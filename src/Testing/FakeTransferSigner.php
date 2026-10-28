<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Transfers\RefreshTransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorizationNeed;
use AlexHackney\Tmesis\Transfers\TransferSigner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as ConcreteDispatcher;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A TransferSigner for a consuming app's tests: it records every need it is asked to
 * sign and answers what it is told to.
 *
 * `FakeTransferSigner::fake()` binds one and makes sure RefreshTransferAuthorization
 * listens, so a test that delivers `transfer.authorization_required` (through
 * `FakeTmesis::webhook()` or by dispatching the event) drives the whole refresh
 * against whatever answers the node's routes, the fake client or `Http::fake()`.
 *
 * The default answer is null, which leaves the object waiting. signEverything()
 * answers every required operation and owed part with a placeholder request on the
 * reserved `.invalid` domain, which is not a credential, but the recorded calls keep
 * only the NAMES of what was supplied anyway and no assertion message prints a URL.
 */
class FakeTransferSigner implements TransferSigner
{
    /** @var list<array{need: TransferAuthorizationNeed, supplied: list<string>, parts: list<int>}> */
    private array $calls = [];

    /** @var TransferAuthorization|(Closure(TransferAuthorizationNeed): ?TransferAuthorization)|null */
    private TransferAuthorization|Closure|null $answer = null;

    /**
     * Bind a fake signer and register the refresh listener if nothing configured one.
     *
     * RefreshTransferAuthorization is `ShouldQueue`, so the refresh runs only where
     * the queue runs it: inline under the `sync` connection (the testbench default),
     * NEVER under `Queue::fake()` (the job is only pushed, and this signer records
     * nothing), and only on a worker under any other connection.
     *
     * @param  TransferAuthorization|(Closure(TransferAuthorizationNeed): ?TransferAuthorization)|null  $answer
     */
    public static function fake(TransferAuthorization|Closure|null $answer = null): self
    {
        $fake = (new self)->answerWith($answer);

        app()->instance(TransferSigner::class, $fake);

        $events = app(Dispatcher::class);

        if (! self::refreshListens($events)) {
            $events->listen(TransferAuthorizationRequired::class, RefreshTransferAuthorization::class);
        }

        return $fake;
    }

    /**
     * What sign() returns from now on: a fixed answer, a closure over the need, or
     * null to leave every object waiting.
     *
     * @param  TransferAuthorization|(Closure(TransferAuthorizationNeed): ?TransferAuthorization)|null  $answer
     */
    public function answerWith(TransferAuthorization|Closure|null $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    /**
     * Answer every need in full: one placeholder request per required operation, and
     * one per owed part when `parts` is required.
     */
    public function signEverything(): self
    {
        return $this->answerWith(static fn (TransferAuthorizationNeed $need): TransferAuthorization => self::placeholderFor($need));
    }

    public function sign(TransferAuthorizationNeed $need): ?TransferAuthorization
    {
        $answer = $this->answer instanceof Closure ? ($this->answer)($need) : $this->answer;

        $this->calls[] = [
            'need' => $need,
            'supplied' => $answer?->supplied() ?? [],
            'parts' => $answer?->partNumbers() ?? [],
        ];

        return $answer;
    }

    /**
     * Every need sign() was asked for, in order.
     *
     * @return list<TransferAuthorizationNeed>
     */
    public function needs(): array
    {
        return array_map(static fn (array $call): TransferAuthorizationNeed => $call['need'], $this->calls);
    }

    /**
     * @param  (callable(TransferAuthorizationNeed): bool)|null  $filter
     */
    public function assertSigned(?callable $filter = null): self
    {
        $matches = array_filter($this->needs(), static fn (TransferAuthorizationNeed $need): bool => $filter === null || $filter($need));

        PHPUnit::assertNotEmpty($matches, 'The transfer signer was not asked for a matching need.');

        return $this;
    }

    public function assertSignedObject(string $objectId): self
    {
        $matches = array_filter($this->needs(), static fn (TransferAuthorizationNeed $need): bool => $need->objectId() === $objectId);

        PHPUnit::assertNotEmpty($matches, "The transfer signer was not asked to sign object [{$objectId}].");

        return $this;
    }

    /**
     * A HEAD was asked for (a completion probe), for the object when one is named.
     */
    public function assertSignedHead(?string $objectId = null): self
    {
        $matches = array_filter(
            $this->needs(),
            static fn (TransferAuthorizationNeed $need): bool => $need->requiresHead()
                && ($objectId === null || $need->objectId() === $objectId),
        );

        PHPUnit::assertNotEmpty(
            $matches,
            $objectId === null
                ? 'The transfer signer was not asked for a HEAD.'
                : "The transfer signer was not asked for a HEAD for object [{$objectId}]."
        );

        return $this;
    }

    /**
     * The signer answered these part numbers for the object, across its calls.
     *
     * @param  list<int>  $partNumbers
     */
    public function assertSignedParts(string $objectId, array $partNumbers): self
    {
        $signed = [];

        foreach ($this->calls as $call) {
            if ($call['need']->objectId() === $objectId) {
                $signed = [...$signed, ...$call['parts']];
            }
        }

        sort($signed);
        sort($partNumbers);

        PHPUnit::assertSame($partNumbers, $signed, "The parts signed for object [{$objectId}] differ.");

        return $this;
    }

    public function assertSignedCount(int $times): self
    {
        $count = count($this->calls);

        PHPUnit::assertSame($times, $count, "The transfer signer was asked {$count} times.");

        return $this;
    }

    public function assertNothingSigned(): self
    {
        PHPUnit::assertEmpty($this->calls, 'Expected the transfer signer not to be asked.');

        return $this;
    }

    private static function refreshListens(Dispatcher $events): bool
    {
        if (! $events instanceof ConcreteDispatcher) {
            return $events->hasListeners(TransferAuthorizationRequired::class);
        }

        $listeners = $events->getRawListeners()[TransferAuthorizationRequired::class] ?? [];

        return is_array($listeners) && in_array(RefreshTransferAuthorization::class, $listeners, true);
    }

    private static function placeholderFor(TransferAuthorizationNeed $need): TransferAuthorization
    {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour');
        $url = static fn (string $operation): string => 'https://fake-signer.invalid/'.rawurlencode($need->objectKey()).'?op='.$operation;
        $request = static fn (string $method, string $operation): SignedRequest => SignedRequest::make($method, $url($operation), [], $expires);

        $parts = null;

        if ($need->requires(TransferAuthorizationRequired::OPERATION_PARTS)) {
            $parts = array_map(
                static fn (int $number): PartAuthorization => PartAuthorization::make($number, $request('PUT', 'part-'.$number)),
                $need->owedPartNumbers(),
            );
        }

        return TransferAuthorization::make(
            put: $need->requires(TransferAuthorizationRequired::OPERATION_PUT) ? $request('PUT', 'put') : null,
            initiate: $need->requires(TransferAuthorizationRequired::OPERATION_INITIATE) ? $request('POST', 'initiate') : null,
            parts: $parts,
            complete: $need->requires(TransferAuthorizationRequired::OPERATION_COMPLETE) ? $request('POST', 'complete') : null,
            abort: $need->requires(TransferAuthorizationRequired::OPERATION_ABORT) ? $request('DELETE', 'abort') : null,
            head: $need->requiresHead() ? $request('HEAD', 'head') : null,
        );
    }
}
