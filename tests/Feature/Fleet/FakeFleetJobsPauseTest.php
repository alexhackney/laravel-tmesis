<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\JobsPauseState;
use AlexHackney\Tmesis\Events\Fleet\FleetJobsPauseChanged;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredJobsPause;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use Illuminate\Support\Facades\Event;

/*
 * The fake's side of the told jobs pause (tmesis v0.9.0, handover 3.5): its beats carry
 * jobs_pause, a scripted pause is reported until a desired state replaces it, and an
 * applied desired pause is reported back as the fleet's, as a real node would.
 */

beforeEach(function (): void {
    $this->withFleet();
    $this->fleet = Tmesis::fake()->fleet();
    $this->travelTo(now()->parse('2026-08-28T12:00:00Z'));
});

it('reports no pause by default', function (): void {
    $node = $this->fleet->enrol('site-7');
    $this->fleet->heartbeat($node->id);

    expect($node->jobsPause)->toBeNull()
        ->and(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
});

it('reports a scripted API pause until the first revision replaces it', function (): void {
    Event::fake([FleetJobsPauseChanged::class]);
    $node = $this->fleet->enrol('site-7');

    $this->fleet->withJobsPause($node->id, new JobsPauseState(null, 'maintenance', JobsPauseState::SOURCE_API));
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPause?->source)->toBe('api');
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 1);

    // A new revision without a pause clears the API pause too.
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()->revoke('old-show');
    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 2);
});

it('applies a desired pause as the fleet\'s, and the document without it resumes', function (): void {
    $node = $this->fleet->enrol('site-7');
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->jobsPause(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'));

    $answer = $this->fleet->heartbeat($node->id);

    expect($answer['desired_state']['jobs_pause'])->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12'])
        ->and($node->jobsPause)->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'fleet']);

    $this->fleet->heartbeat($node->id);
    expect(Tmesis::fleet()->node($node->id)->jobsPause?->toArray())->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'fleet']);
    $this->fleet->assertConverged($node->id);

    ArrayDesiredStateProvider::$documents['site-7']->jobsPause(null);
    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
    $this->fleet->assertConverged($node->id);
});

it('reports a pause as over once its until passes on Laravel\'s clock', function (): void {
    $node = $this->fleet->enrol('site-7');
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->jobsPause(DesiredJobsPause::make('show', '2026-08-28T19:00:00Z'));

    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);
    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeTrue();

    $this->travelTo(now()->parse('2026-08-28T19:00:01Z'));
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
});

it('holds a desired pause whose until already passed as inactive', function (): void {
    $node = $this->fleet->enrol('site-7');
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->jobsPause(DesiredJobsPause::make('yesterday\'s show', '2026-08-27T19:00:00Z'));

    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
});

it('does not take a pause it does not apply', function (): void {
    $node = $this->fleet->enrol('site-7');
    $this->fleet->withoutApplying($node->id);
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()->jobsPause(DesiredJobsPause::make('show'));

    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeFalse();
});

it('reads a reported pause as over once its until passes, with no beat since', function (): void {
    $node = $this->fleet->enrol('site-7');
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->jobsPause(DesiredJobsPause::make('show', '2026-08-28T19:00:00Z'));

    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);
    expect(Tmesis::fleet()->node($node->id)->jobsPaused())->toBeTrue();

    // The node went quiet: the last beat still reports the pause, the rule ends it.
    $this->travelTo(now()->parse('2026-08-28T19:00:01Z'));

    $stored = Tmesis::fleet()->node($node->id);
    expect($stored->jobsPause?->until)->toBe('2026-08-28T19:00:00Z')
        ->and($stored->jobsPaused())->toBeFalse();
});
