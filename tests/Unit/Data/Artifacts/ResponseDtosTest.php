<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTargetStatus;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferCounts;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

/**
 * @return array<string, mixed>
 */
function artifactComponent(string $name): array
{
    /** @var array<string, mixed> */
    return ContractDir::readJson(ContractDir::openapi())['components']['schemas'][$name];
}

it('holds every closed set to the document\'s enum', function (): void {
    expect(ArtifactStatus::KINDS)->toBe(artifactComponent('ArtifactStatus')['properties']['kind']['enum'])
        ->and([ArtifactStatus::STATE_HELD, ArtifactStatus::STATE_FINALIZED])->toBe(artifactComponent('ArtifactStatus')['properties']['state']['enum'])
        ->and([
            ArtifactStatus::RELEASE_UNRELEASED, ArtifactStatus::RELEASE_RELEASED, ArtifactStatus::RELEASE_OVERRIDDEN,
            ArtifactStatus::RELEASE_RETIRING, ArtifactStatus::RELEASE_DELETED,
        ])->toBe(artifactComponent('ArtifactStatus')['properties']['release_state']['enum'])
        ->and(ObjectStatus::STATES)->toBe(artifactComponent('ObjectStatus')['properties']['state']['enum'])
        ->and(ObjectStatus::STATES)->toBe(artifactComponent('PartStatus')['properties']['state']['enum'])
        ->and(ObjectStatus::PHASES)->toBe(artifactComponent('ObjectStatus')['properties']['phase']['enum'])
        ->and([DecisionStatus::ACTION_RELEASE, DecisionStatus::ACTION_OVERRIDE, DecisionStatus::ACTION_DISCARD])->toBe(artifactComponent('DecisionStatus')['properties']['action']['enum'])
        ->and([PlanTargetStatus::AUTHORIZATION_CONFIGURED, PlanTargetStatus::AUTHORIZATION_SIGNED])->toBe(artifactComponent('PlanTargetStatus')['properties']['authorization']['enum'])
        ->and(SignedDestination::ADDRESSING)->toBe(artifactComponent('SignedDestination')['properties']['addressing']['enum'])
        ->and(SignedRequest::METHODS)->toBe(artifactComponent('SignedRequest')['properties']['method']['enum']);
});

it('lists the ten hold reasons in the order the document gives them', function (): void {
    $description = (string) artifactComponent('ArtifactStatus')['properties']['hold_reasons']['description'];

    preg_match('/in this order: ([a-z_, ]+)\./', $description, $matches);

    expect(explode(', ', $matches[1] ?? ''))->toBe(ArtifactStatus::HOLD_REASONS)
        ->and(ArtifactStatus::ACTIVE_HOLD_REASONS)->each->toBeIn(ArtifactStatus::HOLD_REASONS);
});

it('reads a held generation\'s nulls as null, never zero', function (): void {
    $held = ArtifactStatus::fromArray($this->fixtureJson('responses/listArtifacts.json')['items'][1]);

    expect($held->isFinalized())->toBeFalse()
        ->and($held->inventorySha256())->toBeNull()
        ->and($held->planRevision())->toBeNull()
        ->and($held->finalizedAt())->toBeNull()
        ->and($held->registeredAt())->toBeNull()
        ->and($held->retentionExpiresAt())->toBeNull()
        ->and($held->recordingId())->toBeNull()
        ->and($held->retentionHours())->toBe(72);
});

it('reads a plan revision of 0 as 0, not as null', function (): void {
    expect(ArtifactStatus::fromArray(['plan_revision' => 0])->planRevision())->toBe(0);
});

it('counts transfers and says when every one is complete', function (): void {
    $counts = TransferCounts::fromArray(['queued' => 1, 'uploading' => 2, 'waiting_authorization' => 3, 'retry_failed' => 4, 'failed' => 5, 'complete' => 6]);

    expect($counts->total())->toBe(21)
        ->and($counts->allComplete())->toBeFalse()
        ->and(TransferCounts::fromArray(['complete' => 4])->allComplete())->toBeTrue()
        ->and(TransferCounts::fromArray([])->allComplete())->toBeTrue();
});

it('defaults the status-code facts for a read: not created, and recorded', function (): void {
    expect(PlanStatus::fromArray([])->created)->toBeFalse()
        ->and(PlanStatus::fromArray([], created: true)->created)->toBeTrue()
        ->and(DecisionStatus::fromArray([])->recorded)->toBeTrue()
        ->and(DecisionStatus::fromArray([], recorded: false)->recorded)->toBeFalse();
});

it('reads an empty cursor as the last page, so a walker cannot loop on it', function (string $class): void {
    expect($class::fromArray(['items' => [], 'limit' => 1, 'next_cursor' => ''])->nextCursor())->toBeNull()
        ->and($class::fromArray(['items' => [], 'limit' => 1, 'next_cursor' => null])->nextCursor())->toBeNull()
        ->and($class::fromArray(['items' => [], 'limit' => 1, 'next_cursor' => 'c'])->nextCursor())->toBe('c');
})->with([ArtifactPage::class, InventoryPage::class, TransferPage::class, PartPage::class]);

it('skips a page item that is not an object rather than failing the page', function (): void {
    $page = TransferPage::fromArray(['items' => [null, 'x', ['id' => 'o1']], 'limit' => 3, 'next_cursor' => null]);

    expect($page->items())->toHaveCount(1)
        ->and($page->items()[0]->id())->toBe('o1');
});

it('decodes an object with no error and one with an error', function (): void {
    $items = $this->fixtureJson('responses/listTransfers.json')['items'];

    $complete = ObjectStatus::fromArray($items[0]);
    $waiting = ObjectStatus::fromArray($items[1]);

    expect($complete->error())->toBeNull()
        ->and($complete->completedAt()?->format('Y-m-d'))->toBe('2026-09-22')
        ->and($complete->completionBodySha256())->not->toBeNull()
        ->and($waiting->error()?->message())->toContain('Supply signed requests')
        ->and($waiting->authorizationRequired())->toBe(['parts', 'abort'])
        ->and($waiting->partSizeBytes())->toBe(16777216)
        ->and($waiting->completedAt())->toBeNull()
        ->and($waiting->reconcileRequired())->toBeFalse();
});
