<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * The six RESERVED secrets the package does not mint, supplied by the app for a
 * user-data render and the `.env` block (tmesis `v0.6.0`, handover 3.3). OPTIONAL.
 *
 * Bind it in a service provider's register(), or name the class in
 * `tmesis.enrollment.secrets` (`TMESIS_ENROLLMENT_SECRETS`). The names it may answer
 * are ConfigRenderer::SUPPLIED_SECRETS and nothing else:
 *
 *  - `TMESIS_TRANSCRIPTION_API_KEY`, the transcription provider's key (origin only);
 *  - `TMESIS_TRANSCRIPTION_BATCH_API_KEY` (tmesis `v0.9.1`) and
 *    `TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (`v0.9.2`), the batch and fallback
 *    transcription providers' keys. The origin template names both since tmesis
 *    `v0.9.3` (`transcription.batch_api_key: "${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}"`,
 *    likewise `fallback_api_key`), so an origin user-data render carries the supplied
 *    key there, and the `.env` line carries it too. The other bundled role templates
 *    name neither path, so on those roles an app template must name it to carry the
 *    key. A key under a `self_hosted` role renders empty whatever is supplied
 *    (ConfigRenderer::render()), and the package does not check their format. The
 *    box reads a key only where its `config.yml` references it. A fallback that is the
 *    batch provider inherits `batch_api_key`;
 *  - `TMESIS_LIVE_ACCESS_KEY` and `TMESIS_LIVE_SECRET_KEY`, the both-mode live
 *    destination's credential profile (`storage.credentials.live`);
 *  - `TMESIS_SRT_PASSPHRASE` (tmesis `v0.8.0`), the SRT listener's passphrase, one
 *    per box, read when `ingest.srt.encryption` is `allow` or `require`. No template
 *    names it, so it reaches the box as a `.env` line and nowhere else (or through an
 *    app template that names it). The package never mints it: the publishers have to
 *    be told it, so the app owns it.
 *
 * A name left out (or the seam left unconfigured) takes the template's own `${NAME:-}`
 * fallback, which is empty in every shipped template, and an empty live profile is
 * dropped at load (tmesis decision #147), so leaving them out is coherent; a box with
 * no passphrase keeps the shipped `encryption: refuse`. A config render never asks: it
 * leaves every reserved name for the node's `.env`.
 *
 * The values are CREDENTIALS. The renderer wraps them in `SensitiveParameterValue`
 * on the way in; never log them, and answer only what this node needs.
 */
interface EnrollmentSecrets
{
    /**
     * @return array<string, string>
     */
    public function secrets(NodeEnrollment $node): array;
}
