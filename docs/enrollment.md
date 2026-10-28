# Enrolling nodes

A new box needs four secrets of its own (admin token, webhook secret, hook secret and,
since tmesis `v0.8.0`, keyterms hook secret), a config that points it back at this app,
and a way to tell the app it is up.
`tmesis:enroll-node` does the first two, and three routes do the rest:

```sh
php artisan tmesis:enroll-node ny.in.tsp24.com --role=edge --region=ny --out=config.yml
```

1. Mints the admin token and the webhook secret (32 random bytes each, base64url), plus
   a distinct hook secret and keyterms hook secret when asked (`--distinct-*`), and a
   one-use bootstrap token that expires after
   `TMESIS_ENROLLMENT_BOOTSTRAP_TTL_MINUTES` (15). The hook
   secret IS the webhook secret unless you pass `--distinct-hook-secret`, and the
   keyterms hook secret is too unless you pass `--distinct-keyterms-secret`; the grain
   is per box either way, and the node registry falls back to the webhook secret for
   either when a row keeps no separate one. So with `TableNodeResolver` a distinct flag
   needs its column (`tmesis.nodes_table.columns.hook_secret` or
   `.keyterms_hook_secret`): without one nothing reads the distinct secret back, every
   such hook from the box would be refused, and the command refuses the flag before
   minting anything.
2. Renders the role's template (below), and refuses before storing anything when a
   fact is refused or a placeholder is unfilled. A user-data render writes each secret
   the way the node's own interpolator would at that spot (escaped inside double
   quotes, quoted as a bare scalar that needs it), so a secret holding `#`, `"`, `\`
   or `: ` reads back as itself; one it cannot place exactly (inside a block scalar,
   or part of an unquoted scalar) is refused by name. A supplied
   `TMESIS_SRT_PASSPHRASE` outside SRT's 10 to 79 bytes is refused too.
3. Hands the enrolment to your `NodeRepository`. `--replace` re-mints an enrolled name
   (rotation, or a box that missed its token's window) and clears its registration;
   without it an enrolled name is refused.
4. Writes the document to `--out` (mode `0600`), or prints it: a config document
   straight away, a user-data document (it carries the secrets) under the "shown
   once" line. Then prints the `.env` block, the bootstrap token and, with the routes
   on, the install command. Nothing of it goes to the log. Store it now.

The name is the box's hostname: the `X-Tmesis-Node` it signs with and the name the
node registry resolves it by.

**The seams are yours.** Nothing table-backed ships, because the app owns its
rows (the `TransferSigner` precedent). Bind them in a service provider's `register()`,
or name the classes in `TMESIS_ENROLLMENT_REPOSITORY`, `TMESIS_ENROLLMENT_FACTS` and
(optional) `TMESIS_ENROLLMENT_SECRETS`:

```php
namespace AlexHackney\Tmesis\Enrollment;

interface NodeRepository
{
    public function enroll(NodeEnrollment $node, bool $replace = false): void;       // replace clears the registration
    public function pending(string $bootstrapTokenSha256): ?NodeEnrollment;         // expired ones too
    public function register(string $bootstrapTokenSha256, NodeRegistration $registration): bool;   // compare-and-set, burns the token
}

interface EnrollmentFacts
{
    /** @return array<string, scalar|list<scalar>> NON-secret values, keyed by config path (tmesis v0.8.0) */
    public function values(NodeEnrollment $node): array;

    /** @return array<string, string> `${NAME}` values, for your own template only */
    public function placeholders(NodeEnrollment $node): array;
}

