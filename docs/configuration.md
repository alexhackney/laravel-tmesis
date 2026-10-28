# Configuration

Every scalar setting has an environment variable and a documented default; the arrays
(mappings, columns, middleware) and `nodes_table.soft_delete` are set in PHP. Config defaults use a
`blank()` two-step rather than `env('X', $default)`, because some secret managers render an absent
key as `""` rather than null and the second argument would never apply.

| Key | Env | Default |
|---|---|---|
| `default` | `TMESIS_DEFAULT_NODE` | `default` |
| `nodes.default.url` | `TMESIS_NODE_URL` | `http://127.0.0.1:7000` |
| `nodes.default.token` | `TMESIS_NODE_TOKEN` | `null` |
| `nodes.default.webhook_secret` | `TMESIS_WEBHOOK_SECRET` | `null` |
| `nodes.default.hook_secret` | `TMESIS_HOOK_SECRET` | `null` (falls back to the webhook secret) |
| `nodes.default.keyterms_hook_secret` | `TMESIS_KEYTERMS_HOOK_SECRET` | `null` (falls back to the webhook secret) |
| `nodes_resolver` | `TMESIS_NODES_RESOLVER` | `null` (config nodes only; see Many nodes) |
| `nodes_table.connection` | `TMESIS_NODES_TABLE_CONNECTION` | `null` (the default connection) |
| `nodes_table.table` | `TMESIS_NODES_TABLE` | `ingest_servers` |
| `nodes_table.columns` | none | `name` `hostname`, `webhook_secret` `webhook_token`, `hook_secret` `null`, `keyterms_hook_secret` `null`, `token` `admin_token` |
| `nodes_table.where` | none | `['type' => 'tmesis']` |
| `nodes_table.soft_delete` | none | `deleted_at` (`null` disables) |
| `nodes_table.encrypted` | none | `['admin_token']` |
| `nodes_table.url` | `TMESIS_NODES_TABLE_URL` | `null` (receive only) |
| `keys.secret` | `TMESIS_KEY_SECRET` | `null` |
| `keys.previous_secrets` | `TMESIS_KEY_PREVIOUS_SECRETS` (comma separated) | `[]` |
| `webhooks.enabled` | `TMESIS_WEBHOOKS_ENABLED` | `false` |
| `webhooks.prefix` | `TMESIS_WEBHOOKS_PREFIX` | `webhooks` |
| `webhooks.middleware` | none | `[]` |
| `webhooks.dedupe.driver` | `TMESIS_DEDUPE_DRIVER` | `database` |
| `webhooks.dedupe.retention_hours` | `TMESIS_DEDUPE_RETENTION_HOURS` | `48` (minimum) |
| `webhooks.dedupe.cache_store` | `TMESIS_DEDUPE_CACHE_STORE` | `null` (the default store) |
| `webhooks.dedupe.table.connection` | `TMESIS_DEDUPE_TABLE_CONNECTION` | `null` (the default connection) |
| `webhooks.dedupe.table.name` | `TMESIS_DEDUPE_TABLE` | `webhooks` |
| `webhooks.dedupe.table.source` | `TMESIS_DEDUPE_TABLE_SOURCE` | `tmesis` |
| `webhooks.dedupe.table.columns` | none | `id`, `source`, `payload`, `processed_at`, `reprocessed_at` (nullable), `created_at`, `updated_at`, `delivery` (`null`: off; see [Durability](./webhooks.md#durability-of-the-delivery-store)) |
| `hooks.enabled` | `TMESIS_HOOKS_ENABLED` | `false` |
| `hooks.decider` | `TMESIS_HOOKS_DECIDER` | `AllowAllDecider::class` |
| `hooks.on_error` | `TMESIS_HOOKS_ON_ERROR` | `false` (deny) |
| `hooks.slow_ms` | `TMESIS_HOOKS_SLOW_MS` | `100` |
| `hooks.keyterms.enabled` | `TMESIS_KEYTERMS_HOOK_ENABLED` | `false` |
| `hooks.keyterms.provider` | `TMESIS_KEYTERMS_PROVIDER` | `NoKeyterms::class` (answers none) |
| `transfers.signer` | `TMESIS_TRANSFER_SIGNER` | `null` (no listener; see Signed transfers) |
| `transfers.queue` | `TMESIS_TRANSFER_QUEUE` | `null` (the default queue) |
| `contract.validate_incoming` | `TMESIS_CONTRACT_VALIDATE_INCOMING` | `false` |
| `http.timeout` | `TMESIS_HTTP_TIMEOUT` | `10` |
| `http.connect_timeout` | `TMESIS_HTTP_CONNECT_TIMEOUT` | `5` |
| `http.verify` | `TMESIS_HTTP_VERIFY` | `true` (or a CA bundle path; `false` refused outside `local`/`testing`) |
| `enrollment.enabled` | `TMESIS_ENROLLMENT_ENABLED` | `false` |
| `enrollment.repository` | `TMESIS_ENROLLMENT_REPOSITORY` | `null` (or bind `NodeRepository`) |
| `enrollment.facts` | `TMESIS_ENROLLMENT_FACTS` | `null` (or bind `EnrollmentFacts`) |
| `enrollment.secrets` | `TMESIS_ENROLLMENT_SECRETS` | `null` (optional; or bind `EnrollmentSecrets`) |
| `enrollment.templates` | `TMESIS_ENROLLMENT_TEMPLATES` | `null` (the bundled contract templates) |
| `enrollment.bootstrap_ttl_minutes` | `TMESIS_ENROLLMENT_BOOTSTRAP_TTL_MINUTES` | `15` |
| `fleet.enabled` | `TMESIS_FLEET_ENABLED` | `false` |
| `fleet.provider` | `TMESIS_FLEET_PROVIDER` | `null` (or bind `DesiredStateProvider`) |
| `fleet.stale_after_seconds` | `TMESIS_FLEET_STALE_AFTER_SECONDS` | `60` |
| `fleet.join_token_ttl_minutes` | `TMESIS_FLEET_JOIN_TOKEN_TTL_MINUTES` | `60` |
| `fleet.retry_window_minutes` | `TMESIS_FLEET_RETRY_WINDOW_MINUTES` | `15` (minimum) |
| `fleet.command_ttl_seconds` | `TMESIS_FLEET_COMMAND_TTL_SECONDS` | `900` |
| `fleet.max_body_bytes` | `TMESIS_FLEET_MAX_BODY_BYTES` | `4194304` (minimum) |
| `fleet.tombstone_days` | `TMESIS_FLEET_TOMBSTONE_DAYS` | `14` (minimum) |

**Secrets are read exactly as configured and never trimmed.** A trailing newline is a
different secret and changes every HMAC it computes, which shows up as "every webhook
is rejected" rather than as an error. `tmesis:doctor` reports surrounding whitespace by
naming the key, never the value.

Values that this package cannot work without are required **at use**: a blank `token`
throws `InvalidConfigurationException` naming `tmesis.nodes.<name>.token` before any
request is built, rather than going out as an empty bearer.

# Many nodes

Every node that sends this app webhooks or hooks, or that the app calls, must be known
by the name it sends in `X-Tmesis-Node`. There are two places a node can come from,
and they combine.

**Static config, for the nodes that stay up.** Publish the config
(`php artisan vendor:publish --tag=tmesis-config`) and add one entry per node under
`nodes`, each with its own environment keys:

```php
'nodes' => [
    'origin-dc-01' => [
        'url' => env('TMESIS_ORIGIN_URL'),
        'token' => blank(env('TMESIS_ORIGIN_TOKEN')) ? null : env('TMESIS_ORIGIN_TOKEN'),
        'webhook_secret' => blank(env('TMESIS_ORIGIN_WEBHOOK_SECRET')) ? null : env('TMESIS_ORIGIN_WEBHOOK_SECRET'),
        'hook_secret' => blank(env('TMESIS_ORIGIN_HOOK_SECRET')) ? null : env('TMESIS_ORIGIN_HOOK_SECRET'),
        'keyterms_hook_secret' => blank(env('TMESIS_ORIGIN_KEYTERMS_HOOK_SECRET')) ? null : env('TMESIS_ORIGIN_KEYTERMS_HOOK_SECRET'),
    ],
    'transcoder-dc-01' => [ /* the same keys, its own env names */ ],
],
```

**A node resolver, for a fleet that changes at runtime.** Origins and transcoders spun
up for a busy day and down again afterwards cannot live in a config file. Implement
`AlexHackney\Tmesis\Client\NodeResolver` and bind it, or name the class in
`TMESIS_NODES_RESOLVER`:

```php
namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;

interface NodeResolver
{
    public function find(string $name): ?NodeConfig;   // null: no such node (any more)

    /** @return iterable<NodeConfig> */
    public function all(): iterable;                    // for tmesis:health --all and tmesis:doctor
}
```

```php
// AppServiceProvider::register()
$this->app->bind(\AlexHackney\Tmesis\Client\NodeResolver::class, \App\Tmesis\NodeRowResolver::class);
```

How the two combine:

- **Config first, then the resolver.** A name in `tmesis.nodes` never reaches the
  resolver, so the fixed nodes cost no lookup and a row in your store can never
  redirect one of them or swap its secrets. On a clash, config wins and the name is
  listed once.
- **Everything goes through it**: the webhook receiver, the publish hook endpoint,
  `Tmesis::node('name')`, the transfer signer's choice of node, `tmesis:health --all`
  and `tmesis:doctor`. `TMESIS_DEFAULT_NODE` may name a resolver node.
- **Answers are memoized for one request or one queued job, never longer.** The memo
  is a scoped container instance, which Octane forgets per request and a queue worker
  per job, so a node you spin down stops being accepted on the next request. A miss is
  never memoized: a row written a moment after the node's first webhook is accepted
  on the node's retry. Cache in your resolver if you want to (a short TTL is fine),
  but keep it short and never cache a miss for long.
- **A node that is gone is a `401`**, exactly like a name nobody configured
  (`bad_signature`, logged as `unknown_node`). The node retries a `401` for 24 hours,
  so a node that is meant to exist must have its row BEFORE it starts sending
  webhooks.
- **A resolver that throws is a `503`** (`{"error":{"code":"node_lookup_failed"}}`),
  and so is a misconfigured `nodes_resolver` class or a resolved node with no webhook
  secret. The delivery may be genuine, the store may only be down for a moment, and a
  `503` makes the node retry it, where a `401` would say "forgery". The log line names
  the node, the delivery, the resolver class and the class of what was thrown, never
  the thrown message (a driver's message can carry a DSN) and never a secret; the
  original is the `previous` of the `NodeResolverException` a direct caller receives.
  Configured nodes keep working while the resolver is down. For a hook the node reads
  any non-2xx the same way, as its `on_timeout` posture.
- **Unknown names reach your resolver.** An unauthenticated caller can make the
  receiver look up any name, once per request. Index the name column, and if that
  matters for you, rate-limit the route (see Middleware below).
- **`keys.secret` stays global.** Publish keys are ours and self-validating: every
  node, configured or resolved, verifies them with the same `ingest.keys` secret, so
  there is no per-node key secret to resolve.
- **Receive-only nodes are fine.** A resolved node with no `url` (and no `token`) can
  send webhooks and hooks; only an admin call to it throws the required-at-use
  `InvalidConfigurationException` naming `tmesis.nodes_resolver[<name>].url`.
  `tmesis:health --all` and `tmesis:doctor --live` skip it by name, and the doctor
  warns rather than fails.

`AlexHackney\Tmesis\Testing\ArrayNodeResolver::bind([...])` binds an in-memory resolver for
tests; it works with `Tmesis::fake()` too, because the fake reads nodes through the
same registry.

## A nodes table, with no code

`AlexHackney\Tmesis\Client\TableNodeResolver` reads nodes from a table the app already owns,
driven by `tmesis.nodes_table`: one query per lookup, only the columns it needs,
filtered by a `where` map and a soft-delete column. Columns listed in `encrypted` go
through Laravel's encrypter first (an `encrypted` cast on the model); a value that will
not decrypt is logged by node and column only and treated as missing. The URL is an
optional `{column}` template.

The defaults are shaped for an `ingest_servers` table (legacy nginx servers share it
with `type` `nginx`; the nodes heartbeat in and are never called), but they assume a
column that table does not have yet. `columns.token` defaults to `admin_token` (in
`encrypted`), and a configured column is always selected, so until your migration
adds `admin_token` (nullable encrypted text, with `admin_url` beside it for the
`{admin_url}` template) every lookup fails on the
unknown column, and a throwing resolver is `503 node_lookup_failed` to every node. Either
run that migration first, or, for a receive-only fleet, set `'token' => null`: a null
column is left out of the query and the node resolves with no token. `php artisan
tmesis:doctor` names a configured column the table is missing.
With the column in place:

```dotenv
TMESIS_NODES_RESOLVER="AlexHackney\Tmesis\Client\TableNodeResolver"
# only once the app starts calling nodes' admin APIs:
# TMESIS_NODES_TABLE_URL="https://{dns_name}:7000"   # a template over the row
# TMESIS_NODES_TABLE_URL="{admin_url}"                # or a column holding the whole url
```

```php
'nodes_table' => [
    'connection' => null,
    'table' => 'ingest_servers',
    'columns' => [
        'name' => 'hostname',              // = X-Tmesis-Node, unique among live rows
        'webhook_secret' => 'webhook_token',
        'hook_secret' => null,             // falls back to the webhook secret
        'keyterms_hook_secret' => null,    // falls back to the webhook secret (tmesis v0.8.0)
        'token' => 'admin_token',          // the admin bearer; nullable
    ],
    'where' => ['type' => 'tmesis'],
    'soft_delete' => 'deleted_at',
    'encrypted' => ['admin_token'],
    'url' => null,
],
```

## Your own resolver

The recipe for anything the table resolver does not fit, over an Eloquent model whose
secrets are `encrypted` casts:

```php
namespace App\Tmesis;

use App\Models\Node;
use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use Illuminate\Support\Facades\Cache;

final class NodeRowResolver implements NodeResolver
{
    public function find(string $name): ?NodeConfig
    {
        // Optional: a short cache of HITS only. A spun-down node is still accepted
        // until this expires, so keep it to seconds.
        $row = Cache::remember("tmesis-node:{$name}", 10, fn () => Node::query()
            ->where('name', $name)->whereNull('retired_at')->first()?->only(['name', 'url', 'token', 'webhook_secret', 'hook_secret', 'keyterms_hook_secret']));

        if ($row === null) {
            Cache::forget("tmesis-node:{$name}");   // never cache a miss

            return null;
        }

        return NodeConfig::fromArray($name, $row);
    }

    public function all(): iterable
    {
        return Node::query()->whereNull('retired_at')->get()
            ->map(fn (Node $node) => NodeConfig::fromArray($node->name, $node->only(['url', 'token', 'webhook_secret', 'hook_secret', 'keyterms_hook_secret'])));
    }
}
```

Caching decrypted secrets in a shared cache store puts them in that store in the
clear; skip the cache if that store is not as trusted as the database.

## TLS on the admin API

Every admin call carries the node's bearer token, so a node reached over anything but
loopback belongs on `https`. The primary path is ACME per box: the control plane sets
a DNS name for the node, the node's `api.tls.domain` is that name, and the node serves
its own publicly trusted certificate, so a node url of `https://<name>:7000` works
with nothing to configure here (`TMESIS_HTTP_VERIFY` stays `true`). For the node's
file-pair path (a certificate from a private CA) point `TMESIS_HTTP_VERIFY` at an
absolute path to that CA bundle. `false` is for labs and is refused outside the
`local` and `testing` environments. There is no client-certificate option: the admin
API is bearer over TLS. `tmesis:doctor` checks the value.

**The bearer never crosses the network in clear (`0.8.3`).** Outside the
`local` and `testing` environments, an admin call to a url that is not `https` and
whose host is not loopback (`localhost`, 127.0.0.0/8, `::1`) is refused BEFORE
anything is sent, with `InsecureTransportException` naming the node and the scheme
and host (never the token). The tokenless liveness probe (`getPublicHealth()`) carries
no credential and is not refused. The fix is the node's `api.tls.domain`: it then
serves its own ACME certificate, and its url is `https://<that name>:<port>`. An
enrolment that reports such an `http` url registers receive-only (see "Registering
back"). `tmesis:doctor --live` reports each node's scheme: `https` passes, `http` on a
loopback host passes, `http` anywhere else FAILS (a warning in `local` and `testing`,
where the call is allowed).
