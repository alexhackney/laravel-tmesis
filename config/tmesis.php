<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Hooks\AllowAllDecider;
use AlexHackney\Tmesis\Hooks\NoKeyterms;

/*
 * Every default below uses the blank() two-step rather than env('X', $default).
 * Doppler (the primary consumer's secret store) renders an absent key as an empty
 * string, not null, so env('X', 'fallback') would hand back "" and the fallback
 * would never apply. blank() treats "" and null alike.
 */
return [

    /*
     * The node name used when a call names none. It is a key of the nodes array
     * below, or a name the node resolver supplies; not a hostname.
     */
    'default' => blank(env('TMESIS_DEFAULT_NODE')) ? 'default' : env('TMESIS_DEFAULT_NODE'),

    /*
     * One entry per Tmesis node this app talks to, keyed by the node name the node
     * reports in X-Tmesis-Node. Add further nodes here; the shape is the same.
     *
     * With a node resolver configured (nodes_resolver below), an entry here that
     * carries no token, webhook_secret, hook_secret or keyterms_hook_secret is left out
     * of the node list
     * (tmesis:doctor, tmesis:health --all), which is what happens to the default
     * entry below when every node comes from the resolver. Point TMESIS_DEFAULT_NODE
     * at a resolver node so a call that names none reaches a real one.
     */
    'nodes' => [

        'default' => [

            /*
             * Scheme, host and port of the node's admin API, with no path: the
             * contract's own paths already carry /api/v1. Required at use, so a
             * blank value throws InvalidConfigurationException before any request
             * is built rather than producing a request to nowhere.
             */
            'url' => blank(env('TMESIS_NODE_URL')) ? 'http://127.0.0.1:7000' : env('TMESIS_NODE_URL'),

            /*
             * The node's single-scope admin bearer token. Required at use: a blank
             * token would otherwise go out as an empty Authorization header and
             * come back as a 401 with no explanation.
             */
            'token' => blank(env('TMESIS_NODE_TOKEN')) ? null : env('TMESIS_NODE_TOKEN'),

            /*
             * The shared secret this node signs its webhooks with. Read as raw
             * bytes exactly as configured and NEVER trimmed: a trailing newline is
             * a different secret and changes every HMAC. Run tmesis:doctor to have
             * surrounding whitespace reported.
             */
            'webhook_secret' => blank(env('TMESIS_WEBHOOK_SECRET')) ? null : env('TMESIS_WEBHOOK_SECRET'),

            /*
             * The secret this node signs synchronous publish hooks with, from its
             * ingest.verify_hook.secret. Leave it blank when the node signs both
             * with one secret, which is the usual setup: the resolver falls back to
             * webhook_secret above. Raw bytes, never trimmed, same rule again.
             */
            'hook_secret' => blank(env('TMESIS_HOOK_SECRET')) ? null : env('TMESIS_HOOK_SECRET'),

            /*
             * The secret this node signs its keyterms hook GET with, from its
             * transcription.keyterms_hook.secret (TMESIS_KEYTERMS_HOOK_SECRET on the
             * box, tmesis v0.8.0). Blank falls back to webhook_secret above, which is
             * what tmesis:enroll-node mints it as unless --distinct-keyterms-secret.
             * Raw bytes, never trimmed.
             */
            'keyterms_hook_secret' => blank(env('TMESIS_KEYTERMS_HOOK_SECRET')) ? null : env('TMESIS_KEYTERMS_HOOK_SECRET'),
        ],
    ],

    /*
     * Where nodes that are not in the array above come from: a class implementing
     * AlexHackney\Tmesis\Client\NodeResolver, resolved from the container, typically
     * reading the consumer's own nodes table. Consulted AFTER the nodes array, so a
     * configured name always wins a clash. Null means config only. Binding
     * NodeResolver in a service provider's register() does the same as naming it
     * here, and wins over it.
     */
    'nodes_resolver' => blank(env('TMESIS_NODES_RESOLVER')) ? null : env('TMESIS_NODES_RESOLVER'),

    /*
     * AlexHackney\Tmesis\Client\TableNodeResolver: nodes as rows in a table the app already
     * owns. Used only when nodes_resolver above names that class. The table is read,
     * never written, one query per lookup; secrets are never logged.
     */
    'nodes_table' => [

        /*
         * The database connection the table lives on. Null means the default.
         */
        'connection' => blank(env('TMESIS_NODES_TABLE_CONNECTION')) ? null : env('TMESIS_NODES_TABLE_CONNECTION'),

        /*
         * The table name.
         */
        'table' => blank(env('TMESIS_NODES_TABLE')) ? 'ingest_servers' : env('TMESIS_NODES_TABLE'),

        /*
         * Column names. "name" is the node name, the value the node sends in
         * X-Tmesis-Node, and must be unique among live rows. "webhook_secret" is
         * what the node signs webhooks with. "hook_secret" is its
         * ingest.verify_hook.secret; null falls back to the webhook secret, as a
         * configured node does. "keyterms_hook_secret" is its
         * transcription.keyterms_hook.secret, the same fall back. "token" is the
         * admin bearer; null when the app never calls the node's admin API.
         */
        'columns' => [
            'name' => 'hostname',
            'webhook_secret' => 'webhook_token',
            'hook_secret' => null,
            'keyterms_hook_secret' => null,
            'token' => 'admin_token',
        ],

        /*
         * Only rows matching every column => value pair here are nodes. A null
         * value matches NULL. The default keeps other kinds of server that share
         * the table out.
         */
        'where' => ['type' => 'tmesis'],

        /*
         * A column that must be NULL for the row to count, the soft delete. Null
         * disables the check.
         */
        'soft_delete' => 'deleted_at',

        /*
         * Columns stored with Laravel's encrypter (an encrypted cast), decrypted
         * with decryptString semantics before use. A value that does not decrypt is
         * logged by node and column only and treated as missing.
         */
        'encrypted' => ['admin_token'],

        /*
         * The node's admin API URL as a template of {column} placeholders, for
         * example "http://{ip_address}:7000". Null means no URL: the node can still
         * send webhooks and hooks, and only an admin call to it throws, naming the
         * url. A placeholder whose value is blank renders no URL either.
         */
        'url' => blank(env('TMESIS_NODES_TABLE_URL')) ? null : env('TMESIS_NODES_TABLE_URL'),
    ],

    /*
     * Publish keys. The control plane mints them; the node verifies them with no
     * call back here, so these two must agree byte for byte with the node's
     * ingest.keys configuration.
     */
    'keys' => [

        /*
         * The HMAC-SHA256 secret every new key is minted with. Raw bytes, never
         * trimmed, same rule as webhook_secret. One secret for the whole fleet,
         * configured and resolved nodes alike: every node verifies keys with it.
         */
        'secret' => blank(env('TMESIS_KEY_SECRET')) ? null : env('TMESIS_KEY_SECRET'),

        /*
         * Secrets that are being rotated out. A key that fails against the current
         * secret is retried against these, so a rotation does not invalidate keys
         * already in producers' hands. Comma separated in the environment.
         */
        'previous_secrets' => blank(env('TMESIS_KEY_PREVIOUS_SECRETS'))
            ? []
            : array_values(array_filter(array_map('trim', explode(',', (string) env('TMESIS_KEY_PREVIOUS_SECRETS'))))),
    ],

    /*
     * The inbound half: the receiver for the node's at-least-once webhooks.
     */
    'webhooks' => [

        /*
         * Registers POST {prefix}/tmesis. Off by default so installing the package
         * cannot open a route; with the database dedupe driver, turn it on once the
         * migration has been run.
         */
        'enabled' => filter_var(
            blank(env('TMESIS_WEBHOOKS_ENABLED')) ? false : env('TMESIS_WEBHOOKS_ENABLED'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * Route prefix. The receiver lives at {prefix}/tmesis.
         */
        'prefix' => blank(env('TMESIS_WEBHOOKS_PREFIX')) ? 'webhooks' : env('TMESIS_WEBHOOKS_PREFIX'),

        /*
         * Group middleware wrapped around the route. Empty on purpose: the
         * signature middleware is the route's own authentication, and putting the
         * node inside a consumer's api group would subject it to throttle:api,
         * which a node delivering with concurrency 4 crosses. Every 429 is then
         * retried for 24 hours and arrives as a duplicate. See docs/webhooks.md for a
         * node-keyed limiter if you want one.
         */
        'middleware' => [],

        /*
         * Delivery deduplication. The node is at-least-once and unordered, so a
         * duplicate is normal traffic, not an error.
         */
        'dedupe' => [

            /*
             * "database" (durable, the default), "table" or "cache" (the explicit
             * opt-out). Durable by default because the node is at-least-once and
             * retries: a store that forgets on restart re-dispatches every delivery
             * the node retries after it, so every listener runs twice for those, and
             * recording.registered is where a double dispatch costs most (an asset
             * attached twice).
             *
             * "database" keeps its own table, tmesis_webhook_deliveries (publish
             * --tag=tmesis-migrations). "table" dedupes on the cache store below
             * (an atomic add on the delivery id) and keeps a replay log in a table
             * the app already has (see "table" below); durable when that cache
             * store is redis, database, memcached or dynamodb. Use "cache" in tests
             * and when you accept losing a failed dispatch.
             */
            'driver' => blank(env('TMESIS_DEDUPE_DRIVER')) ? 'database' : env('TMESIS_DEDUPE_DRIVER'),

            /*
             * How long a delivery id stays known. The node retries a delivery for
             * 24 hours, so anything under 48 would let a late retry through as new;
             * values below 48 are refused.
             */
            'retention_hours' => (int) (blank(env('TMESIS_DEDUPE_RETENTION_HOURS')) ? 48 : env('TMESIS_DEDUPE_RETENTION_HOURS')),

            /*
             * Which cache store the "cache" and "table" drivers use. Null means the
             * default store. The dedupe is its "add", so pick a store where that is
             * atomic across processes: redis, database, memcached or dynamodb. The
             * "table" driver also takes its dispatch lease as a lock on this store.
             */
            'cache_store' => blank(env('TMESIS_DEDUPE_CACHE_STORE')) ? null : env('TMESIS_DEDUPE_CACHE_STORE'),

            /*
             * The "table" driver: the replay log in an EXISTING table the app owns,
             * shared with other sources. Nothing here is migrated by this package.
             * One row per delivery: the source, the raw signed body as the payload,
             * and the timestamps. Rows are never deleted by this package (prune()
             * is a no-op: the app owns that table's retention), and the app's own
             * reprocessing must skip rows of this source.
             */
            'table' => [

                /*
                 * The database connection the table lives on. Null means the
                 * default connection.
                 */
                'connection' => blank(env('TMESIS_DEDUPE_TABLE_CONNECTION')) ? null : env('TMESIS_DEDUPE_TABLE_CONNECTION'),

                /*
                 * The table name.
                 */
                'name' => blank(env('TMESIS_DEDUPE_TABLE')) ? 'webhooks' : env('TMESIS_DEDUPE_TABLE'),

                /*
                 * The value written to the source column. Every read and write
                 * this package makes is scoped to it, so other sources' rows are
                 * never read, listed, counted or touched.
                 */
                'source' => blank(env('TMESIS_DEDUPE_TABLE_SOURCE')) ? 'tmesis' : env('TMESIS_DEDUPE_TABLE_SOURCE'),

                /*
                 * Column names on that table. "id" is the auto-increment primary
                 * key every later update goes through. "payload" takes the raw
                 * signed body (a json column is fine). "reprocessed_at" is set
                 * alongside processed_at when tmesis:webhooks:retry dispatches a
                 * row; null means the table has no such column. "delivery" is
                 * null by default, and then the cache store above is the ONLY
                 * dedupe: a key lost inside the window (cache:clear on a deploy, an
                 * eviction) lets a second sight of a delivery dispatch again. Name
                 * a nullable string column with a unique index of its own and the
                 * claim writes the delivery id into it, so a second sight is a
                 * unique violation and never a second dispatch. The fleet requires
                 * it on this driver. Every other entry is required.
                 */
                'columns' => [
                    'id' => 'id',
                    'source' => 'source',
                    'payload' => 'payload',
                    'processed_at' => 'processed_at',
                    'reprocessed_at' => 'reprocessed_at',
                    'created_at' => 'created_at',
                    'updated_at' => 'updated_at',
                    'delivery' => null,
                ],
            ],
        ],
    ],

    /*
     * The synchronous half: the publish veto the node calls on the handshake.
     *
     * Nothing here is deduped or stored. The node holds the publish open waiting for
     * the answer under a hard cap (ingest.verify_hook.timeout_ms, 500 ms by default),
     * so everything on this path is a decision and nothing on it is work.
     */
    'hooks' => [

        /*
         * Registers POST {webhooks.prefix}/hooks/publish. Off by default, the same
         * posture as the receiver: a node calling an endpoint that answers a default
         * allow is not obviously worse than a 404, but it should be a choice.
         */
        'enabled' => filter_var(
            blank(env('TMESIS_HOOKS_ENABLED')) ? false : env('TMESIS_HOOKS_ENABLED'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * The class that decides. It must implement AlexHackney\Tmesis\Hooks\PublishHookDecider
         * and is resolved from the container, so constructor injection works. The
         * default allows every publish that reaches the hook: every key the node
         * proved locally, and on an OPEN node a bare unproven key too (a disproved key
         * never reaches it). Bind your own decider before opening a node.
         */
        'decider' => blank(env('TMESIS_HOOKS_DECIDER'))
            ? AllowAllDecider::class
            : env('TMESIS_HOOKS_DECIDER'),

        /*
         * What to answer when the decider THROWS. False denies, true allows.
         *
         * Either way the answer is a 200 carrying a decision. A 5xx would tell the
         * node nothing and hand the choice to its on_timeout posture, so one broken
         * deploy would take out every publish on a node running on_timeout: deny.
         */
        'on_error' => filter_var(
            blank(env('TMESIS_HOOKS_ON_ERROR')) ? false : env('TMESIS_HOOKS_ON_ERROR'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * Log a warning naming the decider when it takes longer than this, in
         * milliseconds. A budget under the node's cap, not a promise: nothing here
         * asserts a wall clock threshold or aborts a slow decider.
         */
        'slow_ms' => (int) (blank(env('TMESIS_HOOKS_SLOW_MS')) ? 100 : env('TMESIS_HOOKS_SLOW_MS')),

        /*
         * The keyterms hook (tmesis v0.8.0, WEBHOOKS.md 13): the node GETs a
         * captioning stream's keyterms (a show's fight card) at the session's open and
         * every transcription.keyterms_hook.interval_seconds after, signed with its
         * keyterms hook secret, and never waits for the answer. Point the node's
         * transcription.keyterms_hook.url at {webhooks.prefix}/hooks/keyterms.
         */
        'keyterms' => [

            /*
             * Whether GET {webhooks.prefix}/hooks/keyterms answers. The route is always
             * registered (since 0.8.3: the node reads a 404 as "no keyterms" and clears
             * the list); off, a signed request is 503 keyterms_disabled and the node
             * keeps its list. Off by default, independent of the publish hook above.
             */
            'enabled' => filter_var(
                blank(env('TMESIS_KEYTERMS_HOOK_ENABLED')) ? false : env('TMESIS_KEYTERMS_HOOK_ENABLED'),
                FILTER_VALIDATE_BOOLEAN
            ),

            /*
             * The class that answers. It must implement AlexHackney\Tmesis\Hooks\KeytermsProvider
             * and is resolved from the container. The default answers none. Once the
             * node has an answer in a session it REPLACES the stream's own
             * captions.keyterms for that session (tmesis decision D14), none included,
             * so with the node's hook configured the provider owns the list.
             */
            'provider' => blank(env('TMESIS_KEYTERMS_PROVIDER'))
                ? NoKeyterms::class
                : env('TMESIS_KEYTERMS_PROVIDER'),
        ],
    ],

    /*
     * Signed-target transfers (tmesis v0.5.0). The node never holds a signed
     * target's credentials: it fires transfer.authorization_required and waits for
     * signed requests. This package ships the listener that answers it and no signer.
     */
    'transfers' => [

        /*
         * The class that presigns. It must implement
         * AlexHackney\Tmesis\Transfers\TransferSigner and is resolved from the container, so
         * constructor injection works. Null registers nothing: no listener answers
         * transfer.authorization_required and signed objects wait. Binding
         * TransferSigner in a service provider's register() does the same as naming
         * it here.
         */
        'signer' => blank(env('TMESIS_TRANSFER_SIGNER')) ? null : env('TMESIS_TRANSFER_SIGNER'),

        /*
         * The queue the refresh job goes on. Null is the connection's default. The
         * job carries the event only; the signed requests are minted on the worker.
         */
        'queue' => blank(env('TMESIS_TRANSFER_QUEUE')) ? null : env('TMESIS_TRANSFER_QUEUE'),
    ],

    /*
     * Contract enforcement at runtime.
     */
    'contract' => [

        /*
         * Validate incoming hook and webhook bodies against the bundled schemas
         * before handing them on, and log loudly when one does not validate.
         *
         * Off by default and it REQUIRES opis/json-schema, which this package keeps
         * as a dev dependency: a validator has no business in every consumer's
         * production dependency tree. Turn it on in the reference receiver and in
         * staging, where a body that stopped matching its schema is news.
         */
        'validate_incoming' => filter_var(
            blank(env('TMESIS_CONTRACT_VALIDATE_INCOMING')) ? false : env('TMESIS_CONTRACT_VALIDATE_INCOMING'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
     * Outbound HTTP to the node's admin API. The node is normally on the same host
     * or private network, so these are short on purpose.
     */
    'http' => [

        /*
         * Seconds to wait for a complete response.
         */
        'timeout' => (int) (blank(env('TMESIS_HTTP_TIMEOUT')) ? 10 : env('TMESIS_HTTP_TIMEOUT')),

        /*
         * Seconds to wait for the connection itself.
         */
        'connect_timeout' => (int) (blank(env('TMESIS_HTTP_CONNECT_TIMEOUT')) ? 5 : env('TMESIS_HTTP_CONNECT_TIMEOUT')),

        /*
         * TLS verification of the node's admin API: true, false, or an absolute path
         * to a CA bundle.
         *
         * Leave it true for a node on its own ACME certificate (`api.tls.domain`,
         * `https://<name>:7000` as the node url): that certificate is publicly
         * trusted and needs nothing here. A bundle path is for the node's file-pair
         * path (a private CA) and labs. false is refused outside the local and
         * testing environments: a control plane must not send a bearer to an
         * unverified peer. `tmesis:doctor` checks it.
         */
        'verify' => blank(env('TMESIS_HTTP_VERIFY')) ? true : env('TMESIS_HTTP_VERIFY'),
    ],

    /*
     * Node enrolment: `tmesis:enroll-node` mints a box's secrets, and the box
     * fetches its user-data and config, then registers back under
     * `webhooks.prefix`. The app keeps the rows (a NodeRepository) and supplies the
     * template's non-secret values (EnrollmentFacts); this package ships neither.
     */
    'enrollment' => [

        /*
         * Register `POST {prefix}/enroll/register`, `GET {prefix}/enroll/install`
         * and `GET {prefix}/enroll/config`.
         * Off by default: installing the package must never open a route. The
         * command works without them.
         */
        'enabled' => filter_var(
            blank(env('TMESIS_ENROLLMENT_ENABLED')) ? false : env('TMESIS_ENROLLMENT_ENABLED'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * The class that keeps enrolments. It must implement
         * AlexHackney\Tmesis\Enrollment\NodeRepository and is resolved from the container.
         * Binding NodeRepository in a service provider's register() does the same.
         * No table-backed implementation ships: the app owns its write shape.
         */
        'repository' => blank(env('TMESIS_ENROLLMENT_REPOSITORY')) ? null : env('TMESIS_ENROLLMENT_REPOSITORY'),

        /*
         * The class that supplies a template's non-secret values, keyed by config path
         * (webhooks.url, node.name) since tmesis v0.8.0. It must implement
         * AlexHackney\Tmesis\Enrollment\EnrollmentFacts. Needed only when a template is
         * rendered; binding EnrollmentFacts does the same.
         */
        'facts' => blank(env('TMESIS_ENROLLMENT_FACTS')) ? null : env('TMESIS_ENROLLMENT_FACTS'),

        /*
         * The class that supplies the six reserved secrets the package does not
         * mint (TMESIS_TRANSCRIPTION_API_KEY, TMESIS_TRANSCRIPTION_BATCH_API_KEY,
         * TMESIS_TRANSCRIPTION_FALLBACK_API_KEY, TMESIS_LIVE_ACCESS_KEY,
         * TMESIS_LIVE_SECRET_KEY, TMESIS_SRT_PASSPHRASE) for a user-data render and
         * the .env block. It must
         * implement AlexHackney\Tmesis\Enrollment\EnrollmentSecrets; binding it does the
         * same. OPTIONAL: when absent, the template's own empty fallback applies.
         */
        'secrets' => blank(env('TMESIS_ENROLLMENT_SECRETS')) ? null : env('TMESIS_ENROLLMENT_SECRETS'),

        /*
         * A directory holding cloud-init.<role>.yml (tried first) or config.<role>.yml.
         * Null means the contract's own four config.<role>.yml, bundled at repin
         * (tmesis v0.6.0, tests/Fixtures/contract/docs/contract/templates). Set it to
         * bring your own; a set directory that does not exist is no template, never a
         * silent fall back to the bundle.
         */
        'templates' => blank(env('TMESIS_ENROLLMENT_TEMPLATES')) ? null : env('TMESIS_ENROLLMENT_TEMPLATES'),

        /*
         * Minutes a bootstrap token stays valid for registering. An unregistered box
         * past this is enrolled again with --replace.
         */
        'bootstrap_ttl_minutes' => (int) (blank(env('TMESIS_ENROLLMENT_BOOTSTRAP_TTL_MINUTES')) ? 15 : env('TMESIS_ENROLLMENT_BOOTSTRAP_TTL_MINUTES')),
    ],

    /*
     * The fleet control plane (tmesis v0.7.0, docs/API.md 15): a node enrolls once
     * with `tmesis join` and then heartbeats here; the answer carries its desired state
     * and its commands. Three routes under `webhooks.prefix`: POST fleet/enroll, POST
     * fleet/heartbeat and DELETE fleet/enroll. Run the migrations first
     * (tmesis-migrations): the fleet keeps its own tables.
     */
    'fleet' => [

        /*
         * Register the three routes. Off by default: installing the package must never
         * open a route. Turning it on requires a durable dedupe driver ("database", or
         * "table" on a durable cache store with dedupe.table.columns.delivery set) and a
         * desired-state provider, and is refused at boot without them.
         */
        'enabled' => filter_var(
            blank(env('TMESIS_FLEET_ENABLED')) ? false : env('TMESIS_FLEET_ENABLED'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * The class that says what each node should be running. It must implement
         * AlexHackney\Tmesis\Fleet\DesiredStateProvider and is resolved from the container;
         * binding the interface does the same. The package owns the revision: it bumps
         * only when the document's content changes.
         */
        'provider' => blank(env('TMESIS_FLEET_PROVIDER')) ? null : env('TMESIS_FLEET_PROVIDER'),

        /*
         * Seconds without a heartbeat before a node is stale (FleetNodeStale, once per
         * streak). 60 is six missed beats at the node's default 10 s interval.
         */
        'stale_after_seconds' => (int) (blank(env('TMESIS_FLEET_STALE_AFTER_SECONDS')) ? 60 : env('TMESIS_FLEET_STALE_AFTER_SECONDS')),

        /*
         * Minutes a join token stays valid BEFORE its first use. After the first use the
         * node may repeat the enrollment for retry_window_minutes (a lost response is
         * recovered by rerunning `tmesis join` with the same token).
         */
        'join_token_ttl_minutes' => (int) (blank(env('TMESIS_FLEET_JOIN_TOKEN_TTL_MINUTES')) ? 60 : env('TMESIS_FLEET_JOIN_TOKEN_TTL_MINUTES')),

        /*
         * The repeat window after a join token's first use: the same node id and a fresh
         * fleet token, the previous one revoked. The node's own recovery message
         * promises 15 minutes, so values below 15 are refused.
         */
        'retry_window_minutes' => (int) (blank(env('TMESIS_FLEET_RETRY_WINDOW_MINUTES')) ? 15 : env('TMESIS_FLEET_RETRY_WINDOW_MINUTES')),

        /*
         * Seconds a command may wait to be DELIVERED. A command that has gone out in a
         * heartbeat answer is re-sent until the node acks it, however old; one that
         * never went out (the node was offline) expires after this, so a kick is never
         * delivered to whoever happens to be publishing an hour later.
         */
        'command_ttl_seconds' => (int) (blank(env('TMESIS_FLEET_COMMAND_TTL_SECONDS')) ? 900 : env('TMESIS_FLEET_COMMAND_TTL_SECONDS')),

        /*
         * The largest heartbeat body accepted, in bytes. The contract's number is 4 MiB
         * and values below it are refused. The web server's own limit on this path
         * (client_max_body_size, post_max_size) must be at least this.
         */
        'max_body_bytes' => (int) (blank(env('TMESIS_FLEET_MAX_BODY_BYTES')) ? 4194304 : env('TMESIS_FLEET_MAX_BODY_BYTES')),

        /*
         * Days a delivery id stays known while the fleet is on, whichever channel
         * carried it: the same event can arrive over the other channel up to the node's
         * 7-day horizon later. Values below 14 are refused.
         */
        'tombstone_days' => (int) (blank(env('TMESIS_FLEET_TOMBSTONE_DAYS')) ? 14 : env('TMESIS_FLEET_TOMBSTONE_DAYS')),
    ],
];