interface EnrollmentSecrets   // OPTIONAL (tmesis v0.6.0)
{
    /** @return array<string, string> ConfigRenderer::SUPPLIED_SECRETS names only (six, listed below) */
    public function secrets(NodeEnrollment $node): array;
}
```

`EnrollmentSecrets` supplies the six reserved secrets the package does not mint: the
transcription provider's key `TMESIS_TRANSCRIPTION_API_KEY` (origin), the both-mode
live destination's access and secret key, (tmesis `v0.8.0`) the SRT listener's
passphrase, and the batch and fallback transcription providers' keys
`TMESIS_TRANSCRIPTION_BATCH_API_KEY` (tmesis `v0.9.1`) and
`TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (`v0.9.2`). They go into a
user-data render and the `.env` block; any other name, or a value that is not a
string, is refused naming the entry and never the value. Leave it unset, or leave a
name out, and the template's own `${NAME:-}` fallback applies, which is empty in every
shipped template; an empty live profile is dropped at load, so
that is a box with no live destination, not a broken one. No template names
`TMESIS_SRT_PASSPHRASE`: it reaches the box as a `.env` line only (or through your own
template), and the package never mints it, because your publishers have to be told
it. A box with none keeps the shipped `ingest.srt.encryption: refuse`. Since tmesis
`v0.9.3` the origin template carries
`transcription.batch_api_key: "${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}"` and
`transcription.fallback_api_key: "${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}"` (tmesis
`CONFIG.md` 6.8), so the two keys behave like `TMESIS_TRANSCRIPTION_API_KEY`: left in
place for the `.env` in a config render, written into a user-data render. Under a
`self_hosted` batch role (`transcription.batch_provider`, empty following `provider`) or
a `self_hosted` fallback role the key renders EMPTY in both modes, whatever the seam
answers, because the node refuses a key for its own sidecar; `TMESIS_TRANSCRIPTION_API_KEY`
does the same under `provider: self_hosted`. The roles are read from the document's own
`transcription` block with its non-secret placeholders filled; a config embedded in a block
scalar (a cloud-init `write_files` `content: |`), or a role the renderer cannot read
exactly, is not detected, and the key is placed as before (the node refuses the load only
if that role is in fact `self_hosted`). A fallback that is the batch
provider inherits `batch_api_key` and needs no key of its own (tmesis `DEPLOY.md` 5.5c
and 5.5d).

**`register()` is a compare-and-set on the token, never a write by name.** The route
looks the enrolment up by token, checks the signature, then registers; a
`tmesis:enroll-node --replace` in between must not let the old box register against
(and burn the token of) the replacement. So register the PENDING row that still holds
that sha and burn it in the same statement, and answer whether it did:

```sql
UPDATE ingest_servers
   SET registered_at = ?, version = ?, api_url = ?, bootstrap_token_sha256 = NULL
 WHERE bootstrap_token_sha256 = ? AND registered_at IS NULL
```

Never look the row up first. `false` (no row touched) is answered with the same
`404 unknown_token` as an unknown token.

A `NodeEnrollment`'s secrets come out through `adminToken()`, `webhookSecret()`,
`hookSecret()` and `keytermsHookSecret()` only (a repository that keeps no keyterms
column rebuilds with the constructor's last argument left out, and gets the webhook
secret). Store them encrypted (the table resolver reads `admin_token`
through the encrypter; the register route needs `webhookSecret()` back from
`pending()`), and keep only `bootstrapTokenSha256`: the plain token is never on the
object. It redacts itself in `var_dump()`, `print_r()`, `dump()`, an array cast,
`var_export()` and `json_encode()`, and `serialize()` throws, so it cannot be queued or
cached by accident. `AlexHackney\Tmesis\Testing\ArrayNodeRepository::fake()` is the in-memory
one, with `assertEnrolled()`, `assertRegistered()` and `enrolled()`.

**The templates are the contract's (tmesis `v0.6.0`).** The four
`config.<role>.yml` are contract artifacts, copied at repin into
`tests/Fixtures/contract/docs/contract/templates/` with their `MD5SUMS` (the provenance
leg verifies both), and they are the default. Set `TMESIS_ENROLLMENT_TEMPLATES` to a
directory of your own (`cloud-init.<role>.yml` is tried first, then
`config.<role>.yml`) or pass `--template=` a file to bring your own; a set directory
that does not exist is no template, never a silent fall back to the bundle. With no
`EnrollmentFacts` configured the command prints the `.env` block alone.

**Values, not placeholders (tmesis `v0.8.0`).** The bundled templates interpolate
SECRETS only; every other setting is a plain YAML value (`node.name: ""`,
`webhooks: { url: "", secret: "${TMESIS_WEBHOOK_SECRET}" }`). So `values()` answers
facts keyed by CONFIG PATH, dot-separated keys and sequence indices, and each one SETS
the value at that key. The text is edited in place, never round-tripped through a YAML
dumper: every comment and line of the template survives, and only the values set
change. Branch on the role, because the templates differ:

