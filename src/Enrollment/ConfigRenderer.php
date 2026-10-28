<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Fleet\JoinToken;
use AlexHackney\Tmesis\Support\ContractSchemas;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use SensitiveParameterValue;

/**
 * Renders a node's config or user-data template: VALUES first, then placeholders.
 *
 * **Values (tmesis `v0.8.0`, handover 3.7).** The role templates interpolate secrets
 * only; every other setting is a plain YAML value. EnrollmentFacts::values() answers
 * facts keyed by CONFIG PATH (`node.name`, `webhooks.url`, `ingest.verify_hook.url`,
 * `delivery.origin.domain`, `relays.0.url`), and each one SETS the value at that key
 * in the template's text (TemplateValues: the text is edited in place, so comments
 * and layout survive; nothing is round-tripped through a YAML dumper). A fact is
 * never silently dropped:
 *
 *  - a path the template does not carry is REFUSED by name
 *    (EnrollmentException::unknownPath()), so a v0.7-style fact against a v0.8
 *    template, or an origin-only key on an edge, fails the render;
 *  - a path that holds a mapping, a block sequence or a block scalar is refused
 *    (notAValue()): a fact sets one value;
 *  - a path whose template value names a reserved `${NAME}` (`webhooks.secret`,
 *    `api.admin_token`) is refused (reservedPath()): secrets reach the box through its
 *    `.env`, never as a fact;
 *  - a value is a string, int, float, bool or a list of those, written as YAML
 *    (strings always double-quoted, lists as a flow sequence), and a string carrying
 *    `${` is refused, because the node would interpolate it.
 *
 * `node.name` is filled with the enrolment's name when the template carries it as a
 * plain value and no fact sets it: the rendered file carries the name the control
 * plane stores for the box.
 *
 * **Placeholders.** `${NAME}` or `${NAME:-default}`, NAME being
 * `[A-Za-z_][A-Za-z0-9_]*`, for an app's own template (`tmesis.enrollment.templates`)
 * that still interpolates non-secrets; the node's interpolation is unchanged, so such a
 * template keeps working. Each one is, in this order:
 *
 *  1. RESERVED (NodeEnrollment::ENV_PLACEHOLDERS and self::SUPPLIED_SECRETS, plus
 *     `TMESIS_BOOTSTRAP_TOKEN` and `TMESIS_FLEET_JOIN_TOKEN`): in RenderMode::Config
 *     LEFT IN PLACE, verbatim, for the node to interpolate from its `.env` at start; in
 *     RenderMode::UserData SUBSTITUTED with the box's secret (the minted four from the
 *     enrolment, the stream key secret from `tmesis.keys.secret`, the tokens given, and
 *     the six SUPPLIED_SECRETS from the optional EnrollmentSecrets seam, falling back
 *     to the template's `:-` default when the seam does not answer one). A fact may
 *     never supply a reserved name;
 *  2. a key of EnrollmentFacts::placeholders(): replaced by that value, verbatim;
 *  3. `TMESIS_ENROLL_REGISTER_URL` / `TMESIS_ENROLL_INSTALL_URL` /
 *     `TMESIS_ENROLL_CONFIG_URL`, supplied by the
 *     package from the route names while the enrolment routes are registered, and
 *     `TMESIS_FLEET_ENROLL_URL` while the fleet routes are (a fact of the same name
 *     overrides them);
 *  4. carrying a `:-default`: replaced by the default;
 *  5. anything else: EnrollmentException::unfilledPlaceholder() naming it. A
 *     document is never handed out with a `${` the node would not fill.
 *
 * **Self-hosted transcription keys (tmesis `v0.9.3`, CONFIG.md 6.8).** A non-empty
 * `batch_api_key` under a `self_hosted` batch role, `fallback_api_key` under a
 * `self_hosted` fallback role, or `api_key` under `provider: self_hosted` fails the
 * node's config load (the sidecar on loopback takes no key). So every
 * `${TMESIS_TRANSCRIPTION_BATCH_API_KEY}` (and the FALLBACK and live
 * `TMESIS_TRANSCRIPTION_API_KEY` ones) under such a role renders EMPTY, in both modes
 * and whatever the seam supplies, which keeps the key out of a user-data document and
 * stops a config document from interpolating the `.env` key in. The batch role is
 * `transcription.batch_provider`, following `transcription.provider` only when absent
 * or empty; the fallback role is `transcription.fallback_provider`. The roles are read
 * (emptiedUnderSelfHosted()) from the text as the node will see it: values set, and
 * every non-secret placeholder resolved, so a role written `${ROLE}` or
 * `${ROLE:-self_hosted}` is judged by its resolved value, and a fact that sets a role
 * counts as much as the template does.
 *
 * Scope: the rule reads the document's own top-level `transcription` paths. A role it
 * cannot read exactly (an opaque span, tag, anchor or block scalar, a double-quoted
 * scalar with an escape) is never guessed: it empties nothing and does not fall through
 * to `provider`. A config embedded in a block scalar (a cloud-init `write_files`
 * `content: |`) is not detected either. In both cases the key is placed as before, and
 * the node refuses the config load on that box if the role is `self_hosted`.
 *
 * A placeholder fact the template does not name is refused too
 * (EnrollmentException::unusedPlaceholder()), for the same reason as an unknown path.
 * A fact's placeholder value goes in verbatim, with no YAML quoting: a template that
 * takes an arbitrary value quotes it itself. A SECRET in a user-data render is
 * written the way the node's own interpolator would write it at that spot
 * (SecretPlacement): escaped inside double quotes, plain or quoted as a whole bare
 * scalar, and refused by name where it cannot be placed exactly (a block scalar's
 * text, part of an unquoted scalar) or would be interpolated again (`${`). A secret
 * holding `#`, `"`, `\` or `: ` reads back as itself, never cut short or reparsed.
 *
 * Templates: since tmesis `v0.6.0` the four `config.<role>.yml` are contract
 * artifacts (`docs/contract/templates/`, verified against their `MD5SUMS` by the
 * provenance leg), bundled at repin, and they are the default directory
 * (self::bundledDirectory()). `tmesis.enrollment.templates` still wins when
 * set: a directory holding `cloud-init.<role>.yml` (tried first, user-data) or
 * `config.<role>.yml`. The contract ships no cloud-init, so user-data mode over a
 * shipped template is the CALLER'S choice (renderRole()'s `$mode`), not the file's.
 *
 * The result is a RenderedDocument, never a string, so a user-data render cannot be
 * logged by accident. Neither the document nor the template is ever logged or put
 * in an exception message.
 */
