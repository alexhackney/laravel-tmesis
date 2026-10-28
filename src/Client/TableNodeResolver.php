<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A NodeResolver over a table the app already owns, driven by `tmesis.nodes_table`.
 *
 * Selected by naming this class in `tmesis.nodes_resolver`. One row per node: the
 * name column is what the node sends as `X-Tmesis-Node`, the secret columns are read
 * as raw bytes (never trimmed, the same rule as config), and the columns listed in
 * `encrypted` are decrypted with the app's encrypter first. A value that will not
 * decrypt is logged by node and column only and treated as missing, so the node's
 * deliveries are refused until the row is fixed rather than verified against
 * ciphertext.
 *
 * The admin URL is an optional template of `{column}` placeholders. A node with none
 * (or with a blank placeholder value) is still a complete node for RECEIVING: the
 * webhook receiver and the hook endpoint read only its secrets. An outgoing admin
 * call to it throws the usual required-at-use InvalidConfigurationException naming
 * the url, the same as a configured node with a blank url.
 *
 * One query per lookup, selecting only the columns needed, filtered by the `where`
 * map and the soft-delete column. The table is never written.
 */
class TableNodeResolver implements NodeResolver
{
    private const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function find(string $name): ?NodeConfig
    {
        $settings = $this->settings();

        $row = $this->query($settings)->where($settings['name'], $name)->first();

        return $row === null ? null : $this->node($settings, (array) $row);
    }

