<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\EnrollmentException;

/**
 * The write half of NodeResolver: where enrolments and registrations are kept.
 *
 * The app implements it, because the app owns the table (the
 * TransferSigner precedent): this package ships no table-backed implementation.
 * Bind it in a service provider's register(), or name the class in
 * `tmesis.enrollment.repository` (`TMESIS_ENROLLMENT_REPOSITORY`).
 * `AlexHackney\Tmesis\Testing\ArrayNodeRepository` is the in-memory one for tests.
 *
 * The secrets on a NodeEnrollment are credentials: store them encrypted (the
 * `TableNodeResolver` reads `admin_token` through the encrypter), never log them,
 * and never cache the object (it refuses `serialize()`).
 */
interface NodeRepository
{
    /**
     * Keep a new enrolment, pending until the box registers.
     *
     * @param  bool  $replace  re-mint for a name that exists: the previous secrets and
     *                         bootstrap token stop being valid, and any registration
     *                         MUST be cleared too (the box installs and registers
     *                         again with the new token)
     *
     * @throws EnrollmentException (alreadyEnrolled()) when the name exists and
     *                                                 $replace is false
     */
    public function enroll(NodeEnrollment $node, bool $replace = false): void;

    /**
     * The PENDING enrolment whose bootstrap token hashes to this, or null when there
     * is none (never minted, or already burned by register()).
     *
     * An expired one is still returned: the caller checks
     * NodeEnrollment::bootstrapExpired(), so the answer to an unknown and an expired
     * token is the same.
     *
     * @param  string  $bootstrapTokenSha256  lower-case hex
     */
    public function pending(string $bootstrapTokenSha256): ?NodeEnrollment;

    /**
     * Record what the box installed and where to dial it against the PENDING
     * enrolment that holds this bootstrap token hash, and BURN the token, as ONE
     * compare-and-set. True when it did; false when no pending enrolment holds that
     * sha any more (replaced by `--replace`, expired and swept, or already
     * registered), in which case NOTHING is written.
     *
     * Keyed on the token, never the name: the route looked the enrolment up by token
     * a moment earlier, and a `--replace` in between must not let the OLD box's
     * request register against, and burn the token of, the REPLACEMENT enrolment.
     * In SQL that is one statement, never a lookup then a write:
     *
     *     UPDATE ingest_servers
     *        SET registered_at = ?, version = ?, api_url = ?, bootstrap_token_sha256 = NULL
     *      WHERE bootstrap_token_sha256 = ? AND registered_at IS NULL
     *
     * and the answer is whether it touched a row. Once it returns true, pending()
     * for that sha answers null: that is what makes the token one-use; the route
     * does not burn it separately.
     *
     * @param  string  $bootstrapTokenSha256  lower-case hex
     */
    public function register(string $bootstrapTokenSha256, NodeRegistration $registration): bool;
}