class ConfigRenderer
{
    public const BOOTSTRAP_TOKEN = 'TMESIS_BOOTSTRAP_TOKEN';

    public const REGISTER_URL = 'TMESIS_ENROLL_REGISTER_URL';

    public const INSTALL_URL = 'TMESIS_ENROLL_INSTALL_URL';

    public const CONFIG_URL = 'TMESIS_ENROLL_CONFIG_URL';

    /**
     * The one-time fleet join token (tmesis v0.7.0, `tmesis:enroll-node --fleet`).
     * RESERVED like the bootstrap token: substituted in a user-data render only, and
     * never in `.env` or `config.yml`. The app's cloud-init runs, in this order (tmesis
     * `v0.9.0`, handover section 6.1; DEPLOY.md 5.6):
     *
     * 1. `install.sh ... --no-start`, which leaves the service stopped AND skips the
     *    installer's live capacity bench (a 15 to 65 minute sweep would outlive the
     *    15-minute bootstrap token);
     * 2. register (`POST {prefix}/enroll/register`) or `tmesis join
     *    "${TMESIS_FLEET_ENROLL_URL}" --token "${TMESIS_FLEET_JOIN_TOKEN}"`, which
     *    refuses while the node runs;
     * 3. the DEPLOY.md 6.5 live bench (`systemd-run ... tmesis bench --profile live
     *    --store --if-live`, as the service) and, on a box that runs VOD jobs,
     *    `tmesis bench --profile job`, while the service is still stopped;
     * 4. `systemctl start tmesis tmesis-update.path`: the node reads the stored
     *    capacity at this first start, so unit admission is on from the first minute.
     */
    public const FLEET_JOIN_TOKEN = 'TMESIS_FLEET_JOIN_TOKEN';

