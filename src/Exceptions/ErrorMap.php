<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Data\ErrorResponse;

/**
 * The ONE place a status code and an `error.code` become an exception class.
 *
 * Two rules, in this order:
 *
 *  1. A known `error.code` picks the class. The node's codes are more specific than
 *     its statuses (four different 409s, three different 503s). Two codes have a
 *     class of their own, a SUBCLASS of their status's (`fleet_owned`,
 *     `encoder_unavailable`), so a catch on the status class still holds.
 *  2. Anything else maps by status class. The code set is OPEN and additive, so an
 *     unrecognised code on a known status is normal traffic from a newer node and
 *     must not fall through to a decoding error.
 *
 * 501 always produces NotImplementedException, with the milestone from the body when
 * the node named one. That is the discovery mechanism from M1 on.
 */
final class ErrorMap
{
    /**
     * The frozen table from `docs/API.md` 6.2, plus `relay_immutable` which the relay
     * routes emit and the openapi document describes but that table omits.
     *
     * The nine artifact codes (tmesis `v0.5.0`, `docs/API.md` 6.2 and 12a) are all
     * 409 and all ConflictException, told apart by `code()`. `initiation_outcome_unknown`
     * and `destination_conflict` are NOT here: they are `error.code` on a failed
     * transfer object's row (`ObjectError`), never a response.
     *
     * @var array<string, class-string<TmesisException>>
     */
    public const CODES = [
        'bad_request' => BadRequestException::class,
        'unauthorized' => AuthenticationException::class,
        'not_found' => NotFoundException::class,
        'method_not_allowed' => MethodNotAllowedException::class,
        'stream_exists' => ConflictException::class,
        'stream_sealed' => ConflictException::class,
        'stream_busy' => ConflictException::class,
        'stream_not_live' => ConflictException::class,
        'lease_held_elsewhere' => ConflictException::class,
        // tmesis v0.6.0: a DIFFERENT marker already has this id on this session
        // (placeMarker; the same request under the id is the idempotent 200).
        'marker_exists' => ConflictException::class,
        // tmesis v0.6.0, the transcode job routes (API.md 9.10 and 9.12): a job already
        // terminal, a node that runs no jobs, and a target over a prefix this node
        // writes (the node cannot see another node's writers).
        'job_not_cancellable' => ConflictException::class,
        'role_unsupported' => ConflictException::class,
        'destination_busy' => ConflictException::class,
        'relay_immutable' => ConflictException::class,
        'artifact_not_finalized' => ConflictException::class,
        'artifact_identity_mismatch' => ConflictException::class,
        'transfer_plan_stale' => ConflictException::class,
        'transfer_identity_mismatch' => ConflictException::class,
        'transfer_incomplete' => ConflictException::class,
        'request_conflict' => ConflictException::class,
        'release_conflict' => ConflictException::class,
        'artifact_retiring' => ConflictException::class,
        'artifact_reconciliation_required' => ConflictException::class,
        // tmesis v0.8.0: a purge refused by a hold no purge removes (purgeArtifact,
        // API.md 12a.5b), and a master asked of a node whose recording.master.enabled
        // is off (createMaster, 12a.8).
        'artifact_held' => ConflictException::class,
        'master_disabled' => ConflictException::class,
        // An enrolled node refusing an API write to what the fleet's desired state owns
        // (tmesis v0.7.0, the stream and relay routes; v0.9.0, the jobs pause routes,
        // detail path jobs_pause, handover 3.5). Its own class: the fix is the next
        // desired revision, never a retry.
        'fleet_owned' => FleetOwnedException::class,
        'payload_too_large' => PayloadTooLargeException::class,
        'unsupported_media_type' => UnsupportedMediaTypeException::class,
        'validation_failed' => ValidationException::class,
        'immutable_field' => ValidationException::class,
        // tmesis v0.6.0, createTranscodeJob: a reserved source (upload_id), a later
        // milestone's output (storyboard, bif, channel_ready), a destination.origin.
        // Each is the code every detail shares, else validation_failed.
        'source_not_supported' => ValidationException::class,
        'output_not_supported' => ValidationException::class,
        'destination_not_supported' => ValidationException::class,
        // tmesis v0.8.0, createMaster: a source this node cannot use, or two sources a
        // stream copy cannot join across. The details name each.
        'invalid_source' => ValidationException::class,
        'sources_incompatible' => ValidationException::class,
        'rate_limited' => RateLimitException::class,
        // tmesis v0.6.0: jobs.max_queued jobs are queued. It replaces rate_limited on
        // the job route, which the node never enforced.
        'queue_full' => RateLimitException::class,
        'internal' => ServerException::class,
        'not_implemented' => NotImplementedException::class,
        'draining' => UnavailableException::class,
        'not_ready' => UnavailableException::class,
        'journal_unavailable' => UnavailableException::class,
        // tmesis v0.8.0, createMaster: no ffmpeg or ffprobe, so the master queue never
        // started on this node.
        'master_unavailable' => UnavailableException::class,
        // tmesis v0.9.0, createTranscodeJob (handover 3.4 and 3.7): the pinned jobs
        // encoder failed at boot. Its own class because it does NOT clear by waiting:
        // send the job to another box.
        'encoder_unavailable' => EncoderUnavailableException::class,
    ];

    /**
     * @var array<int, class-string<TmesisException>>
     */
    private const STATUSES = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        404 => NotFoundException::class,
        405 => MethodNotAllowedException::class,
        409 => ConflictException::class,
        413 => PayloadTooLargeException::class,
        415 => UnsupportedMediaTypeException::class,
        422 => ValidationException::class,
        429 => RateLimitException::class,
        501 => NotImplementedException::class,
        503 => UnavailableException::class,
    ];

    /**
     * @param  array<string, list<string>|string|null>  $headers
     */
    public static function toException(int $status, ?ErrorResponse $body, array $headers = []): TmesisException
    {
        $error = $body?->error();
        $code = $error?->code() ?? '';

        $class = self::classFor($status, $code);

        $message = $error?->message();

        if ($message === null || $message === '') {
            $message = $code !== ''
                ? "The Tmesis node answered {$status} ({$code})."
                : "The Tmesis node answered {$status}.";
        }

        $requestId = $error?->requestId();
        $requestId = ($requestId === null || $requestId === '')
            ? self::header($headers, 'X-Request-Id')
            : $requestId;

        $node = self::header($headers, 'X-Tmesis-Node');
        $nodeVersion = self::header($headers, 'X-Tmesis-Version');

        if (is_a($class, RateLimitException::class, true) || is_a($class, UnavailableException::class, true)) {
            $retryAfter = self::header($headers, 'Retry-After');

            return new $class(
                $message,
                $error,
                $status,
                $requestId,
                $node,
                $nodeVersion,
                $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return new $class($message, $error, $status, $requestId, $node, $nodeVersion);
    }

    /**
     * @return class-string<TmesisException>
     */
    private static function classFor(int $status, string $code): string
    {
        // 501 is discovery and outranks the code: a node that answers 501 with an
        // unexpected code is still telling us the route is not built yet.
        if ($status === 501) {
            return NotImplementedException::class;
        }

        if ($code !== '' && isset(self::CODES[$code])) {
            return self::CODES[$code];
        }

        if (isset(self::STATUSES[$status])) {
            return self::STATUSES[$status];
        }

        if ($status >= 500) {
            return ServerException::class;
        }

        return ClientException::class;
    }

    /**
     * @param  array<string, list<string>|string|null>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