    public function all(): iterable
    {
        $settings = $this->settings();
        $nodes = [];

        foreach ($this->query($settings)->orderBy($settings['name'])->get() as $row) {
            $node = $this->node($settings, (array) $row);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * What `tmesis:doctor` reports: the configured table missing, or a configured
     * column it does not have, each naming the config key to fix. A lookup against
     * such a table throws, which the receiver answers `503 node_lookup_failed` on
     * every delivery without naming the cause; this names it. Empty when sound. A
     * failure to inspect is reported by exception class only (a driver's message can
     * carry a DSN).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        try {
            $settings = $this->settings();
        } catch (InvalidConfigurationException $e) {
            return [$e->getMessage()];
        }

        $columns = ['columns.name' => [$settings['name']], 'columns.webhook_secret' => [$settings['webhook_secret']]];

        foreach (['hook_secret', 'keyterms_hook_secret', 'token'] as $key) {
            if ($settings[$key] !== null) {
                $columns["columns.{$key}"] = [$settings[$key]];
            }
        }

        $columns['url'] = $this->placeholders($settings['url']);
        $columns['where'] = array_keys($settings['where']);
        $columns['soft_delete'] = $settings['soft_delete'] === null ? [] : [$settings['soft_delete']];

        try {
            $connection = $this->db->connection($settings['connection']);
            $schema = $connection->getSchemaBuilder();

            if (! $schema->hasTable($settings['table'])) {
                return ["table [{$settings['table']}] (tmesis.nodes_table.table) does not exist on the [{$connection->getName()}] connection"];
            }

            $present = array_map('strtolower', $schema->getColumnListing($settings['table']));
        } catch (Throwable $e) {
            return ["table [{$settings['table']}] cannot be inspected (".$e::class.')'];
        }

        $problems = [];

        foreach ($columns as $key => $names) {
            foreach ($names as $column) {
                if (! in_array(strtolower($column), $present, true)) {
                    $problems[] = "table [{$settings['table']}] has no column [{$column}], named in tmesis.nodes_table.{$key}";
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array{connection: string|null, table: string, name: string, webhook_secret: string, hook_secret: string|null, keyterms_hook_secret: string|null, token: string|null, url: string|null, where: array<string, mixed>, soft_delete: string|null, encrypted: list<string>}  $settings
     */
    private function query(array $settings): Builder
    {
        $columns = [$settings['name'], $settings['webhook_secret']];

        foreach (['hook_secret', 'keyterms_hook_secret', 'token'] as $key) {
            if ($settings[$key] !== null) {
                $columns[] = $settings[$key];
            }
        }

        $columns = array_values(array_unique([...$columns, ...$this->placeholders($settings['url'])]));

        $query = $this->db->connection($settings['connection'])->table($settings['table'])->select($columns);

        foreach ($settings['where'] as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        if ($settings['soft_delete'] !== null) {
            $query->whereNull($settings['soft_delete']);
        }

        return $query;
    }

    /**
     * @param  array{connection: string|null, table: string, name: string, webhook_secret: string, hook_secret: string|null, keyterms_hook_secret: string|null, token: string|null, url: string|null, where: array<string, mixed>, soft_delete: string|null, encrypted: list<string>}  $settings
     * @param  array<string, mixed>  $row
     */
    private function node(array $settings, array $row): ?NodeConfig
    {
        $name = $row[$settings['name']] ?? null;

        if (! is_scalar($name) || (string) $name === '') {
            return null;
        }

        $name = (string) $name;

        $read = fn (?string $column): ?string => $column === null
            ? null
            : $this->value($name, $column, $row[$column] ?? null, in_array($column, $settings['encrypted'], true));

        return new NodeConfig(
            name: $name,
            url: $this->url($settings['url'], $row),
            token: $read($settings['token']),
            webhookSecret: $read($settings['webhook_secret']),
            hookSecret: $read($settings['hook_secret']),
            keytermsHookSecret: $read($settings['keyterms_hook_secret']),
        );
    }

    private function value(string $node, string $column, mixed $value, bool $encrypted): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        if (! $encrypted) {
            return (string) $value;
        }

        try {
            $plain = $this->container->make(Encrypter::class)->decrypt((string) $value, false);
        } catch (Throwable $e) {
            Log::warning('Tmesis node secret could not be decrypted; treating it as missing.', [
                'node' => $node,
                'column' => $column,
                'exception' => $e::class,
            ]);

            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /**
     * The rendered URL, or `''` (required at use) when there is no template or a
     * placeholder's value is blank.
     *
     * @param  array<string, mixed>  $row
     */
    private function url(?string $template, array $row): string
    {
        if ($template === null) {
            return '';
        }

        $blank = false;

        $url = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($row, &$blank): string {
            $value = $row[$match[1]] ?? null;

            if (! is_scalar($value) || (string) $value === '') {
                $blank = true;

                return '';
            }

            return (string) $value;
        }, $template);

        return $blank || ! is_string($url) ? '' : rtrim($url, '/');
    }

    /**
     * @return list<string>
     */
    private function placeholders(?string $template): array
    {
        if ($template === null || preg_match_all(self::PLACEHOLDER, $template, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * `tmesis.nodes_table`, read at use and checked.
     *
     * @return array{connection: string|null, table: string, name: string, webhook_secret: string, hook_secret: string|null, keyterms_hook_secret: string|null, token: string|null, url: string|null, where: array<string, mixed>, soft_delete: string|null, encrypted: list<string>}
     */
    private function settings(): array
    {
        $get = fn (string $key): mixed => $this->config->get("tmesis.nodes_table.{$key}");

        $optional = function (string $key) use ($get): ?string {
            $value = $get($key);

            if (blank($value)) {
                return null;
            }

            if (! is_string($value)) {
                throw InvalidConfigurationException::invalid("tmesis.nodes_table.{$key}", 'it must be a string or null');
            }

            return $value;
        };

        $required = fn (string $key): string => $optional($key) ?? throw InvalidConfigurationException::missing("tmesis.nodes_table.{$key}");

        $where = $get('where') ?? [];

        if (! is_array($where) || array_filter(array_keys($where), fn ($column): bool => ! is_string($column) || $column === '') !== []) {
            throw InvalidConfigurationException::invalid('tmesis.nodes_table.where', 'it must map column names to values');
        }

        /** @var array<string, mixed> $where */
        $encrypted = $get('encrypted') ?? [];

        if (! is_array($encrypted)) {
            throw InvalidConfigurationException::invalid('tmesis.nodes_table.encrypted', 'it must be a list of column names');
        }

        return [
            'connection' => $optional('connection'),
            'table' => $required('table'),
            'name' => $required('columns.name'),
            'webhook_secret' => $required('columns.webhook_secret'),
            'hook_secret' => $optional('columns.hook_secret'),
            'keyterms_hook_secret' => $optional('columns.keyterms_hook_secret'),
            'token' => $optional('columns.token'),
            'url' => $optional('url'),
            'where' => $where,
            'soft_delete' => $optional('soft_delete'),
            'encrypted' => array_values(array_filter($encrypted, 'is_string')),
        ];
    }
}