    /** The fleet enroll route's absolute URL, while the fleet routes are registered. */
    public const FLEET_ENROLL_URL = 'TMESIS_FLEET_ENROLL_URL';

    /**
     * The reserved secrets the package does not mint, which a user-data render and the
     * `.env` block take from the optional EnrollmentSecrets seam (tmesis `v0.6.0`,
     * handover 3.3). `TMESIS_SRT_PASSPHRASE` since `v0.8.0` (handover 3.3): the SRT
     * listener's one passphrase per box, which no template names, so it reaches the
     * box through the `.env` block (or an app template that names it) and nowhere
     * else. The package never mints it: the publishers have to be told it, so the
     * app owns it. `TMESIS_TRANSCRIPTION_BATCH_API_KEY` (tmesis `v0.9.1`) and
     * `TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (`v0.9.2`) are the same kind: the batch
     * and fallback transcription providers' keys, never minted. The origin template
     * names both since `v0.9.3` (`${NAME:-}`, empty as shipped), so they reach the box
     * as `.env` lines and, in an origin user-data render, in the document; a
     * `self_hosted` role renders its key empty whatever the seam supplies (the class
     * doc, self-hosted transcription keys).
     *
     * @var list<string>
     */
    public const SUPPLIED_SECRETS = [
        'TMESIS_TRANSCRIPTION_API_KEY',
        'TMESIS_TRANSCRIPTION_BATCH_API_KEY',
        'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY',
        'TMESIS_LIVE_ACCESS_KEY',
        'TMESIS_LIVE_SECRET_KEY',
        'TMESIS_SRT_PASSPHRASE',
    ];

    /** The SRT listener's passphrase: supplied, never minted, 10 to 79 bytes. */
    public const SRT_PASSPHRASE = 'TMESIS_SRT_PASSPHRASE';

    /** The live, batch and fallback keys a `self_hosted` role renders empty (tmesis `v0.9.3`). */
    private const API_KEY = 'TMESIS_TRANSCRIPTION_API_KEY';

    private const BATCH_API_KEY = 'TMESIS_TRANSCRIPTION_BATCH_API_KEY';

    private const FALLBACK_API_KEY = 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY';

    private const SELF_HOSTED = 'self_hosted';

    /** The one path the package fills itself, from the enrolment, unless a fact does. */
    public const NODE_NAME_PATH = 'node.name';

    private const PLACEHOLDER = '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/';

    public function __construct(private readonly Repository $config) {}

    /**
     * The names no fact may supply: the eleven reserved `.env` secrets (five minted,
     * six supplied) and the two tokens.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [...NodeEnrollment::ENV_PLACEHOLDERS, ...self::SUPPLIED_SECRETS, self::BOOTSTRAP_TOKEN, self::FLEET_JOIN_TOKEN];
    }

    /**
     * The contract's own templates as bundled at repin, the default directory.
     */
    public static function bundledDirectory(): string
    {
        return ContractSchemas::root().'/docs/contract/templates';
    }

