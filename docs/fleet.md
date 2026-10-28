# The fleet control plane

Since tmesis `v0.7.0` a node can ENROLL with this app once (`tmesis join`) and then
HEARTBEAT to it: every 10 seconds it reports its state and events, and the answer
carries what it should run (desired state) and what it should do (commands). The node
always calls out; a box behind NAT is reached only through the answer to its own
heartbeat. Webhooks keep flowing beside it, and every event dispatches ONCE whichever
channel carries it first.

## Turning it on

```dotenv
TMESIS_FLEET_ENABLED=true
TMESIS_FLEET_PROVIDER=App\Fleet\MyDesiredState   # or bind AlexHackney\Tmesis\Fleet\DesiredStateProvider
TMESIS_DEDUPE_DRIVER=database                    # or table on a durable cache store, with dedupe.table.columns.delivery set
```

Run the migrations first (`php artisan vendor:publish --tag=tmesis-migrations`, then
`migrate`): the fleet keeps its own tables (`tmesis_fleet_*`). Enabling is REFUSED at
boot, naming the key, on the `cache` dedupe driver (the fleet acks an event only after
recording its id durably), without a provider, or with a value under a contract floor.
Three routes open under `webhooks.prefix`, each authenticated by a bearer token:

| Route | Token | What |
|---|---|---|
| `POST {prefix}/fleet/enroll` | the one-time JOIN token | enrol; the answer carries the node id, the fleet token and the heartbeat URL |
| `POST {prefix}/fleet/heartbeat` | the node's FLEET token | a heartbeat |
| `DELETE {prefix}/fleet/enroll` | the node's fleet token | deregistration (`tmesis leave`) |

The heartbeat URL handed out must be `https` (or `http` on loopback): the node refuses
anything else. An app behind a TLS-terminating proxy has to trust the proxy (or set
`APP_URL` / `URL::forceScheme('https')`), or enrol answers `503 heartbeat_url_insecure`
BEFORE the token is spent. Bodies are up to 4 MiB: the web server's own limit on the
heartbeat path (`client_max_body_size`, `post_max_size`) must allow that.

## Enrolling a box

```php
$token = Tmesis::fleet()->joinToken('ny.in.tsp24.com', role: 'edge');
$token->reveal(); // shown once: only its sha256 is stored
```

or `php artisan tmesis:fleet:join-token ny.in.tsp24.com --role=edge`, which prints the
token and the exact commands. On the box, with the service stopped (join refuses while
the node runs):

```sh
systemctl stop tmesis-update.path tmesis
tmesis join https://app.example.com/webhooks/fleet/enroll --token - < token.txt
chown -R tmesis:tmesis /var/lib/tmesis/bench
systemd-run --wait --pipe --collect --quiet --unit=tmesis-bench \
    --uid=tmesis --gid=tmesis \
    -p EnvironmentFile=-/etc/tmesis/.env \
    -p WorkingDirectory=/var/lib/tmesis \
    -p PrivateTmp=true \
    -p ProtectSystem=full \
    -p ProtectHome=true \
    -p LimitNOFILE=65535 \
    -p NoNewPrivileges=true \
    -p SystemCallFilter=@system-service \
    -p SystemCallErrorNumber=EPERM \
    -p SystemCallArchitectures=native \
    -p "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK" \
    -p CapabilityBoundingSet=CAP_NET_BIND_SERVICE \
    -p AmbientCapabilities=CAP_NET_BIND_SERVICE \
    -p ProtectKernelTunables=true \
    -p ProtectKernelModules=true \
    -p ProtectControlGroups=true \
    -p RestrictSUIDSGID=true \
    -p LockPersonality=true \
    -p OOMPolicy=continue \
    -p RuntimeMaxSec=5100 \
    /opt/tmesis/current/tmesis bench --profile live --store --if-live --timeout 80m \
    --config /etc/tmesis/config.yml
systemctl is-active tmesis-bench   # must no longer read active
# on a box that runs VOD jobs (a transcoder, or jobs.enabled):
tmesis bench --profile job
systemctl start tmesis tmesis-update.path
```

The bench lines are tmesis `DEPLOY.md` section 6.5's (since tmesis `v0.9.0`): the
chown repairs a bench directory a v0.8.x job bench left root-owned, the live bench runs
as the service through `systemd-run`, and a box started without it has live admission
OFF.

