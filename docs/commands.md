# Commands

| Command | What it does |
|---|---|
| `tmesis:health {--node=} {--all} {--emit}` | Health of one node or every configured and resolved node (a resolved node with no admin url is skipped by name). Fails on any unreachable or unavailable node. `--emit` dispatches a `NodeProbed` per node probed. |
| `tmesis:streams {--node=} {--state=}` | The node's stream registry. |
| `tmesis:doctor {--live}` | Config and resolved nodes, secrets (the keyterms hook secret included), whitespace, the receiver, publish hook and keyterms hook routes, the hook decider and keyterms provider, the dedupe store and processed deliveries waiting for `tmesis:webhooks:prune`, runtime schema validation, and the bundled contract fixtures. |
| `tmesis:enroll-node {name} {--role=} {--region=} {--plan=} {--template=} {--mode=} {--out=} {--replace} {--distinct-hook-secret} {--distinct-keyterms-secret} {--fleet}` | Enrol a box: mint its secrets and bootstrap token, render its template with your `values()`, keep it through your `NodeRepository`, and show the secrets once (see "Enrolling nodes"). |
| `tmesis:mint-key {stream} {epoch} {--template=}` | Mint a key for debugging. Nothing is stored and the node is never contacted. |
| `tmesis:fleet:join-token {name} {--role=} {--label=*}` | Mint a one-time fleet join token and print it ONCE with the exact `tmesis join` lines. Only its hash is stored. |
| `tmesis:fleet:check-stale` | Mark fleet nodes silent past `stale_after_seconds` stale (`FleetNodeStale`, once per streak) and expire commands no heartbeat delivered. Schedule every minute. |
| `tmesis:fleet:prune` | Delete acked or expired fleet commands and spent join tokens past `fleet.tombstone_days` (14 by default). Nodes are never deleted. Schedule daily. |
| `tmesis:webhooks:prune` | Delete processed deliveries stored longer than the dedupe retention (at least 14 days while the fleet is on); never an unprocessed row. Database driver only (the cache expires its ids, the table driver never deletes); prints the count. Schedule daily. |
| `tmesis:webhooks:retry {--id=*} {--older-than=60} {--limit=100} {--node=}` | Dispatch stored deliveries whose dispatch failed or never ran, oldest first, under a lease per row. Database and table drivers; fails if any retry fails again. |

## Polling node health

A node enrolled in the fleet heartbeats since tmesis `v0.7.0` (`lastHeartbeatAt`,
`tmesis:fleet:check-stale` and `FleetNodeStale` cover it). A node outside the fleet
sends no heartbeat, so an app that needs "is this node up" for those polls.
`tmesis:health --emit` dispatches one
`AlexHackney\Tmesis\Health\NodeProbed` per node it calls: `name`, `reachable`, `health` (the
`HealthFull`, null when unreachable), `errorClass` and `errorCode` (the envelope's
`error.code`, null for a refused connection or a timeout), `elapsedMs` and `probedAt`
(UTC). Receive-only nodes are skipped as before and emit nothing, and a failure of
the app's own configuration (a blank token, an unknown name) is not emitted as a down
node. Exit code and table are unchanged. It is not in `Events\`, because that
namespace means a webhook, and it is stateless: the listener decides what a
transition is.

Schedule it well under your staleness cutoff (every 30 s against a 90 s cutoff gives
two misses of slack), and never let two runs overlap:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tmesis:health --all --emit')->everyThirtySeconds()->withoutOverlapping();
```

```php
use AlexHackney\Tmesis\Health\NodeProbed;

Event::listen(NodeProbed::class, function (NodeProbed $probe): void {
    $server = IngestServer::firstWhere('hostname', $probe->name);

    if ($server === null) {
        return;
    }

    $server->forceFill([
        // reachable is "it answered"; ready() is "it will take a publish".
        'is_online' => $probe->reachable && $probe->health?->ready() === true,
        'last_heartbeat_at' => $probe->reachable ? $probe->probedAt : $server->last_heartbeat_at,
    ])->save();
});
```

The reader then treats a `last_heartbeat_at` older than the cutoff as offline, so a
scheduler that stops running reads as every node going stale, not as every node up.
