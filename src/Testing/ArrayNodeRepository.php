<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRegistration;
use AlexHackney\Tmesis\Enrollment\NodeRepository;
use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * An in-memory NodeRepository for a consuming app's tests.
 *
 * `ArrayNodeRepository::fake()` binds one. It keeps the NodeEnrollment objects as
 * they are (never serialised) and follows the interface's contract: enroll()
 * refuses an existing name without replace, register() is a compare-and-set on the
 * pending token hash that burns it, and a
 * replace drops the previous token and registration. Assertion messages name nodes,
 * never secrets.
 */
class ArrayNodeRepository implements NodeRepository
{
    /** @var array<string, NodeEnrollment> */
    private array $enrollments = [];

    /** @var array<string, string> sha256 => name, pending only */
    private array $pending = [];

    /** @var array<string, NodeRegistration> */
    private array $registrations = [];

    public static function fake(): self
    {
        $repository = new self;

        app()->instance(NodeRepository::class, $repository);

        return $repository;
    }

    public function enroll(NodeEnrollment $node, bool $replace = false): void
    {
        $previous = $this->enrollments[$node->name] ?? null;

        if ($previous !== null) {
            if (! $replace) {
                throw EnrollmentException::alreadyEnrolled($node->name);
            }

            unset($this->pending[$previous->bootstrapTokenSha256], $this->registrations[$node->name]);
        }

        $this->enrollments[$node->name] = $node;
        $this->pending[$node->bootstrapTokenSha256] = $node->name;
    }

    public function pending(string $bootstrapTokenSha256): ?NodeEnrollment
    {
        $name = $this->pending[$bootstrapTokenSha256] ?? null;

        return $name === null ? null : $this->enrollments[$name];
    }

    /**
     * The compare-and-set the interface asks for: only the enrolment that still
     * holds this sha as PENDING is registered, and its token burned in the same
     * step. A replaced or already-registered token answers false and writes nothing.
     */
    public function register(string $bootstrapTokenSha256, NodeRegistration $registration): bool
    {
        $name = $this->pending[$bootstrapTokenSha256] ?? null;

        if ($name === null) {
            return false;
        }

        unset($this->pending[$bootstrapTokenSha256]);

        $this->registrations[$name] = $registration;

        return true;
    }

    public function enrolled(string $name): ?NodeEnrollment
    {
        return $this->enrollments[$name] ?? null;
    }

    public function registration(string $name): ?NodeRegistration
    {
        return $this->registrations[$name] ?? null;
    }

    public function assertEnrolled(string $name): self
    {
        PHPUnit::assertArrayHasKey($name, $this->enrollments, "The node [{$name}] was not enrolled.");

        return $this;
    }

    public function assertNotEnrolled(string $name): self
    {
        PHPUnit::assertArrayNotHasKey($name, $this->enrollments, "The node [{$name}] was enrolled.");

        return $this;
    }

    public function assertRegistered(string $name): self
    {
        PHPUnit::assertArrayHasKey($name, $this->registrations, "The node [{$name}] did not register.");

        return $this;
    }

    public function assertNotRegistered(string $name): self
    {
        PHPUnit::assertArrayNotHasKey($name, $this->registrations, "The node [{$name}] registered.");

        return $this;
    }
}