    /**
     * What the EnrollmentSecrets seam answers for a node, checked and wrapped: only
     * SUPPLIED_SECRETS names, string values, each in a `SensitiveParameterValue`.
     * Empty when there is no seam.
     *
     * @return array<string, SensitiveParameterValue>
     *
     * @throws EnrollmentException (invalidSecret()) naming an entry that is not one
     */
    public function suppliedSecrets(NodeEnrollment $node, ?EnrollmentSecrets $secrets): array
    {
        if ($secrets === null) {
            return [];
        }

        /** @var array<array-key, mixed> $answered an app's answer, checked rather than trusted */
        $answered = $secrets->secrets($node);
        $out = [];

        foreach ($answered as $name => $value) {
            $name = (string) $name;

            if (! in_array($name, self::SUPPLIED_SECRETS, true) || ! is_string($value)) {
                throw EnrollmentException::invalidSecret($name);
            }

            // The node's ingest.PassphraseProblem: SRT takes 10 to 79 bytes, and a box
            // whose listener allows or requires encryption refuses to start on any
            // other length. Empty is "not set", which the node judges by its policy.
            if ($name === self::SRT_PASSPHRASE && $value !== '' && (strlen($value) < 10 || strlen($value) > 79)) {
                throw EnrollmentException::invalidSrtPassphrase();
            }

            $out[$name] = new SensitiveParameterValue($value);
        }

        return $out;
    }

    /**
     * @param  BootstrapToken|null  $token  required in UserData mode when the template
     *                                      names `${TMESIS_BOOTSTRAP_TOKEN}`
     * @param  EnrollmentSecrets|null  $secrets  the optional seam for the
     *                                           SUPPLIED_SECRETS, read in UserData
     *                                           mode only
     * @param  TemplateKind|null  $kind  what the template FILE is (TemplateKind::forPath()):
     *                                   Config sets values() and refuses an unused
     *                                   placeholder only when the role has no app
     *                                   template (hasAppTemplate()); App fills
     *                                   placeholders() and does not apply values();
     *                                   null does both, for text with no file
     *
     * @throws EnrollmentException when a placeholder is unfilled, a fact is not a
     *                             string or is reserved, a supplied secret is not
     *                             one, or a user-data template needs a token that
     *                             was not given
     * @throws InvalidConfigurationException when a user-data template names the
     *                                       stream key secret and it is blank
     */
    public function render(string $template, NodeEnrollment $node, EnrollmentFacts $facts, RenderMode $mode, ?BootstrapToken $token = null, ?EnrollmentSecrets $secrets = null, ?JoinToken $joinToken = null, ?TemplateKind $kind = null): RenderedDocument
    {
        if ($kind !== TemplateKind::App) {
            $template = $this->setValues($template, $node, $facts);
        }

        $placeholders = $this->facts($node, $facts);

        // A config document leaves a placeholder fact to the app's own template, but
        // only when the role HAS one: a fact no document uses (a v0.7-shaped facts
        // class against the bundled config alone) is still refused.
        if ($kind !== TemplateKind::Config || ! $this->hasAppTemplate($node->role)) {
            $this->refuseUnused($template, $placeholders);
        }

        $values = array_merge($this->routeFacts(), $placeholders);
        $reserved = self::reserved();
        $supplied = $mode === RenderMode::UserData ? $this->suppliedSecrets($node, $secrets) : [];

        $contexts = $mode === RenderMode::UserData ? SecretPlacement::scan($template) : [];
        $emptied = $this->emptiedUnderSelfHosted($template, $values);

        $text = (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($values, $reserved, $mode, $node, $token, $supplied, $joinToken, $contexts, $emptied): string {
            [$whole, $offset] = $match[0];
            $name = $match[1][0];
            $default = isset($match[2]) && $match[2][1] !== -1 ? $match[2][0] : null;

            if (in_array($name, $emptied, true)) {
                return '';
            }

            if (in_array($name, $reserved, true)) {
                if ($mode === RenderMode::Config) {
                    return $whole;
                }

                if (in_array($name, self::SUPPLIED_SECRETS, true) && ! isset($supplied[$name])) {
                    return $default ?? throw EnrollmentException::unfilledPlaceholder($name);
                }

                $secret = in_array($name, self::SUPPLIED_SECRETS, true)
                    ? self::unwrap($supplied[$name])
                    : $this->secret($name, $node, $token, $joinToken);

                return SecretPlacement::place($contexts[$offset] ?? null, $name, $secret);
            }

            if (array_key_exists($name, $values)) {
                return $values[$name];
            }

            if ($default !== null) {
                return $default;
            }

            throw EnrollmentException::unfilledPlaceholder($name);
        }, $template, -1, $count, PREG_OFFSET_CAPTURE);

        return new RenderedDocument($text, $mode);
    }

