# The publish veto

The node can call your app on the publish HANDSHAKE, before any media has moved, and
refuse the publish if you say so. It is **off at both ends** by default, and it is a
correctness tool rather than a security boundary: the signature is the boundary.

```php
// config/tmesis.php
'hooks' => ['enabled' => true, 'decider' => App\Tmesis\EntitlementDecider::class],
```

```php
use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;

class EntitlementDecider implements PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision
    {
        // BRANCH ON key_type. `signed` means the node already proved the key;
        // `bare_unproven` means nothing local vouches for the publisher at all.
        if ($request->keyType() === HookRequest::KEY_TYPE_BARE_UNPROVEN) {
            return HookDecision::deny('unproven key');
        }

        return $this->show($request->stream())?->isCancelled()
            ? HookDecision::deny('this show was cancelled')
            : HookDecision::allow(patch: ['ladder' => 'broadcast-720p']);
    }
}
```

**The node holds the handshake open under a 500 ms cap while your decider runs**, so
read something you already have: a cached entitlement, a column you can fetch by
stream id, a flag. Do not call another service.

Five things the endpoint will never do, because each makes the node read something
other than your decision:

| Never | Why |
|---|---|
| an empty body | it carries no JSON document, so the node falls back to its `on_timeout` posture, whatever your decider meant |
| `200 {}` | it decodes, and a decision with no `allow` **is a deny** |
| a 5xx for a decision | the node treats any non-2xx as a timeout (`on_timeout`): one broken deploy turns the veto off, or under `on_timeout: deny` blacks out every publish |
| I/O on the default path | the cap is a cap, not a target |
| a listener in the request | `PublishHookDecided` is dispatched after the response has been sent |

**The response is contract (tmesis `v0.6.0`).** What the endpoint answers is
`publish.verify.response.schema.json` (`allow` required, `reason` and `patch` optional,
nothing else), with a generated example and hand-written worked responses in
`test/vectors/hook.json`. The `hook-endpoint` leg validates every `HookDecision` shape
against the schema, and the `vectors-hook` leg reproduces each vector body through
`HookDecision`. `PendingHook::decision()` validates what it decodes whenever
`opis/json-schema` is installed, so a consumer test fails on an answer the node would
not accept. The runtime never validates on the 500 ms path.

A decider that throws is caught, logged, and answered with the `hooks.on_error`
posture and a `200`. A decider slower than `hooks.slow_ms` logs a warning naming the
class. Point the node at `POST {webhooks.prefix}/hooks/publish` and set its
`ingest.verify_hook.secret`; leave `nodes.*.hook_secret` blank if the node signs both
with one secret.