A join token is good for 60 minutes before its first use. After it, a repeat within 15
minutes (the node's own recovery for a lost answer) returns the SAME node id and a fresh
fleet token, revoking the previous one. The name the fleet knows the box by is the one
the token was minted for; a new join token for a name that is already enrolled
SUPERSEDES the older node.

With the installer bootstrap, `tmesis:enroll-node --fleet` mints the join token into the
user-data as `${TMESIS_FLEET_JOIN_TOKEN}` beside `${TMESIS_FLEET_ENROLL_URL}`, and the
app's `cloud-init.<role>.yml` runs, in this order: `install.sh ... --no-start`, then
`tmesis join "${TMESIS_FLEET_ENROLL_URL}" --token "${TMESIS_FLEET_JOIN_TOKEN}"`, then
(since tmesis `v0.9.0`) the live bench and the job bench, then
`systemctl start tmesis tmesis-update.path`. `--no-start` also skips the installer's
own live bench, which would outlive the 15-minute bootstrap token, so the box is
measured after it joins and before it starts; a box that never runs the live bench has
live admission OFF (every publish admitted). `Enrollment\Bootstrap` holds the printed
lines. The join token rides the user-data only, never `.env` or `config.yml`.

## What each node should run

```php
use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Fleet\{DesiredDocument, DesiredRecording, DesiredRelay, DesiredStateProvider, DesiredStream};

final class MyDesiredState implements DesiredStateProvider
{
    public function desiredFor(FleetNode $node): DesiredDocument
    {
        return DesiredDocument::make()
            ->stream(DesiredStream::make(
                'show-2041-main',
                DesiredRecording::make(enabled: true, retentionHours: 72, destinationRef: 'archive'),
                minKeyEpoch: 6,
                labels: ['show_id' => '2041'],
                relays: [DesiredRelay::make('origin-atl', 'srt://origin-atl-01.internal:8890')],
            ))
            ->revoke('show-2039-rehearsal');
    }
}
```

It is called on enrolment and on EVERY heartbeat, so answer from your own cache. Return
the whole document every time; the package owns the revision: it hashes the canonical
document (key-ordered, under an HMAC with the app key) and moves the revision only when
the content changes. The node gets the full document again whenever its applied
revision OR its revocation list differs from yours (the admin API is a second
revocation feeder and can move the list without a revision). A provider that throws is
logged by class, fires `FleetDesiredStateFailed`, and the node keeps what it last
applied. The document itself is never stored (its relay URLs are credentials).

**A refused revision still carries its revocations.** The node refuses a revision it
cannot apply (`FleetRevisionRejected`, `$node->rejected`) and keeps the streams, relays
and recordings it last applied. On a tmesis `v0.7.0` node the refused revision's
revocation list still applies when that list is itself valid, while the
refusal keeps being reported. Until your provider returns content the node accepts,
every answer re-sends the newest revision, so a revocation added meanwhile, or a digest
drift, still converges; the rest of the document waits until you fix it. The builders
already refuse what the admin API would (an `s3://` destination, both selectors,
retention under an hour), so a refusal is the node's own judgement: a `destination_ref`
the box does not configure, a relay id a static relay holds, a sealed stream with a
live session.

**Pausing a box's transcode jobs before a show (tmesis `v0.9.0`).** The document
carries a told jobs pause: the node claims no new transcode job until `until` (or until
you clear it), running jobs finish, and submissions still queue.

```php
use AlexHackney\Tmesis\Fleet\DesiredJobsPause;

DesiredDocument::make()
    ->stream(...)
    ->jobsPause($show->startsSoon() ? DesiredJobsPause::make('show: fight night 12', $show->endsAt) : null);
```

It is a FULL replacement like everything in the document: absent or null CLEARS the
fleet's pause (and an API pause the box held when it joined), so set it on EVERY
document for as long as it should hold; a document built without it is the resume.
The wire always carries `jobs_pause` (null when none). `reason` is 1 to 200 bytes and
`until` RFC 3339 UTC or null, both refused before encoding, because a bad value makes
the node reject the WHOLE revision, streams included. A pause changes the revision;
a document with no pause hashes as it did before `v0.9.0`, so the upgrade moves no
node's revision. A pre-`0.9.0` node ignores the field.

**Rotating `APP_KEY`:** list the old key in `APP_PREVIOUS_KEYS` for at least one
heartbeat interval. With it, no node's revision moves. Without it, every node's revision
moves ONCE, to identical content.

## Status, events and commands

```php
use AlexHackney\Tmesis\Facades\Tmesis;

foreach (Tmesis::fleet()->nodes() as $node) {
    $node->lastHeartbeatAt; $node->admission; $node->stats; $node->streams;
    $node->converged();   // applied the intended revision with the intended revocation list
    $node->rejected;      // the newest revision it refused, with the JSON path of why
    $node->jobsPaused();  // tmesis v0.9.0: the last heartbeat reported a jobs pause; offer it no jobs
    $node->jobsPause?->until; $node->jobsPause?->source;   // fleet or api
}

$id = Tmesis::fleet()->kick($nodeId, 'show-2041-main');
Tmesis::fleet()->seal($nodeId, 'show-2041-main', graceSeconds: 30); // 0 to 300; the node caps it
Tmesis::fleet()->drain($nodeId);                                      // in place: admission closes, the node stays up
Tmesis::fleet()->update($nodeId, '0.8.1');   // tmesis v0.8.0: install that release; 'v0.8.1' is sent as 0.8.1
Tmesis::fleet()->reloadEnv($nodeId);         // a holding restart that rereads /etc/tmesis/.env
```

`update` and `reload_env` restart the node HOLDING its live windows (a publisher drops
briefly and rejoins the same session). The update command stays unacked while in
flight and the NEW process acks it; it is acked `moot` when the update fails, another
is in flight or 30 minutes pass. **Its ack carries no outcome:** compare the next
heartbeat's `version` with what you asked for, both through
`DatabaseFleet::bareVersion()`: a release build reports the bare version, but a box
built from a git checkout reports `git describe` (`v0.8.2`), and the node itself
compares with the leading `v` trimmed from both sides.

A command is re-sent on every heartbeat until the node acks it (`FleetCommandAcked`).
The ack does not say whether it acted or found it moot (a kick of a stream nobody
publishes): the next heartbeat's `streams` and `admission` are the outcome. A command
that no heartbeat DELIVERED within `TMESIS_FLEET_COMMAND_TTL_SECONDS` (900) expires
(`FleetCommandExpired`); once delivered it never expires. A revocation never disconnects
an attached publisher: pair `revoke()` with a `kick()`.

The package's own observations are under `AlexHackney\Tmesis\Events\Fleet\` and are NOT
`WebhookEvent`s: `FleetNodeEnrolled`, `FleetNodeReenrolled`, `FleetNodeLeft`
(`left` or `superseded`), `FleetHeartbeatReceived` (every beat: queue your listener),
`FleetNodeRestarted`, `FleetAdmissionChanged`, `FleetRevisionApplied`,
`FleetRevisionRejected`, `FleetNodeStale`, `FleetNodeRecovered`, `FleetCommandAcked`,
`FleetCommandExpired`, `FleetDesiredStateFailed`, `FleetJobsPauseChanged` (tmesis `v0.9.0`: the
jobs pause the heartbeat reports moved). The node's own events arrive as the
SAME `WebhookEvent` classes the receiver dispatches; `$event->delivery()->channel` is
`webhook` or `fleet`.

**Dedupe with the fleet on:** every delivery id stays known for 14 days
(`TMESIS_FLEET_TOMBSTONE_DAYS`) whichever channel carried it, because the same event can
arrive over the other channel up to the node's 7-day horizon later. A failed fleet
dispatch is kept and replayed by `tmesis:webhooks:retry` like a webhook one; on the
`table` driver (which keeps no headers) its replay reports the `webhook` channel.

## Scheduling

```php
Schedule::command('tmesis:fleet:check-stale')->everyMinute()->withoutOverlapping(); // FleetNodeStale, and expiry of undelivered commands
Schedule::command('tmesis:fleet:prune')->daily();                                    // settled commands and spent join tokens past 14 days
Schedule::command('tmesis:webhooks:prune')->daily();                                 // processed deliveries; ids kept 14 days while the fleet is on
```

## Leaving

`tmesis leave` on the box deregisters it (`DELETE fleet/enroll`), and only when the box
owes nothing: no unregistered recording, unreleased generation, unfinished transfer,
running job, undelivered webhook, unacked fleet event or live session. With the node
running it needs the node DRAINED first (`Tmesis::fleet()->drain()`); release what the
box holds through `artifacts()` as usual. The node's row is kept (`enrolled()` false,
`FleetNodeLeft`), and its name is free for a new enrolment.