    /**
     * Whether the configured directory holds an app template (TemplateKind::App) for
     * the role: the document a config document's unused placeholder facts are for.
     */
    public function hasAppTemplate(NodeRole|string $role): bool
    {
        $path = $this->templatePath($role);

        return $path !== null && TemplateKind::forPath($path) === TemplateKind::App;
    }

    /**
     * Render the configured template for a role, in `$mode` when given, else the mode
     * its file name says (RenderMode::forPath()): `cloud-init.<role>.yml` is
     * user-data, anything else config. The shipped templates are all
     * `config.<role>.yml`, so a user-data render of one is asked for with `$mode`.
     *
     * @throws EnrollmentException when no template is configured or found for the
     *                             role, or render() refuses
     * @throws InvalidConfigurationException when render() needs a blank stream key
     *                                       secret
     */
    public function renderRole(NodeRole|string $role, NodeEnrollment $node, EnrollmentFacts $facts, ?BootstrapToken $token = null, ?RenderMode $mode = null, ?EnrollmentSecrets $secrets = null, ?JoinToken $joinToken = null): RenderedDocument
    {
        $role = $this->role($role);
        $path = $this->templatePath($role);

        if ($path === null) {
            throw EnrollmentException::noTemplate($role->value, $this->directory());
        }

        return $this->render(self::read($path), $node, $facts, $mode ?? RenderMode::forPath($path), $token, $secrets, $joinToken, TemplateKind::forPath($path));
    }

    /**
     * Whether the role's configured template names `${$name}` (with or without a
     * default). False when there is no template. Since tmesis `v0.8.0` the bundled
     * templates name secrets only; a non-secret is a value, asked with
     * templateCarries().
     */
    public function templateUses(NodeRole|string $role, string $name): bool
    {
        $path = $this->templatePath($role);

        return $path !== null && preg_match('/\$\{'.preg_quote($name, '/').'(?::-[^}]*)?\}/', self::read($path)) === 1;
    }

    /**
     * Whether the role's configured template carries a value a fact may set at
     * `$path` (TemplateValues::settable()). False when there is no template.
     */
    public function templateCarries(NodeRole|string $role, string $path): bool
    {
        $file = $this->templatePath($role);

        return $file !== null && TemplateValues::parse(self::read($file))->settable($path);
    }

    /**
     * The configured template for a role, `cloud-init.<role>.yml` then
     * `config.<role>.yml`, or null when there is no directory or neither file.
     *
     * @throws EnrollmentException when the role is not one
     */
    public function templatePath(NodeRole|string $role): ?string
    {
        $role = $this->role($role);
        $directory = $this->directory();

        if ($directory === null) {
            return null;
        }

        foreach (["cloud-init.{$role->value}.yml", "config.{$role->value}.yml"] as $file) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * `tmesis.enrollment.templates` when set, else the bundled contract templates
     * (self::bundledDirectory()); null when that is not a readable directory. A set
     * value that is wrong is null, never a silent fall back to the bundle: the app
     * asked for its own templates.
     */
    public function directory(): ?string
    {
        $directory = $this->config->get('tmesis.enrollment.templates');

        if (! is_string($directory) || blank($directory)) {
            $directory = self::bundledDirectory();
        }

        if (! is_dir($directory) || ! is_readable($directory)) {
            return null;
        }

        return rtrim($directory, '/\\');
    }

    /**
     * @throws EnrollmentException when the file cannot be read
     */
    public static function read(string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw EnrollmentException::unreadableTemplate($path);
        }

        return $contents;
    }

