<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TableNodeResolver;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Enrollment\ConfigRenderer;
use AlexHackney\Tmesis\Enrollment\EnrollmentSeams;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Hooks\KeytermsProvider;
use AlexHackney\Tmesis\Hooks\NoKeyterms;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;
use AlexHackney\Tmesis\Support\Config;
use AlexHackney\Tmesis\Support\ContractSchemas;
use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use AlexHackney\Tmesis\Webhooks\TableDeliveryDeduper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Checks this side of the integration: config, secrets, the route, the dedupe store,
 * and the bundled contract fixtures.
 *
 * Every check prints pass, warn, or fail. A whitespace problem is reported by NAMING
 * THE KEY and never the value: these are secrets, and a trailing newline from a .env
 * file is the classic way to get a signature that silently never matches.
 */
class DoctorCommand extends Command
{
    protected $signature = 'tmesis:doctor {--live : Also call getHealth() on every configured and resolved node}';

    protected $description = 'Check the Tmesis integration: config, secrets, route, dedupe store, fixtures';

    private bool $failed = false;

    public function handle(NodeRegistry $registry, TmesisClientInterface $client): int
    {
        $this->checkConfig($registry);
        $this->checkNodes($registry);
        $this->checkKeys($registry);
        $this->checkRoute();
        $this->checkHookRoute();
        $this->checkKeytermsRoute();
        $this->checkEnrollment();
        $this->checkFleet();
        $this->checkDeduper();
        $this->checkDeliveryRetention();
        $this->checkFixtures();
        $this->checkContractValidation();

        if ($this->option('live')) {
            $this->checkLive($registry, $client);
        }

        return $this->failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function checkConfig(NodeRegistry $registry): void
    {
        if (! is_array(config('tmesis'))) {
            $this->reportFail('config', 'tmesis config is not loaded. Publish it with --tag=tmesis-config.');

            return;
        }

        $this->reportPass('config', 'loaded');

        try {
            $default = $registry->defaultName();
        } catch (TmesisException $e) {
            $this->reportFail('default node', $e->getMessage());

            return;
        }

        try {
            if (! $registry->has($default)) {
                $this->reportFail('default node', "tmesis.default is [{$default}] but tmesis.nodes.{$default} does not exist and the node resolver does not know it");
            } elseif ($this->defaultIsInertStub($registry, $default)) {
                $this->reportWarn('default node', "the default node [{$default}] is the shipped stub with no credentials and is not in the node list; point TMESIS_DEFAULT_NODE at a resolver node");
            } else {
                $this->reportPass('default node', "[{$default}] is configured");
            }
        } catch (TmesisException $e) {
            $this->reportFail('default node', $e->getMessage());
        }

        try {
            $this->reportPass('retention', Config::retentionHours().'h');
        } catch (TmesisException $e) {
            $this->reportFail('retention', $e->getMessage());
        }

        $this->checkVerify();
    }

    /**
     * With a resolver, a configured default entry carrying no token and no secrets is
     * the shipped `nodes.default` stub: has() still answers it, but names() leaves it
     * out, so nothing the doctor or the app walks would ever reach it.
     */
    private function defaultIsInertStub(NodeRegistry $registry, string $default): bool
    {
        if (! is_array(config("tmesis.nodes.{$default}")) || $registry->resolver() === null) {
            return false;
        }

        try {
            $node = $registry->get($default);
        } catch (TmesisException) {
            return false;
        }

        return blank($node->token) && blank($node->webhookSecret) && blank($node->hookSecret) && blank($node->keytermsHookSecret);
    }

    /**
     * Node enrolment: the routes, the app's repository and facts, and the templates.
     *
     * No row at all when the routes are off and no repository is configured: an app
     * that does not enrol nodes through this package has nothing to check here.
     */
    private function checkEnrollment(): void
    {
        $enabled = (bool) config('tmesis.enrollment.enabled', false);
        $configured = EnrollmentSeams::repositoryConfigured($this->laravel);

        if (! $enabled && ! $configured) {
            return;
        }

        if (! $configured) {
            $this->reportFail('enrollment', 'routes are on and no NodeRepository is bound or named in tmesis.enrollment.repository');
        } else {
            try {
                EnrollmentSeams::repository($this->laravel);

                $this->reportPass('enrollment', $enabled
                    ? 'routes on: POST '.$this->routeUrl('tmesis.enroll.register').', GET '.$this->routeUrl('tmesis.enroll.install').', GET '.$this->routeUrl('tmesis.enroll.config')
                    : 'repository configured, routes off (enrollment.enabled is false)');
            } catch (Throwable $e) {
                $this->reportFail('enrollment', $e->getMessage());
            }
        }

        $this->checkEnrollmentTemplates();
    }

    /**
     * The fleet control plane (tmesis v0.7.0): the routes, the provider, the dedupe
     * store's durability and retention (an id stays known 14 days while the fleet is
     * on, tmesis D13), and stale nodes. Silent when the fleet is off.
     */
    private function checkFleet(): void
    {
        if (! FleetConfig::enabled()) {
            return;
        }

        try {
            FleetConfig::assertUsable($this->laravel);
            FleetConfig::provider($this->laravel);
        } catch (Throwable $e) {
            $this->reportFail('fleet', $e->getMessage());

            return;
        }

        $this->reportPass('fleet', 'routes on: POST '.$this->routeUrl('tmesis.fleet.enroll').', POST '.$this->routeUrl('tmesis.fleet.heartbeat'));
        $this->reportPass('fleet dedupe', Config::dedupeDriver().' driver, ids kept '.FleetConfig::dedupeRetentionHours().'h whichever channel carried them');

        try {
            $nodes = $this->laravel->make(FleetStore::class)->nodes();
        } catch (Throwable $e) {
            $this->reportFail('fleet nodes', 'the fleet tables cannot be read ('.$e::class.'): run the tmesis-migrations');

            return;
        }

        $stale = array_values(array_filter($nodes, fn ($n): bool => $n->isStale()));

        if ($stale === []) {
            $this->reportPass('fleet nodes', count($nodes).' enrolled, none stale');
        } else {
            $this->reportWarn('fleet nodes', count($nodes).' enrolled, stale: '.implode(', ', array_map(fn ($n): string => $n->name, $stale)));
        }
    }

    private function checkEnrollmentTemplates(): void
    {
        $setting = config('tmesis.enrollment.templates');
        $renderer = $this->laravel->make(ConfigRenderer::class);

        if ($renderer->directory() === null) {
            if (blank($setting)) {
                $this->reportWarn('enrollment templates', 'the bundled contract templates are missing from ['.ConfigRenderer::bundledDirectory().']; reinstall the package');

                return;
            }

            $setting = is_string($setting) ? $setting : 'not a string';

            $this->reportWarn('enrollment templates', "[tmesis.enrollment.templates] is [{$setting}], which is missing or not a readable directory");

            return;
        }

        if (blank($setting)) {
            $this->reportPass('enrollment templates', 'the contract\'s config.<role>.yml, bundled at the pin (set tmesis.enrollment.templates to bring your own)');
        }

        foreach (NodeRole::cases() as $role) {
            if ($renderer->templatePath($role) === null) {
                $this->reportWarn(
                    "enrollment template {$role->value}",
                    "neither cloud-init.{$role->value}.yml nor config.{$role->value}.yml is in the templates directory"
                );
            }
        }

        if (! EnrollmentSeams::factsConfigured($this->laravel)) {
            $this->reportWarn('enrollment facts', 'templates need an EnrollmentFacts; bind one or name it in tmesis.enrollment.facts (until then the command prints the .env block only and install cannot render)');
        }
    }

    private function routeUrl(string $name): string
    {
        try {
            return route($name);
        } catch (Throwable) {
            return "[{$name} is not registered]";
        }
    }

    /**
     * `tmesis.http.verify`, through the same resolution every admin call uses, so a
     * value the transport would refuse fails here first.
     */
    private function checkVerify(): void
    {
        try {
            $verify = Transport::resolveVerify(config('tmesis.http.verify', true), (string) $this->laravel->environment());
        } catch (TmesisException $e) {
            $this->reportFail('http.verify', $e->getMessage());

            return;
        }

        match (true) {
            $verify === true => $this->reportPass('http.verify', 'on, the system CA store'),
            $verify === false => $this->reportWarn('http.verify', 'off: allowed in local and testing only, refused anywhere else'),
            default => $this->reportPass('http.verify', "on, the CA bundle [{$verify}]"),
        };
    }

    private function checkNodes(NodeRegistry $registry): void
    {
        try {
            $resolver = $registry->resolver();
        } catch (TmesisException $e) {
            $this->reportFail('node resolver', $e->getMessage());

            return;
        }

        // A missing column makes every lookup throw, which the receiver answers as a
        // 503 node_lookup_failed naming nothing: name it here, before the lookups.
        if ($resolver instanceof TableNodeResolver) {
            $problems = $resolver->problems();

            foreach ($problems as $problem) {
                $this->reportFail('node table', $problem);
            }

            if ($problems !== []) {
                return;
            }
        }

        try {
            $names = $registry->names();
        } catch (TmesisException $e) {
            $this->reportFail('node resolver', $e->getMessage());

            return;
        }

        if ($resolver !== null) {
            $this->reportPass('node resolver', $resolver::class);
        }

        if ($names === []) {
            $this->reportFail('nodes', 'no nodes are configured under tmesis.nodes or supplied by the node resolver');

            return;
        }

        $configured = config('tmesis.nodes');
        $configured = is_array($configured) ? $configured : [];

        foreach ($names as $name) {
            try {
                $node = $registry->get($name);
            } catch (TmesisException $e) {
                $this->reportFail("nodes.{$name}", $e->getMessage());

                continue;
            }

            // The key a problem is reported under says where to fix it: the config
            // path for a configured node, the resolver for one it supplied.
            $resolved = ! array_key_exists($name, $configured);
            $key = $resolved ? "tmesis.nodes_resolver[{$name}]" : "tmesis.nodes.{$name}";

            // A resolved node with no url or token is a node the app only RECEIVES
            // from (it heartbeats in and is never called); that is a warning, and
            // an admin call to it throws naming the key.
            if ($resolved && blank($node->url) && blank($node->token)) {
                $this->reportWarn("nodes.{$name}.url", "[{$key}.url] and [{$key}.token] are not set: receive only, admin calls to this node will throw");
            } else {
                $this->requireValue("nodes.{$name}.url", "{$key}.url", $node->url, secret: false);
                $this->requireValue("nodes.{$name}.token", "{$key}.token", $node->token);
            }

            $this->requireValue("nodes.{$name}.webhook_secret", "{$key}.webhook_secret", $node->webhookSecret);

            if (! blank($node->hookSecret)) {
                $this->requireValue("nodes.{$name}.hook_secret", "{$key}.hook_secret", $node->hookSecret);
            }

            if (! blank($node->keytermsHookSecret)) {
                $this->requireValue("nodes.{$name}.keyterms_hook_secret", "{$key}.keyterms_hook_secret", $node->keytermsHookSecret);
            }
        }
    }

    private function checkKeys(NodeRegistry $registry): void
    {
        $this->requireValue('keys.secret', 'tmesis.keys.secret', config('tmesis.keys.secret'));

        $previous = config('tmesis.keys.previous_secrets');

        if (! is_array($previous)) {
            return;
        }

        foreach (array_values($previous) as $index => $secret) {
            $this->requireValue(
                "keys.previous_secrets.{$index}",
                "tmesis.keys.previous_secrets.{$index}",
                is_string($secret) ? $secret : null,
            );
        }
    }

    private function checkRoute(): void
    {
        $enabled = (bool) config('tmesis.webhooks.enabled', false);
        $registered = Route::has('tmesis.webhooks.receive');

        if ($enabled && ! $registered) {
            $this->reportFail('webhook route', 'webhooks.enabled is true but the route is not registered');

            return;
        }

        if (! $enabled && $registered) {
            $this->reportWarn('webhook route', 'the route is registered but webhooks.enabled is false');

            return;
        }

        $enabled
            ? $this->reportPass('webhook route', 'POST '.config('tmesis.webhooks.prefix').'/tmesis')
            : $this->reportPass('webhook route', 'disabled (webhooks.enabled is false)');
    }

    /**
     * The publish veto: its route, its decider, and whether the decider can be
     * resolved at all.
     *
     * A decider class that does not resolve answers the on_error posture for every
     * publish on the node, which is a thing to learn here rather than during a show.
     */
    private function checkHookRoute(): void
    {
        $enabled = (bool) config('tmesis.hooks.enabled', false);
        $registered = Route::has('tmesis.hooks.publish');

        if ($enabled && ! $registered) {
            $this->reportFail('hook route', 'hooks.enabled is true but the route is not registered');

            return;
        }

        if (! $enabled) {
            $registered
                ? $this->reportWarn('hook route', 'the route is registered but hooks.enabled is false')
                : $this->reportPass('hook route', 'disabled (hooks.enabled is false)');

            return;
        }

        $this->reportPass('hook route', 'POST '.config('tmesis.webhooks.prefix').'/hooks/publish');

        $decider = config('tmesis.hooks.decider');

        if (! is_string($decider) || $decider === '') {
            $this->reportFail('hook decider', 'tmesis.hooks.decider is not set');

            return;
        }

        try {
            $resolved = app($decider);
        } catch (Throwable $e) {
            $this->reportFail('hook decider', "[{$decider}] cannot be resolved: ".$e->getMessage());

            return;
        }

        $resolved instanceof PublishHookDecider
            ? $this->reportPass('hook decider', $decider)
            : $this->reportFail('hook decider', "[{$decider}] does not implement ".PublishHookDecider::class);

        $posture = (bool) config('tmesis.hooks.on_error', false);

        $this->reportPass('hook on_error', $posture ? 'allow' : 'deny');
    }

    /**
     * The keyterms hook (tmesis v0.8.0): its route and its provider. The secret is
     * checked per node in checkNodes().
     */
    private function checkKeytermsRoute(): void
    {
        $enabled = filter_var(config('tmesis.hooks.keyterms.enabled', false), FILTER_VALIDATE_BOOLEAN);
        $registered = Route::has('tmesis.hooks.keyterms');

        // The route is always registered (a 404 makes the node clear every session's
        // list). Missing means something dropped it: a route cache from before 0.8.3.
        if (! $registered) {
            $enabled
                ? $this->reportFail('keyterms route', 'hooks.keyterms.enabled is true but the route is not registered (a stale route cache?)')
                : $this->reportWarn('keyterms route', 'the route is not registered (a stale route cache?): a node pointed at it gets a 404, which clears every session\'s keyterms');

            return;
        }

        if (! $enabled) {
            $this->reportPass('keyterms route', 'disabled (hooks.keyterms.enabled is false): GET '.$this->routeUrl('tmesis.hooks.keyterms').' answers a signed request 503 keyterms_disabled, so a node pointed at it keeps its list');

            return;
        }

        $this->reportPass('keyterms route', 'GET '.$this->routeUrl('tmesis.hooks.keyterms'));

        $provider = config('tmesis.hooks.keyterms.provider');

        if (! is_string($provider) || $provider === '') {
            $this->reportFail('keyterms provider', 'tmesis.hooks.keyterms.provider is not set');

            return;
        }

        try {
            $resolved = app($provider);
        } catch (Throwable $e) {
            $this->reportFail('keyterms provider', "[{$provider}] cannot be resolved (".$e::class.')');

            return;
        }

        if (! $resolved instanceof KeytermsProvider) {
            $this->reportFail('keyterms provider', "[{$provider}] does not implement ".KeytermsProvider::class);

            return;
        }

        // Any answer, none included, replaces a stream's own captions.keyterms for the
        // session (tmesis decision D14), so the default provider clears them. The route
        // on with no provider bound is never wanted (an app with no keyterms leaves it
        // off), so it fails rather than warns.
        $resolved instanceof NoKeyterms
            ? $this->reportFail('keyterms provider', NoKeyterms::class.' answers none, which replaces every stream\'s own captions.keyterms for the session; bind your own provider, or leave hooks.keyterms.enabled off')
            : $this->reportPass('keyterms provider', $provider);
    }

    /**
     * Runtime schema validation, and whether the validator it needs is installed.
     */
    private function checkContractValidation(): void
    {
        if (! config('tmesis.contract.validate_incoming', false)) {
            $this->reportPass('contract validation', 'off (contract.validate_incoming is false)');

            return;
        }

        ContractSchemas::validatorInstalled()
            ? $this->reportPass('contract validation', 'on, opis/json-schema is installed')
            : $this->reportFail(
                'contract validation',
                'contract.validate_incoming is on but opis/json-schema is not installed. '
                .'Run composer require --dev opis/json-schema, or turn the switch off'
            );
    }

    private function checkDeduper(): void
    {
        // Resolved here rather than injected, so a store that refuses its config
        // (a blank table column, a retention under 48) is a failed check, not a crash.
        try {
            $deduper = $this->resolveDeduper();
        } catch (TmesisException $e) {
            $this->reportFail('dedupe store', $e->getMessage());

            return;
        }

        if ($deduper instanceof TableDeliveryDeduper) {
            $this->checkTableDeduper($deduper);

            return;
        }

        $probe = 'doctor-probe-'.bin2hex(random_bytes(8));

        $delivery = new ReceivedDelivery(
            node: 'doctor',
            event: 'doctor.probe',
            deliveryId: $probe,
            timestamp: (string) now()->getTimestamp(),
            attempt: 1,
            signature: '',
            rawBody: '{}',
            headers: [],
            receivedAt: new \DateTimeImmutable,
        );

        try {
            $claimed = $deduper->claim($probe, $delivery, []);
            $deduper->forget($probe);
        } catch (Throwable $e) {
            $this->reportFail(
                'dedupe store',
                $deduper->isDurable()
                    ? 'the store is unusable ('.$e->getMessage().'). Publish and run the migration: --tag=tmesis-migrations'
                    : 'the store is unusable: '.$e->getMessage()
            );

            return;
        }

        $claimed
            ? $this->reportPass('dedupe store', $deduper->describe())
            : $this->reportFail('dedupe store', 'a fresh probe id was reported as a duplicate: '.$deduper->describe());

        $this->checkDurability($deduper, 'Use the database driver outside tests.');
        $this->checkUnprocessed($deduper);
    }

    /**
     * The database driver keeps every processed delivery, body included, until
     * `tmesis:webhooks:prune` deletes it past the retention window; nothing else ever
     * does. Rows past the window mean the prune is not scheduled. Silent for the other
     * drivers (the cache expires its own ids, the table is the app's), and when the
     * store cannot be read, which checkDeduper() already reports.
     */
    private function checkDeliveryRetention(): void
    {
        try {
            $deduper = $this->resolveDeduper();

            if (! $deduper instanceof DatabaseDeliveryDeduper) {
                return;
            }

            $count = $deduper->prunableCount();
            $oldest = $deduper->oldestPrunable();
        } catch (Throwable) {
            return;
        }

        if ($count === 0 || $oldest === null) {
            $this->reportPass('delivery retention', 'no processed delivery past '.$deduper->retentionHours().'h');

            return;
        }

        $this->reportWarn('delivery retention', "{$count} processed delivery row(s) past ".$deduper->retentionHours().'h, the oldest stored '
            .$oldest->format('Y-m-d H:i:s').' UTC: schedule tmesis:webhooks:prune (daily)');
    }

    private function resolveDeduper(): DeliveryDeduper
    {
        return $this->laravel->make(DeliveryDeduper::class);
    }

    /**
     * The `table` driver checks its table and cache store without a probe claim: the
     * table is the app's, and forget() leaves the row, so a probe would stay behind.
     */
    private function checkTableDeduper(TableDeliveryDeduper $deduper): void
    {
        $problems = $deduper->problems();

        foreach ($problems as $problem) {
            $this->reportFail('dedupe store', $problem);
        }

        if ($problems !== []) {
            return;
        }

        $this->reportPass('dedupe store', $deduper->describe());
        $this->checkDurability(
            $deduper,
            'Point tmesis.webhooks.dedupe.cache_store at a '
            .implode(', ', TableDeliveryDeduper::DURABLE_CACHE_DRIVERS).' store.'
        );
        $this->checkUnprocessed($deduper);
    }

    private function checkDurability(DeliveryDeduper $deduper, string $fix): void
    {
        if ($deduper->isDurable()) {
            return;
        }

        $this->reportWarn(
            'dedupe durability',
            'the store is not durable. The node is at-least-once and retries, so a store '
            .'that forgets on restart re-dispatches every delivery retried after it and '
            .'every listener runs twice for those; recording.registered is where that '
            .'costs most (an asset attached twice). '.$fix
        );
    }

    private function checkUnprocessed(DeliveryDeduper $deduper): void
    {
        $stale = $deduper->unprocessedOlderThan(86400);

        if ($stale > 0) {
            $this->reportWarn(
                'unprocessed deliveries',
                $stale.' delivery(s) claimed over 24h ago and never marked processed. The node '
                .'had its 204 and will not retry them: each is a dispatch that failed or never ran. '
                .'Run php artisan tmesis:webhooks:retry to dispatch them again.'
            );
        }
    }

    private function checkFixtures(): void
    {
        $root = ContractSchemas::root();

        if (! is_file($root.'/PROVENANCE.md')) {
            $this->reportFail('contract fixtures', 'PROVENANCE.md is missing from '.$root);

            return;
        }

        // The expected set is what PROVENANCE.md's own table names, not a number in
        // this file: a repin that adds a schema (rc5 added two) then needs no edit
        // here, and a bundled tree that disagrees with its own table is the finding.
        $expected = [];

        if (preg_match('/^```$(.*?)^```$/ms', (string) file_get_contents($root.'/PROVENANCE.md'), $block) === 1) {
            foreach (explode("\n", $block[1]) as $line) {
                if (preg_match('#^[0-9a-f]{64}  docs/contract/schemas/(.+\.schema\.json)$#', trim($line), $row) === 1) {
                    $expected[] = $row[1];
                }
            }
        }

        $present = array_map(
            static fn (string $path): string => basename($path),
            glob($root.'/docs/contract/schemas/*.schema.json') ?: [],
        );

        sort($expected);
        sort($present);

        if ($expected === []) {
            $this->reportFail('contract fixtures', 'PROVENANCE.md names no schema files in its table');

            return;
        }

        $expected === $present
            ? $this->reportPass('contract fixtures', count($present).' schemas, every one named by PROVENANCE.md')
            : $this->reportFail(
                'contract fixtures',
                count($present).' schema files present, PROVENANCE.md names '.count($expected)
                .' (missing: '.(implode(', ', array_diff($expected, $present)) ?: 'none')
                .'; unlisted: '.(implode(', ', array_diff($present, $expected)) ?: 'none').')'
            );
    }

    private function checkLive(NodeRegistry $registry, TmesisClientInterface $client): void
    {
        try {
            $names = $registry->names();
        } catch (TmesisException $e) {
            $this->reportFail('live', $e->getMessage());

            return;
        }

        foreach ($names as $name) {
            if ($this->receiveOnly($registry, $name)) {
                $this->reportWarn("live {$name}", 'skipped: no admin url');

                continue;
            }

            $this->checkScheme($registry, $name);

            try {
                $health = $client->node($name)->getHealth();
                $this->reportPass("live {$name}", $health->status().', version '.$health->version());
            } catch (TmesisException $e) {
                $this->reportFail("live {$name}", $e->getMessage());

                continue;
            }

            $this->checkEncoderPins($name, $health);
        }
    }

    /**
     * A pin that failed at boot (tmesis `v0.9.0`, handover 3.4) refuses its workload
     * until it is repaired or set to `auto` and the node restarts; it does not clear
     * by waiting. A warning, not a failure: the box is up and the other workload
     * still runs. No row when both encoders are usable or absent.
     */
    private function checkEncoderPins(string $name, HealthFull $health): void
    {
        $pins = [
            'live encode.video_encoder' => $health->videoEngine(),
            'jobs jobs.video_encoder' => $health->jobsVideoEngine(),
        ];

        foreach ($pins as $pin => $engine) {
            if ($engine === null || $engine->usable()) {
                continue;
            }

            $detail = "[{$pin}] pinned to {$engine->requested()} is not usable";

            if ($engine->reason() !== '') {
                $detail .= ": {$engine->reason()}";
            }

            $this->reportWarn("encoder {$name}", $detail);
        }
    }

    /**
     * The admin url's scheme: the bearer crosses whatever the url says.
     *
     * A url that cannot be read is left to the live call, which names the key.
     */
    private function checkScheme(NodeRegistry $registry, string $name): void
    {
        try {
            $url = $registry->url($registry->get($name));
        } catch (TmesisException) {
            return;
        }

        // The rule Transport enforces on every admin call, so a FAIL here
        // is a node whose admin calls throw InsecureTransportException.
        $target = Transport::cleartextTarget($url);

        if ($target === null) {
            $this->reportPass("scheme {$name}", strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 'https' : 'http on a loopback host');

            return;
        }

        $environment = (string) app()->environment();
        $detail = "[{$target}]: the admin token would cross the network in clear; the node's api.tls.domain is the fix";

        if (Transport::allowsCleartext($environment)) {
            $this->reportWarn("scheme {$name}", "{$detail} (allowed in the [{$environment}] environment only)");

            return;
        }

        $this->reportFail("scheme {$name}", "{$detail}; every admin call to it is refused outside local and testing");
    }

    /**
     * A resolver-supplied node with no admin url: nothing to call.
     */
    private function receiveOnly(NodeRegistry $registry, string $name): bool
    {
        if (is_array(config("tmesis.nodes.{$name}"))) {
            return false;
        }

        try {
            return blank($registry->get($name)->url);
        } catch (TmesisException) {
            return false;
        }
    }

    private function requireValue(string $label, string $key, mixed $value, bool $secret = true): void
    {
        if (blank($value) || ! is_string($value)) {
            $this->reportFail($label, "[{$key}] is not set");

            return;
        }

        if ($secret && $value !== trim($value)) {
            // The VALUE is never printed: it is a secret, and the point is which key
            // is wrong, not what is in it.
            $this->reportFail($label, "[{$key}] has leading or trailing whitespace, which makes it a different secret");

            return;
        }

        $this->reportPass($label, 'set');
    }

    private function reportPass(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>pass</> %-28s %s', $label, $detail));
    }

    private function reportWarn(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=yellow>warn</> %-28s %s', $label, $detail));
    }

    private function reportFail(string $label, string $detail): void
    {
        $this->failed = true;
        $this->line(sprintf('  <fg=red>fail</> %-28s %s', $label, $detail));
    }
}