```php
public function values(NodeEnrollment $node): array
{
    // Every role's template carries these two.
    $values = [
        'api.listen' => ':7443',
        'api.tls.domain' => $node->name,
    ];
    $webhooks = 'https://app.example.com/api/webhooks/tmesis';
    $publishHook = 'https://app.example.com/api/webhooks/hooks/publish';

    return match ($node->role) {
        NodeRole::Origin => $values + [
            'webhooks.url' => $webhooks,
            'ingest.verify_hook.url' => $publishHook,
            'delivery.origin.domain' => 'live.tsp24.com',
            'transcription.provider' => 'deepgram',
            'transcription.keyterms_hook.url' => 'https://app.example.com/api/webhooks/hooks/keyterms',
            'preview.access' => 'granted',
            'preview.ice.public_ips' => ['203.0.113.7'],
        ],
        NodeRole::Edge => $values + [
            'webhooks.url' => $webhooks,
            'ingest.verify_hook.url' => $publishHook,
            'relays.0.url' => 'rtmp://origin-atl-01.tsp24.com:1935/src',
        ],
        // No publish hook on a transcoder.
        NodeRole::Transcoder => $values + ['webhooks.url' => $webhooks],
        // A standalone box reports to no control plane: its template has no
        // webhooks.url, no verify hook and no node.name.
        NodeRole::Standalone => $values + ['delivery.origin.domain' => 'live.example.com'],
    };
}
```

Which of those paths each bundled template carries, at tmesis `v0.9.3` (the origin template
gained the `transcription` batch and fallback keys at `v0.9.3`; the others did not change from
`v0.9.0`):

| Path | edge | origin | standalone | transcoder |
|---|---|---|---|---|
| `api.listen`, `api.tls.domain` | yes | yes | yes | yes |
| `node.name` (filled from the enrolment) | yes | yes | no | yes |
| `webhooks.url` | yes | yes | no | yes |
| `ingest.verify_hook.url` | yes | yes | no | no |
| `relays.0.url` | yes | no | no | no |
| `delivery.origin.domain`, `preview.ice.public_ips` | no | yes | yes | no |
| `transcription.*`, `preview.access` | no | yes | no | no |

- **A fact is never dropped.** A path the role's template does not carry is refused
  by name (`transcription.keyterms_hook.url` on an edge, a typo), and so is a
  `placeholders()` name no document uses: a v0.7-shaped facts class
  (`TMESIS_WEBHOOK_URL`, `TMESIS_NODE_NAME`) against a v0.8 template fails the render
  instead of shipping an empty `webhooks.url`.
- A path that holds a mapping or a block sequence is refused (set the values under
  it), and so is one whose template value names a reserved secret (`webhooks.secret`,
  `api.admin_token`): secrets reach the box through its `.env`.
- A value is a string, int, float, bool or a list of those. Strings are written
  double-quoted, with every character the node's YAML parser (`yaml.v3`) would refuse
  or fold (controls, DEL, the C1 block including NEL, U+2028/U+2029, U+FFFE/U+FFFF)
  escaped and printable Unicode kept; lists as a flow sequence. A string carrying `${`
  or invalid UTF-8 is refused.
- Anything the renderer cannot read EXACTLY is opaque and refused by name, never
  edited by a guess: a multi-line scalar, a block scalar, a nested block sequence, an
  anchor, alias or tag, a flow collection it cannot parse, a duplicated key, and
  everything under one. Values in a second YAML document are not carried (the node
  reads the first).
- `node.name` is filled with the enrolment's name wherever the template carries it as
  a plain value and no fact sets it. The standalone template carries no `node.name`.
- `ConfigRenderer::templateCarries($role, $path)` says whether a role's template
  carries a value; `templateUses($role, $name)` still answers for a `${NAME}`.

`placeholders()` is for YOUR OWN template (`TMESIS_ENROLLMENT_TEMPLATES` or
`--template`) that still interpolates non-secrets, which keeps working: the node's
interpolation is unchanged.

**One facts class serves both documents.** Which answer a template takes comes from
its FILE name (`TemplateKind::forPath()`), never from the render mode:

- a `config.<role>.yml` (bundled, or your override) is a **config document**:
  `values()` are set in it, and a `placeholders()` name it does not use is left to
  your own template, refused only when the role has none
  (`ConfigRenderer::hasAppTemplate()`), so a fact no document uses still fails;
- a `cloud-init.<role>.yml` is an **app template**: `placeholders()` fill it and one
  it does not use is refused, and `values()` are not applied;
