<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * The non-secret values a node's template is filled with, supplied by the app.
 *
 * Bind it in a service provider's register(), or name the class in
 * `tmesis.enrollment.facts` (`TMESIS_ENROLLMENT_FACTS`). Needed only to render a
 * template; enrolment without a template prints the `.env` block alone.
 *
 * NEVER a secret: the reserved `${...}` names reach the box through its `.env`, a
 * placeholder fact named after one is refused, and so is a value fact aimed at a key
 * whose template value names one (`webhooks.secret`, `api.admin_token`).
 *
 * **values() (tmesis `v0.8.0`)**: keyed by CONFIG PATH, dot-separated keys and
 * sequence indices. Each one SETS the plain YAML value at that key; a path the
 * template does not carry is refused by name, never dropped. Branch on
 * `$node->role`: the templates differ (only the origin carries
 * `transcription.keyterms_hook.url`; the edge's relay is `relays.0.url`; the
 * standalone template carries no `webhooks.url`, `ingest.verify_hook.url` or
 * `node.name`, and the transcoder's no `ingest.verify_hook.url`; docs/enrollment.md has
 * the table). A value is
 * a string, int, float, bool or a list of those. An example:
 *
 * ```php
 * public function values(NodeEnrollment $node): array
 * {
 *     $row = IngestServer::where('hostname', $node->name)->firstOrFail();
 *
 *     $values = [                                         // every role carries these
 *         'api.listen' => ':7443',
 *         'api.tls.domain' => $row->dns_name,                 // <region>.in.tsp24.com
 *     ];
 *     $reports = [                                        // not on standalone
 *         'webhooks.url' => 'https://app.example.com/api/webhooks/tmesis',
 *     ];
 *     $hook = [                                           // edge and origin only
 *         'ingest.verify_hook.url' => 'https://app.example.com/api/webhooks/hooks/publish',
 *     ];
 *
 *     return match ($node->role) {
 *         NodeRole::Origin => $values + $reports + $hook + [
 *             'delivery.origin.domain' => 'live.tsp24.com',
 *             'transcription.provider' => 'deepgram',
 *             'transcription.keyterms_hook.url' => 'https://app.example.com/api/webhooks/hooks/keyterms',
 *             'preview.access' => 'granted',
 *             'preview.ice.public_ips' => [$row->public_ip],
 *         ],
 *         NodeRole::Edge => $values + $reports + $hook + [
 *             'relays.0.url' => 'rtmp://origin-atl-01.tsp24.com:1935/src',
 *         ],
 *         NodeRole::Transcoder => $values + $reports,
 *         NodeRole::Standalone => $values,
 *     };
 * }
 * ```
 *
 * `node.name` is filled from the enrolment unless a value sets it.
 *
 * **placeholders()**: keyed by `${NAME}` (without `${` and `}`), for an app's OWN
 * template (`tmesis.enrollment.templates`) that still interpolates non-secrets.
 * Values are strings, rendered verbatim. A name the template does not use is refused.
 * Answer `[]` against the bundled templates, which name secrets only.
 *
 * A relay URL can carry a credential (the relay rule): one that does belongs in the
 * node's `.env` behind an app template's own placeholder, not in a fact.
 */
interface EnrollmentFacts
{
    /**
     * Plain values, keyed by config path (`webhooks.url`, `node.name`).
     *
     * @return array<string, string|int|float|bool|list<string|int|float|bool>>
     */
    public function values(NodeEnrollment $node): array;

    /**
     * `${NAME}` placeholder values, for an app template that still uses them.
     *
     * @return array<string, string>
     */
    public function placeholders(NodeEnrollment $node): array;
}