    /**
     * The template with every value fact set at its path, and `node.name` filled from
     * the enrolment when the template carries it as a plain value and no fact sets it.
     *
     * @throws EnrollmentException naming the first fact refused
     */
    private function setValues(string $template, NodeEnrollment $node, EnrollmentFacts $facts): string
    {
        $carried = TemplateValues::parse($template);
        $values = $this->values($node, $facts);

        if (! array_key_exists(self::NODE_NAME_PATH, $values)
            && $carried->settable(self::NODE_NAME_PATH)
            && ! str_contains((string) $carried->raw(self::NODE_NAME_PATH), '${')) {
            $values[self::NODE_NAME_PATH] = $node->name;
        }

        if ($values === []) {
            return $template;
        }

        $yaml = [];

        foreach ($values as $path => $value) {
            $opaque = $carried->opaqueAncestor($path);

            if ($opaque !== null) {
                throw EnrollmentException::unreadableValue($path, $opaque);
            }

            if (! $carried->has($path)) {
                throw EnrollmentException::unknownPath($path, $node->role->value);
            }

            if (! $carried->settable($path)) {
                throw EnrollmentException::notAValue($path, (string) $carried->kind($path));
            }

            if (preg_match('/\$\{(?:'.implode('|', self::reserved()).')(?::-[^}]*)?\}/', (string) $carried->raw($path)) === 1) {
                throw EnrollmentException::reservedPath($path);
            }

            $yaml[$path] = self::yaml($value);
        }

        return $carried->replace($yaml) ?? throw EnrollmentException::overlappingPaths(array_keys($yaml));
    }

    /**
     * What EnrollmentFacts::values() answers, checked rather than trusted.
     *
     * @return array<string, string|int|float|bool|list<string|int|float|bool>>
     *
     * @throws EnrollmentException naming the first path or value refused
     */
    private function values(NodeEnrollment $node, EnrollmentFacts $facts): array
    {
        $out = [];

        /** @var array<array-key, mixed> $supplied an app's answer, checked rather than trusted */
        $supplied = $facts->values($node);

        foreach ($supplied as $path => $value) {
            $path = (string) $path;

            if (! TemplateValues::isPath($path)) {
                throw EnrollmentException::invalidPath($path);
            }

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    throw EnrollmentException::invalidValue($path);
                }

                foreach ($value as $item) {
                    if (! self::isScalarValue($item)) {
                        throw EnrollmentException::invalidValue($path);
                    }
                }

                /** @var list<string|int|float|bool> $value */
                $out[$path] = $value;

                continue;
            }

            if (! self::isScalarValue($value)) {
                throw EnrollmentException::invalidValue($path);
            }

