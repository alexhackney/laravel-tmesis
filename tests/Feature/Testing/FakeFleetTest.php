<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\Fleet\FleetRevisionRejected;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Testing\FakeFleet;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;

/*
 * F9: the fake an app writes its fleet tests against. It drives the real enrolment,
 * store and heartbeat handler, so what passes here passes against the receiver.
 */

beforeEach(function (): void {
    $this->withFleet();
    $this->fleet = Tmesis::fake()->fleet();
});

it('is what Tmesis::fleet() answers once faked', function (): void {
    expect(Tmesis::fleet())->toBeInstanceOf(FakeFleet::class)->toBe($this->fleet);
});

it('enrols a node, applies what it is sent, and converges', function (): void {
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->stream(DesiredStream::make('show', DesiredRecording::make(true)))
        ->revoke('old-show');

    $node = $this->fleet->enrol('site-7');

    expect($node->appliedRevision)->toBe(1);

    $answer = $this->fleet->heartbeat($node->id);

    expect($answer['desired_state'])->toBeNull();
    $this->fleet->assertDesiredRevision($node->id, 1)->assertConverged($node->id);

    ArrayDesiredStateProvider::$documents['site-7']->revoke('another');
    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    $this->fleet->assertDesiredRevision($node->id, 2)->assertConverged($node->id);
});

it('queues, delivers and acks commands the way a healthy node does', function (): void {
    $node = $this->fleet->enrol('site-7');
    $kick = Tmesis::fleet()->kick($node->id, 'show');
    $drain = Tmesis::fleet()->drain($node->id);

    $this->fleet->assertCommandQueued($node->id, 'kick', 'show')->assertCommandQueued($node->id, 'drain');

    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    $this->fleet->assertCommandAcked($kick)->assertCommandAcked($drain)->assertNothingQueued($node->id);
    expect(Tmesis::fleet()->node($node->id)->isDraining())->toBeTrue();
});

it('keeps re-sending to a node that does not ack', function (): void {
    $node = $this->fleet->enrol('site-7');
    $this->fleet->withoutAcking($node->id);
    $kick = Tmesis::fleet()->kick($node->id, 'show');

    $this->fleet->heartbeat($node->id);
    $second = $this->fleet->heartbeat($node->id);

    expect(array_column($second['commands'], 'id'))->toBe([$kick]);
    expect(fn () => $this->fleet->assertCommandAcked($kick))->toThrow(AssertionFailedError::class);
});

it('carries a node event over the heartbeat, dispatched once as the webhook class', function (): void {
    $count = 0;
    Event::listen(StreamPublished::class, function () use (&$count): void {
        $count++;
    });
    $node = $this->fleet->enrol('site-7');

    $id = $this->fleet->event($node->id, 'stream.published', ['stream' => 'show', 'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN']);
    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    $this->fleet->assertEventAcked($node->id, $id);
    expect($count)->toBe(1);
});

it('scripts a rejected revision', function (): void {
    Event::fake([FleetRevisionRejected::class]);
    $node = $this->fleet->enrol('site-7');
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()->revoke('x');

    $this->fleet->rejectNext($node->id, 'revocations[0]: refused (test)');
    $this->fleet->heartbeat($node->id);
    $this->fleet->heartbeat($node->id);

    Event::assertDispatched(FleetRevisionRejected::class, fn ($e) => $e->rejected->revision === 2 && str_starts_with($e->rejected->reason, 'revocations[0]'));
    expect(Tmesis::fleet()->node($node->id)->appliedRevision)->toBe(1);
});

it('refuses to heartbeat a node that left, as the receiver would', function (): void {
    $node = $this->fleet->enrol('site-7');
    $this->fleet->enrol('site-7');

    expect(fn () => $this->fleet->heartbeat($node->id))->toThrow(RuntimeException::class, 'not enrolled');
});