- any other file name (a one-off `--template` file) is the only document of that
  render, so it takes both answers and is held to both checks.

So the two-document flow below (`enroll/install` serves your cloud-init,
`enroll/config` the role's config) takes ONE `EnrollmentFacts` answering both.
`--mode=user-data` over a `config.<role>.yml` is still a config document.

The command's render mode is the file's by default and the caller's when asked.
`cloud-init.<role>.yml` is **user-data**: its `write_files` is what puts `/etc/tmesis/.env` on the box, so the
secrets are substituted into it (tmesis WP7: they leave this app only into the box's
own user-data). `config.<role>.yml` is **config**: the secrets stay as `${...}` for the
node to read from its `.env`, which is what the bootstrap in DEPLOY.md 5.6 does (it
writes `.env` first, then this file). The contract ships no cloud-init, so
`--mode=user-data` (or `renderRole(..., mode: RenderMode::UserData)`) is how a shipped
template is rendered with its secrets in when explicitly requested by the caller;
the install route never treats a config template as user-data. Each `${NAME}` or `${NAME:-default}` is:

| Placeholder | Config | User-data |
|---|---|---|
| `TMESIS_ADMIN_TOKEN`, `TMESIS_WEBHOOK_SECRET`, `TMESIS_HOOK_SECRET`, `TMESIS_KEYTERMS_HOOK_SECRET` (since `v0.8.0`), `TMESIS_STREAM_KEY_SECRET`, `TMESIS_BOOTSTRAP_TOKEN`, `TMESIS_FLEET_JOIN_TOKEN` (reserved: a fact with one of these names is refused) | left in place | the box's value; the stream key secret is `tmesis.keys.secret` (blank is refused), the bootstrap token the one just minted |
| `TMESIS_TRANSCRIPTION_API_KEY`, `TMESIS_LIVE_ACCESS_KEY`, `TMESIS_LIVE_SECRET_KEY`, `TMESIS_SRT_PASSPHRASE` (since `v0.8.0`), `TMESIS_TRANSCRIPTION_BATCH_API_KEY` and `TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (since `0.9.2`) (reserved: refused as facts) | left in place; the three `TMESIS_TRANSCRIPTION_*_API_KEY` render empty under their own `self_hosted` role (since `0.9.3`) | what `EnrollmentSecrets` answers, else the `:-default`, else refused; the three transcription keys empty under their own `self_hosted` role (since `0.9.3`) |
| a key of your `placeholders()` (one an app template does not use is refused; see above for a config document) | that value | that value |
| `TMESIS_ENROLL_REGISTER_URL`, `TMESIS_ENROLL_INSTALL_URL`, `TMESIS_ENROLL_CONFIG_URL` | the route URL while the enrolment routes are on (a fact overrides it) | the same |
| `TMESIS_FLEET_ENROLL_URL` | the fleet enrol URL while the fleet routes are on (a fact overrides it) | the same |
| anything else with a `:-default` | the default | the default |
| anything else | refused, naming it | refused, naming it |

A render is a `RenderedDocument`, never a string. It holds the text the way the
enrolment holds its secrets (no dump, cast, export or encoding shows it, `serialize()`
throws, no `__toString`), and `reveal()` is for the file, the one print and the
install response only.

`TMESIS_STREAM_KEY_SECRET` in the `.env` block is this app's `tmesis.keys.secret`,
fleet-wide rather than per box (publish keys are self-validating); when it is blank the
line is a comment saying so.

For a bootstrap that fetches files, **install serves user-data only when a configured
`cloud-init.<role>.yml` exists** (including the secrets for the box's 0600 `.env`).
There is no bundled cloud-init template, so with the package defaults install returns
`404 no_template`; provide a cloud-init template to use this route. **Config is fetched separately** from
`GET {prefix}/enroll/config`. Config always uses the bundled `config.<role>.yml`,
regardless of `TMESIS_ENROLLMENT_TEMPLATES` or a cloud-init override, and renders in
config mode: your `values()` and the box's `node.name` written in at their keys (since
tmesis `v0.8.0`), and `${TMESIS_ADMIN_TOKEN}` and the other reserved secrets left
literal for the node to read from `.env`. Never write a user-data render to the
node's 0644 `config.yml`. Both fetches require the same bearer
bootstrap token and leave it pending until signed registration.

**The routes**, off unless `TMESIS_ENROLLMENT_ENABLED=true`, under the receiver's
prefix and middleware (so `api/webhooks/enroll/*` with a typical `api/webhooks` prefix).

| Route | Name | Takes | Answers |
|---|---|---|---|
| `GET {prefix}/enroll/install` | `tmesis.enroll.install` | `Authorization: Bearer <token>` (a `?token=` is ignored) | configured `cloud-init.<role>.yml` user-data as `text/x-shellscript`, `Cache-Control: no-store`, token NOT burned; `404 no_template` when only config templates exist (including the bundle) |
| `GET {prefix}/enroll/config` | `tmesis.enroll.config` | `Authorization: Bearer <token>` (a `?token=` is ignored) | bundled role config in Config mode as `text/yaml; charset=utf-8`, `Cache-Control: no-store`, token NOT burned; `404 no_template` without a bundled template |
| `POST {prefix}/enroll/register` | `tmesis.enroll.register` | body `{token, ...}` plus what `tmesis version --json` reports (`version`, and `api_listen`, `api_scheme`, `api_domain`), or an explicit `api_url`; optional `doctor` and `bench` objects; signed like a webhook with the box's own webhook secret | `204` and `register()`, which burns the token (`404 unknown_token` when the compare-and-set finds no pending enrolment for it); `401 invalid_signature` (token untouched); `422 validation_failed` for a missing `version`, a non-`http(s)` `api_url`, an `http` `api_url` to a host that is not loopback outside `local` and `testing`, an `api_scheme` other than `http`/`https`, or a field of the wrong type (token untouched) |

An unknown, a burned and an expired token all answer the same
`404 {error:{code:"unknown_token"}}`, so a caller cannot tell which tokens exist.

The token names the enrolment; the signature proves the caller is the box. Someone
who saw the token in transit cannot register a host of their choosing, because the
webhook secret exists only here and in the box's user-data. The signature is the
webhook function (`sha256=` + hex HMAC-SHA256 of `timestamp.body` over the exact bytes
sent, `X-Tmesis-Timestamp` within 300 seconds), so the box can sign with `openssl`
once cloud-init has written its `.env`:

```sh
. /etc/tmesis/.env
ts=$(date +%s)
# The version report as it is, plus the token (and, optionally, the doctor report).
body=$(tmesis version --json | jq -c --arg t "$TMESIS_BOOTSTRAP_TOKEN" '. + {token: $t}')
sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$TMESIS_WEBHOOK_SECRET" | awk '{print $NF}')"
curl -fsS -X POST -H "X-Tmesis-Timestamp: $ts" -H "X-Tmesis-Signature: $sig" \
  -H 'Content-Type: application/json' --data "$body" "$TMESIS_ENROLL_REGISTER_URL"
```

(`TMESIS_BOOTSTRAP_TOKEN` and `TMESIS_ENROLL_REGISTER_URL` are whatever your bootstrap
writes; both placeholders are filled for a template that names them.)

**The admin URL is derived, never guessed** (DEPLOY.md 5.6, "Registering back"):

| The box reports | Registered `apiUrl` |
|---|---|
| an `api_url` | that, verbatim (a hand-run bootstrap, or a file-pair box whose certificate names a host `api_listen` does not); outside `local` and `testing` an `http` one to a host that is not loopback is `422 validation_failed` naming `api_url`, token untouched |
| `api_domain` (the ACME path) | `https://<api_domain>:<port of api_listen>`: only the certificate's name verifies |
| an `api_listen` naming a host, and `api_scheme` `https` | `https://<host>:<port>` |
| an `api_listen` naming a host, and `api_scheme` `http` | `http://<host>:<port>` in `local` and `testing` only; anywhere else `null` (RECEIVE-ONLY), because every admin call to it would be refused (see "TLS on the admin API") |
| `:7000`, a wildcard, a loopback address, or nothing (no config loaded) | `null`: the node is RECEIVE-ONLY, its webhooks and hooks work and an admin call throws naming the missing url, exactly as a resolver row with no url |

`NodeRegistration::isReceiveOnly()` says which, and `$registration->doctor` and
`->bench` carry the bootstrap's `tmesis doctor` report and `tmesis bench --profile job`
result, untouched and unmodelled, or null when it sent none. The bootstrap records
them with the registration and never gates on them.
