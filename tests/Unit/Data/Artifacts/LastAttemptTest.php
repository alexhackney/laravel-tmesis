<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\LastAttempt;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Events\TransferFailed;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * @return array<string, mixed>
 */
function transferFailedPayload(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example('transfer.failed')), true, 512, JSON_THROW_ON_ERROR);
}

it('reads the cause of the last failed attempt, the raw timestamp and the parsed one', function (): void {
    $attempt = LastAttempt::fromArray(['code' => 'dns_not_found', 'message' => 'archive.example.net did not resolve', 'at' => '2026-11-08T19:12:44.120Z']);

    expect($attempt->code())->toBe(LastAttempt::CODE_DNS_NOT_FOUND)
        ->and($attempt->message())->toBe('archive.example.net did not resolve')
        ->and($attempt->atRaw())->toBe('2026-11-08T19:12:44.120Z')
        ->and($attempt->at()?->format('Y-m-d H:i:s.v'))->toBe('2026-11-08 19:12:44.120')
        ->and($attempt->toArray()['code'])->toBe('dns_not_found');
});

it('treats an unknown code as text and a timestamp it cannot parse as unknown, never a throw', function (): void {
    $attempt = LastAttempt::fromArray(['code' => 'http_503', 'message' => 'the provider answered 503', 'at' => 'yesterday-ish']);

    expect($attempt->code())->toBe('http_503')
        ->and($attempt->at())->toBeNull()
        ->and($attempt->atRaw())->toBe('yesterday-ish')
        ->and(LastAttempt::fromArray([])->at())->toBeNull()
        ->and(LastAttempt::fromArray([])->code())->toBe('');
});

it('names the cause codes the node documents', function (): void {
    expect([
        LastAttempt::CODE_DNS_NOT_FOUND, LastAttempt::CODE_DNS_ERROR, LastAttempt::CODE_CONNECT_REFUSED,
        LastAttempt::CODE_CONNECT_TIMEOUT, LastAttempt::CODE_TLS_ERROR, LastAttempt::CODE_TIMEOUT, LastAttempt::CODE_TRANSPORT,
    ])->toBe(['dns_not_found', 'dns_error', 'connect_refused', 'connect_timeout', 'tls_error', 'timeout', 'transport']);
});

it('reads last_attempt on an object and a part, null on a row written before the field', function (): void {
    $cause = ['code' => 'tls_error', 'message' => 'the TLS handshake with archive.example.net failed', 'at' => '2026-11-08T19:12:44.120Z'];

    expect(ObjectStatus::fromArray(['last_attempt' => $cause])->lastAttempt()?->code())->toBe('tls_error')
        ->and(ObjectStatus::fromArray(['last_attempt' => null])->lastAttempt())->toBeNull()
        ->and(ObjectStatus::fromArray([])->lastAttempt())->toBeNull()
        ->and(PartStatus::fromArray(['last_attempt' => $cause])->lastAttempt()?->message())->toBe($cause['message'])
        ->and(PartStatus::fromArray(['last_attempt' => null])->lastAttempt())->toBeNull()
        ->and(PartStatus::fromArray([])->lastAttempt())->toBeNull();
});

it('keeps both the budget code and the cause on a terminally failed object', function (): void {
    $object = ObjectStatus::fromArray([
        'state' => 'failed',
        'error' => ['code' => 'attempts_exhausted', 'message' => 'The transfer reported attempts_exhausted'],
        'last_attempt' => ['code' => 'connect_timeout', 'message' => 'connecting to archive.example.net timed out', 'at' => '2026-11-08T19:12:44.120Z'],
    ]);

    expect($object->error()?->code())->toBe('attempts_exhausted')
        ->and($object->lastAttempt()?->code())->toBe(LastAttempt::CODE_CONNECT_TIMEOUT);
});

it('reads transfer.failed with no last attempt, as the generated example carries', function (): void {
    /** @var TransferFailed $event */
    $event = EventMap::make(transferFailedPayload());

    expect($event->lastAttempt())->toBeNull()
        ->and($event->code())->toBe(TransferFailed::CODE_ATTEMPTS_EXHAUSTED);
});

it('reads transfer.failed with a cause, validated against the schema', function (): void {
    $payload = transferFailedPayload();
    $payload['last_attempt'] = ['code' => 'connect_refused', 'message' => 'archive.example.net refused the connection', 'at' => '2026-11-08T19:12:44.120Z'];

    SchemaValidator::forSchema('transfer.failed')->assertValid(json_decode((string) json_encode($payload), false), null, 'transfer.failed with a cause');

    /** @var TransferFailed $event */
    $event = EventMap::make($payload);

    expect($event->lastAttempt())->toBeInstanceOf(LastAttempt::class)
        ->and($event->lastAttempt()?->code())->toBe(LastAttempt::CODE_CONNECT_REFUSED)
        ->and($event->lastAttempt()?->at()?->format('H:i'))->toBe('19:12');
});
