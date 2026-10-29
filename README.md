# laravel-tmesis

Laravel control-plane client and reference receiver for the Tmesis media node
contract: SRT/RTMP ingest, supervised ffmpeg ABR, first-party HLS packaging, bucket or
origin delivery.

Tmesis is Hackney Enterprises' in-house Go media node; its source is not public. The
contract artifacts this package is built against are bundled under
`tests/Fixtures/contract/`, so the package installs and its test suite runs without the
node.

What you get:

- **A typed admin client** for the node's HTTP API: health, streams, relays, recordings,
  artifacts and signed transfers, preview grants, markers and transcode jobs.
- **Publish keys**, minted here and verified by the node with no call back.
- **A signed webhook receiver** with raw-byte HMAC verification, replay window and
  durable de-duplication, dispatching one typed Laravel event per webhook.
- **The `publish.verify` and keyterms hooks**, so the node can ask your app before it
  accepts a publish.
- **Node enrolment and a fleet control plane** (join tokens, heartbeats, desired state,
  commands).
- **`FakeTmesis`** and friends for testing your own app without a node.
- **A contract suite** that proves all of the above against the node's generated
  artifacts.

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13

## Versioning

**Pinned to Tmesis `v0.9.6`** (the tag; the commit is in
`tests/Fixtures/contract/PROVENANCE.md`). A package release carries the same version
string as the tmesis release whose contract it carries, so `v0.9.6` of this package is
the tmesis `v0.9.6` contract. A package-only change (a fix, a test leg, fixtures) takes
the next PATCH on the line it carries.

Inside tmesis v1 every contract change is additive by policy and the package tolerates
unknown fields everywhere, but a field can change meaning without changing shape
(`ingest.dropped_bytes_total` went from packets to bytes at `v0.9.1`), so read
[`CHANGELOG.md`](CHANGELOG.md) and repin before you upgrade nodes. See
[Bumping the pin](docs/contract-suite.md#bumping-the-pin) and
[Releasing](docs/development.md#releasing).

## Install

```sh
composer require alexhackney/laravel-tmesis
```

Publish the config:

```sh
php artisan vendor:publish --tag=tmesis-config
```

If you are going to receive webhooks on the default `database` driver, publish and
run the migration **before** you turn the route on. The default delivery store is
durable for a reason (see [Durability](docs/webhooks.md#durability-of-the-delivery-store)); an app
that already keeps its webhooks in a table of its own can use the `table` driver
instead and migrate nothing:

```sh
php artisan vendor:publish --tag=tmesis-migrations
php artisan migrate
```

Then check the install:

```sh
php artisan tmesis:doctor
```

## Quick start

Point the default node at your Tmesis box, then read from it and mint a key:

```dotenv
TMESIS_NODE_URL=https://origin-01.example.com:7000
TMESIS_NODE_TOKEN=...
TMESIS_WEBHOOK_SECRET=...
TMESIS_KEY_SECRET=...
TMESIS_WEBHOOKS_ENABLED=true
```

```php
use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use Illuminate\Support\Facades\Event;

$health = Tmesis::node()->getHealth();
$health->status();                  // ok, degraded, unavailable

$key = Tmesis::mint('show-2041-main', KeyEpoch::fromInt(1));
$key->substituteInto('srt://origin-01.example.com:9000?streamid={key}');

// Webhooks arrive at POST /webhooks/tmesis and dispatch typed events.
Event::listen(StreamLive::class, function (StreamLive $event): void {
    // the stream is live on the node
});
```

## Documentation

| Guide | Covers |
|---|---|
| [Configuration](docs/configuration.md) | Every config key and environment variable, many nodes, TLS on the admin API. |
| [Enrolling nodes](docs/enrollment.md) | Role templates, secrets, the enrolment routes, `tmesis:enroll-node`. |
| [The fleet control plane](docs/fleet.md) | Join tokens, heartbeats, desired state, commands. |
| [Publish keys](docs/publish-keys.md) | Minting keys and the three epochs. |
| [Reading from a node](docs/reading.md) | Health, streams, stats, transcripts. |
| [Provisioning and lifecycle writes](docs/writes.md) | Streams, relays, recordings, preview grants and markers. |
| [Artifacts](docs/artifacts.md) | Routing, releasing, masters, signed transfers. |
| [Transcode jobs](docs/transcode-jobs.md) | VOD jobs and pausing them. |
| [Hooks](docs/hooks.md) | The publish veto and the keyterms hook. |
| [Receiving webhooks](docs/webhooks.md) | Events, ordering, middleware, the delivery store. |
| [Testing your app](docs/testing.md) | `FakeTmesis` and the testing doubles. |
| [Commands](docs/commands.md) | Every artisan command. |
| [The contract suite](docs/contract-suite.md) | Provenance, the legs, bumping the pin, the reference receiver. |
| [Development](docs/development.md) | Running the checks and cutting a release. |

## Licence

MIT. See [LICENSE](LICENSE).