            /** @var string|int|float|bool $value */
            $out[$path] = $value;
        }

        return $out;
    }

    private static function isScalarValue(mixed $value): bool
    {
        return match (true) {
            is_string($value) => mb_check_encoding($value, 'UTF-8') && ! str_contains($value, '${'),
            is_float($value) => is_finite($value),
            default => is_int($value) || is_bool($value),
        };
    }

    /**
     * A checked value as YAML text: a string double-quoted (self::quote()), a number
     * or bool bare, a list as a flow sequence of those.
     *
     * @param  string|int|float|bool|list<string|int|float|bool>  $value
     */
    private static function yaml(string|int|float|bool|array $value): string
    {
        return match (true) {
            is_array($value) => '['.implode(', ', array_map(self::yaml(...), $value)).']',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => self::quote($value),
            default => (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        };
    }

    /**
     * A YAML double-quoted scalar the NODE reads back exactly (gopkg.in/yaml.v3,
     * probed): `"` and `\` escaped; every character YAML does not call printable
     * (C0 controls, DEL, the C1 block, U+FFFE, U+FFFF) escaped, because yaml.v3
     * refuses them raw; and every line break YAML folds inside quotes (LF, CR, NEL,
     * U+2028, U+2029) escaped, because a raw NEL there reads back as a space. Every
     * other character, printable Unicode included, is written as it is.
     *
     * @internal
     */
    public static function quote(string $value): string
    {
        return '"'.self::escape($value).'"';
    }

    /**
     * The inside of self::quote(): what a value becomes between double quotes. Also
     * SecretPlacement's, for a secret placed inside a template's own double quotes.
     *
     * @internal
     */
    public static function escape(string $value): string
    {
        return (string) preg_replace_callback(
            '/["\\\\\x{00}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}\x{FFFE}\x{FFFF}]/u',
            static fn (array $match): string => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04X', mb_ord($match[0], 'UTF-8')),
            },
            $value,
        );
    }

    /**
     * A placeholder fact the (value-set) template does not name would be dropped:
     * refused instead, naming it.
     *
     * @param  array<string, string>  $placeholders
     *
     * @throws EnrollmentException
     */
    private function refuseUnused(string $template, array $placeholders): void
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        foreach (array_keys($placeholders) as $name) {
            if (! in_array($name, $matches[1], true)) {
                throw EnrollmentException::unusedPlaceholder($name);
            }
        }
    }

    /**
     * The key placeholders that render EMPTY because their role is `self_hosted`
     * (tmesis `v0.9.3`, CONFIG.md 6.8): the batch key under a `self_hosted` batch role,
     * the fallback key under a `self_hosted` fallback role, and the live key
     * (`TMESIS_TRANSCRIPTION_API_KEY`) under `provider: self_hosted`.
     *
     * The roles are read from the text as the node will see it: every non-secret
     * placeholder resolved the way the render resolves it (placeholder and route facts,
     * then the `:-` default), a reserved name left as it is (a role is never a secret).
     * The batch role is `transcription.batch_provider`, and only an ABSENT or EMPTY one
     * follows `transcription.provider`. A role that is present but not read exactly
     * (carriedRole() answers false) is never guessed: it empties nothing and does not
     * fall through to the provider.
     *
     * @param  array<string, string>  $values  the placeholder and route facts
     * @return list<string>
     */
    private function emptiedUnderSelfHosted(string $template, array $values): array
    {
        $reserved = self::reserved();

        $resolved = (string) preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values, $reserved): string {
            $name = $match[1];

            if (in_array($name, $reserved, true)) {
                return $match[0];
            }

            return $values[$name] ?? $match[2] ?? $match[0];
        }, $template);

        $carried = TemplateValues::parse($resolved);
        $provider = self::carriedRole($carried, 'transcription.provider');
        $batch = self::carriedRole($carried, 'transcription.batch_provider');
        $fallback = self::carriedRole($carried, 'transcription.fallback_provider');
        $emptied = [];

        if ($provider === self::SELF_HOSTED) {
            $emptied[] = self::API_KEY;
        }

        if ($batch !== false && ($batch === null || $batch === '' ? $provider : $batch) === self::SELF_HOSTED) {
            $emptied[] = self::BATCH_API_KEY;
        }

        if ($fallback === self::SELF_HOSTED) {
            $emptied[] = self::FALLBACK_API_KEY;
        }

        return $emptied;
    }

    /**
     * A role's value as the node reads it: null when the document does not carry the
     * path, the decoded string when it does ('' for an empty scalar (a bare key, a
     * comment-only value or a placeholder resolved to empty), `""`, `''`, `~` or `null`),
     * false when it is there but cannot be read exactly (an opaque span, a block
     * scalar, a mapping with children, a sequence, a duplicated key, a double-quoted scalar
     * holding an escape, or text still holding a `${`). Only a plain scalar, a
     * single-quoted scalar (its `''` read as `'`) and an escape-free double-quoted one
     * are decoded; the rest is never guessed.
     */
    private static function carriedRole(TemplateValues $carried, string $path): string|false|null
    {
        if (! $carried->has($path)) {
            return $carried->opaqueAncestor($path) === null ? null : false;
        }

        if ($carried->opaqueAncestor($path) !== null) {
            return false;
        }

        if ($carried->kind($path) === TemplateValues::COLLECTION) {
            // A bare key, a comment-only value and a placeholder resolved to empty are all
            // recorded as a collection; only a key with children is one. The node reads
            // an empty one as "" (YAML null).
            foreach ($carried->paths() as $other) {
                if (str_starts_with($other, $path.'.')) {
                    return false;
                }
            }

            return '';
        }

        if ($carried->kind($path) !== TemplateValues::SCALAR) {
            return false;
        }

        $raw = trim((string) $carried->raw($path));

        if (str_contains($raw, '${')) {
            return false;
        }

        if (str_starts_with($raw, '"')) {
            return str_contains($raw, '\\') ? false : substr($raw, 1, -1);
        }

        if (str_starts_with($raw, "'")) {
            return str_replace("''", "'", substr($raw, 1, -1));
        }

        return in_array($raw, ['~', 'null', 'Null', 'NULL'], true) ? '' : $raw;
    }

    /**
     * @return array<string, string>
     */
    private function facts(NodeEnrollment $node, EnrollmentFacts $facts): array
    {
        $values = [];

        /** @var array<array-key, mixed> $supplied an app's answer, checked rather than trusted */
        $supplied = $facts->placeholders($node);

        foreach ($supplied as $name => $value) {
            $name = (string) $name;

            if (in_array($name, self::reserved(), true)) {
                throw EnrollmentException::reservedPlaceholder($name);
            }

            if (! is_string($value)) {
                throw EnrollmentException::invalidFact($name);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * The URLs a template can carry its own register, install and config calls with, while
     * the routes are registered.
     *
     * @return array<string, string>
     */
    private function routeFacts(): array
    {
        $values = [];

        if (Route::has('tmesis.enroll.register')) {
            $values[self::REGISTER_URL] = route('tmesis.enroll.register');
        }

        if (Route::has('tmesis.enroll.install')) {
            $values[self::INSTALL_URL] = route('tmesis.enroll.install');
        }

        if (Route::has('tmesis.enroll.config')) {
            $values[self::CONFIG_URL] = route('tmesis.enroll.config');
        }

        if (Route::has('tmesis.fleet.enroll')) {
            $values[self::FLEET_ENROLL_URL] = route('tmesis.fleet.enroll');
        }

        return $values;
    }

    /**
     * A reserved name's value, for a user-data render only.
     */
    private function secret(string $name, NodeEnrollment $node, ?BootstrapToken $token, ?JoinToken $joinToken = null): string
    {
        return match ($name) {
            self::FLEET_JOIN_TOKEN => $joinToken?->reveal() ?? throw EnrollmentException::joinTokenRequired(),
            'TMESIS_ADMIN_TOKEN' => $node->adminToken(),
            'TMESIS_WEBHOOK_SECRET' => $node->webhookSecret(),
            'TMESIS_HOOK_SECRET' => $node->hookSecret(),
            'TMESIS_KEYTERMS_HOOK_SECRET' => $node->keytermsHookSecret(),
            'TMESIS_STREAM_KEY_SECRET' => $this->streamKeySecret(),
            default => $token?->plain() ?? throw EnrollmentException::bootstrapTokenRequired(),
        };
    }

    private static function unwrap(SensitiveParameterValue $value): string
    {
        $revealed = $value->getValue();

        return is_string($revealed) ? $revealed : '';
    }

    private function streamKeySecret(): string
    {
        $secret = $this->config->get('tmesis.keys.secret');

        if (! is_string($secret) || blank($secret)) {
            throw InvalidConfigurationException::missing('tmesis.keys.secret');
        }

        return $secret;
    }

    private function role(NodeRole|string $role): NodeRole
    {
        if ($role instanceof NodeRole) {
            return $role;
        }

        return NodeRole::tryFrom($role) ?? throw EnrollmentException::invalidRole($role);
    }
}