Several nodes, and nodes that are not in config at all, are covered in
[Many nodes](./configuration.md#many-nodes): for a fleet prefer a node resolver, because `Tmesis::connect()`
is for outgoing calls only and the receiver never sees those nodes.

```php
use AlexHackney\Tmesis\Data\Node\NodeConfig;

Tmesis::node('origin-atl-01')->getHealth();

// A one-off node: URL and token from anywhere. https, or loopback: the bearer is
// refused in clear outside local and testing.
Tmesis::connect(new NodeConfig('discovered-01', 'https://discovered-01.example.com:7000', $token))->getHealth();
```

## The edge control channel: a patch on the handshake

An `allow` can carry a `patch`, and the patch IS a stream PATCH body (the node decodes
it into its own `StreamSpec`, unknown fields refused), so build it with the same
`StreamSpec` the admin writes use. On an UNPROVISIONED stream the node applies
`labels` and `relays` through CreateStream: that is how an edge learns, at publish
time, which show a key belongs to and which origin to forward it to, with no admin
call from the control plane.

```php
use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EdgeRoutingDecider implements PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision
    {
        // Filled AHEAD of the publish (when the show is scheduled, when the origin
        // changes), never computed here: the node gives this 500 ms in all.
        $route = Cache::get("tmesis-route:{$request->stream()}");

        if ($route === null) {
            return HookDecision::allow();   // no route known: publish, no patch
        }

        $decision = HookDecision::allow(patch: StreamSpec::make()
            ->labels(['show' => $route['show'], 'site' => $route['site']])
            ->relays([RelayInput::make("rtmp://{$route['origin']}:1935/src", 'origin')]));

        // The relay URL is a credential. Log the redacted form, never toArray().
        Log::info('tmesis edge route', $decision->toRedactedArray());

        return $decision;
    }
}
```

It renders as `{"allow": true, "patch": {"labels": {"show": ..., "site": ...},
"relays": [{"url": "rtmp://<origin>:1935/src", "id": "origin"}]}}`; `labels([])` goes
out as `{}`, as it does on `updateStream()`. An array patch still passes through
verbatim, for a field the builder does not know yet.

- **How you know it landed:** `relay.started` for `origin` on the session that
  follows, matched with `$event->destination()->matches('origin')` rather than
  `id()`: since tmesis `v0.6.0` a provisioned destination whose URL and feed equal a
  static relay's is folded into that leg, so the event says `id: static-0` and names
  `origin` in `aliases`. A patch the node cannot apply never fails the publish, so
  the absence of that event is the only signal.
- **Reconnects need nothing:** a reconnect on the same stream id restarts the
  record's relays with no second patch (`docs/API.md` 9.9c).
- **Audit through `PublishHookDecided::redactedDecision()`**, never
  `$event->decision->toArray()`: the event is dispatched after the answer, and the
  package logs no decision itself.

**The outage posture, plainly (tmesis `v0.6.0`, #141 option B, shipped).** When the
hook times out or the node cannot reach your app:

| Key | What the node does |
|---|---|
| a signed valid key, or a bare key in `dual_accept.map` | takes `on_timeout` as configured |
| a bare unproven key on an OPEN node | takes `on_timeout` too (it was a forced deny through `v0.5.0`), logs a WARN and counts `tmesis_hook_outage_allowed_total{stream}` |
| a bare unproven key on a CLOSED node | never reaches the hook: refused before it |
| a revoked key, or one below `min_key_epoch` | never reaches the hook: refused before it |

So a control-plane outage on an OPEN edge with `on_timeout: allow` no longer blocks
legacy (bare) keys; it lets them through, and the counter is how you see that it
happened. The decider's availability is still a production concern: an outage is a
window where your entitlement check did not run.

## Errors

Every non-2xx becomes a named exception, mapped on `error.code` in one place.

```php
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;

try {
    Tmesis::node()->getStream('show-2041-main');
} catch (NotFoundException $e) {
    // A real 404. From M1 on, "not built yet" is a 501, never a 404.
} catch (NotImplementedException $e) {
    $e->milestone();   // "M3": this route arrives at that milestone
} catch (UnavailableException $e) {
    $e->code();        // draining, not_ready, journal_unavailable, master_unavailable;
                       // encoder_unavailable throws EncoderUnavailableException, a subclass
    $e->retryAfter();  // from Retry-After, which the admin listener does not send at this pin: null
}
```

A `422 validation_failed` on `segment_seconds` can be the segment grid as well as the
range (since tmesis `v0.2.0`: the effective value must be a multiple of every video
rung's `keyframe_seconds` on the effective ladder, and the node's message names the
rung), and on a `PATCH` that only changed `ladder` the `details[].path` is `ladder`;
the package checks the range and never the grid, because only the node knows its
ladders.

**Enum-typed fields are exposed as strings**, with the known values as class constants.
The value sets are open and additive, so a backed enum's `from()` would throw on the
day a new value ships.

# The keyterms hook

Since tmesis `v0.8.0` a captioning node can ask your app for a stream's VOCABULARY (a
show's fight card: fighter names, promotion, venue, city), so it reaches the live
captions without anyone PATCHing the stream (`docs/WEBHOOKS.md` 13). The node GETs it at
the session's open and every `transcription.keyterms_hook.interval_seconds` (30) after,
under a 2000 ms timeout, and never waits: captions open with the stream's own
`captions.keyterms` and are never interrupted. Off at both ends by default.

```php
// config/tmesis.php, or TMESIS_KEYTERMS_HOOK_ENABLED=true and TMESIS_KEYTERMS_PROVIDER
'hooks' => ['keyterms' => ['enabled' => true, 'provider' => App\Tmesis\FightCardKeyterms::class]],
```

```php
use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Hooks\KeytermsProvider;

class FightCardKeyterms implements KeytermsProvider
{
    public function keyterms(KeytermsHookRequest $request): array
    {
        // $request->node, ->stream, ->session, ->lang, ->deliveryId
        return Show::forStream($request->stream)?->keyterms() ?? [];
    }
}
```

Point the origin's `transcription.keyterms_hook.url` at `GET {prefix}/hooks/keyterms`
(`tmesis.hooks.keyterms`; a `values()` fact sets it at enrolment). The route verifies the
node's signature with its KEYTERMS hook secret (`keyterms_hook_secret` on the node,
falling back to `webhook_secret`; `TMESIS_KEYTERMS_HOOK_SECRET` on the box) over the
timestamp and an EMPTY body, through the receiver's one verifier and its 300 second
window. The query (`stream`, `session`, `lang`) is not covered by the signature: it
names show data, and the signature proves only that the node asked. Nothing is stored,
deduped or dispatched.

**The route is registered even when `hooks.keyterms.enabled` is false**, because the
node reads a `404` (and a `204`) as "no keyterms" and clears every session's list. Off,
it still verifies the signature (an unsigned caller gets the receiver's `401` and
learns nothing) and answers a signed request `503 keyterms_disabled`, which keeps the
node's list in force; the switch is read per request. For the same reason, in a
multi-tenant host the path must be TENANTLESS: a host-level `404` (an unknown tenant, a
domain route that does not match the node's request) clears the list too. Route
`{prefix}/hooks/keyterms` around your tenancy, as you do the receiver.

**Whatever you answer REPLACES the stream's `captions.keyterms` for that session**
(tmesis decision D14), including none, and a later PATCH does not displace it. So answer
the whole list, and know that the default `NoKeyterms` provider answers none: with the
node's hook on and no provider of yours bound, every captioning stream runs with no
keyterms whatever it was PATCHed with. `tmesis:doctor` fails an enabled route that still
has `NoKeyterms`: bind a provider, or leave the route off.

| Your provider | The route answers | The node |
|---|---|---|
| returns a list | `200 {"keyterms": [...]}`, each term trimmed and case-insensitive duplicates dropped (first spelling, order kept), as the node would | applies it if it differs from the list in force |
| returns `[]` | `200 {"keyterms": []}` | none, for the session |
| returns a list the node would ignore WHOLE (more than 100 terms, an empty one, one over 50 characters after trimming, a control character, a non-string; `Support\Keyterms`, the rule's one home) | `500 invalid_keyterms`, and an `InvalidKeytermsException` is REPORTED with the node's own sentence (`keyterms[3] is 63 characters, at most 50`) | keeps the list in force |
| throws (or is not a `KeytermsProvider`) | `503 keyterms_unavailable`, logged with the provider and the exception CLASS, never the message | keeps the list in force |
| is never asked: `hooks.keyterms.enabled` is false | `503 keyterms_disabled`, logged with the node and the delivery | keeps the list in force |

The provider's own token cap (Deepgram's, about 500) is the node's to judge: a list over
it is ignored whole there, with a `caption.keyterms_hook_failed` row on the stream's
timeline. A wrong `X-Tmesis-Event` or a query missing a field is `400`.
