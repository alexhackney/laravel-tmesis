<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\JobEvent;
use AlexHackney\Tmesis\Events\JobsPaused;
use AlexHackney\Tmesis\Events\JobsResumed;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * A generated jobs.paused or jobs.resumed example (tmesis v0.9.0) with $overlay laid
 * over it, held valid against the schema first.
 *
 * @param  array<string, mixed>  $overlay
 * @return array<string, mixed>
 */
function jobsPauseExample(string $event, array $overlay = []): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example($event)), true, 512, JSON_THROW_ON_ERROR);
    $payload = array_replace($payload, $overlay);

    SchemaValidator::forSchema($event)->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "{$event} with ".json_encode($overlay)
    );

    return $payload;
}

it('maps both to their classes, node-scoped and not job events', function (string $event, string $class): void {
    $instance = EventMap::make(jobsPauseExample($event));

    expect($instance)->toBeInstanceOf($class)
        ->and($instance)->not->toBeInstanceOf(JobEvent::class)
        ->and($instance->stream())->toBeNull()
        ->and($instance->labels())->toBe([])
        ->and($instance->node())->toBe('origin-atl-01');
})->with([
    ['jobs.paused', JobsPaused::class],
    ['jobs.resumed', JobsResumed::class],
]);

it('reads the generated jobs.paused example', function (): void {
    /** @var JobsPaused $event */
    $event = EventMap::make(jobsPauseExample('jobs.paused'));

    expect($event->untilRaw())->toBe('2026-08-28T19:00:00Z')
        ->and($event->until()?->format(DATE_ATOM))->toBe('2026-08-28T19:00:00+00:00')
        ->and($event->reason())->toBe('show: fight night 12')
        ->and($event->source())->toBe(JobsPaused::SOURCE_FLEET)
        ->and($event->setAtRaw())->toBe('2026-08-28T12:00:00.409Z')
        ->and($event->setAt()->format('Y-m-d H:i:s.v'))->toBe('2026-08-28 12:00:00.409');
});

it('reads a pause with no until, set through the api', function (): void {
    /** @var JobsPaused $event */
    $event = EventMap::make(jobsPauseExample('jobs.paused', ['until' => null, 'source' => 'api']));

    expect($event->untilRaw())->toBeNull()
        ->and($event->until())->toBeNull()
        ->and($event->source())->toBe(JobsPaused::SOURCE_API);
});

it('reads the generated jobs.resumed example and pairs it with its jobs.paused', function (): void {
    /** @var JobsPaused $paused */
    $paused = EventMap::make(jobsPauseExample('jobs.paused'));

    /** @var JobsResumed $resumed */
    $resumed = EventMap::make(jobsPauseExample('jobs.resumed'));

    expect($resumed->cause())->toBe(JobsResumed::CAUSE_EXPIRED)
        ->and($resumed->source())->toBe(JobsResumed::SOURCE_FLEET)
        ->and($resumed->pausedAtRaw())->toBe($paused->setAtRaw())
        ->and($resumed->pausedAt())->toEqual($paused->setAt());
});

it('reads every resumed cause', function (string $cause): void {
    /** @var JobsResumed $event */
    $event = EventMap::make(jobsPauseExample('jobs.resumed', ['cause' => $cause]));

    expect($event->cause())->toBe($cause);
})->with([JobsResumed::CAUSE_EXPIRED, JobsResumed::CAUSE_CLEARED, JobsResumed::CAUSE_RELEASED]);

it('refuses a source or cause outside the closed sets', function (string $event, string $field, string $value): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example($event)), true, 512, JSON_THROW_ON_ERROR);
    $payload[$field] = $value;

    expect(SchemaValidator::forSchema($event)->isValid(json_decode((string) json_encode($payload), false)))->toBeFalse();
})->with([
    ['jobs.paused', 'source', 'operator'],
    ['jobs.resumed', 'source', 'operator'],
    ['jobs.resumed', 'cause', 'timeout'],
]);

it('throws naming the key when a required timestamp is missing', function (): void {
    $paused = JobsPaused::fromArray(['event' => 'jobs.paused']);
    $resumed = JobsResumed::fromArray(['event' => 'jobs.resumed']);

    expect($paused->untilRaw())->toBeNull()
        ->and($paused->until())->toBeNull()
        ->and($paused->reason())->toBe('')
        ->and(fn () => $paused->setAt())->toThrow(RuntimeException::class, '[set_at]')
        ->and(fn () => $resumed->pausedAtRaw())->toThrow(RuntimeException::class, '[paused_at]')
        ->and($resumed->cause())->toBe('');
});

it('reads an empty until as no until', function (): void {
    $event = JobsPaused::fromArray(['event' => 'jobs.paused', 'until' => '']);

    expect($event->untilRaw())->toBeNull()
        ->and($event->until())->toBeNull();
});
