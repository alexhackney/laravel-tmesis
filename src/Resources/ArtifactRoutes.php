<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Exceptions\ServerException;
use Closure;
use Generator;
use Illuminate\Support\LazyCollection;

/**
 * The eleven artifact routes with the owner already in the path: everything the stream
 * routes (`/api/v1/streams/{id}/artifacts`, Artifacts) and the job routes
 * (`/api/v1/transcode/jobs/{id}/artifacts`, JobArtifacts, tmesis `v0.6.0`) share. The
 * bodies, the answers and the status-code facts are the same on both; only the owner
 * segment differs, so it lives here once.
 *
 * `$base` is the owner's artifacts collection, already built by Transport::path();
 * every argument added below it is escaped the same way.
 *
 * @internal used by Artifacts and JobArtifacts
 */
final class ArtifactRoutes
{
    public function __construct(private readonly Transport $transport, private readonly string $base) {}

    public function list(?int $limit, ?string $cursor): ArtifactPage
    {
        return ArtifactPage::fromArray($this->transport->get($this->base, ['limit' => $limit, 'cursor' => $cursor]));
    }

    public function get(string $generation): ArtifactStatus
    {
        return ArtifactStatus::fromArray($this->transport->get($this->path('/%s', $generation)));
    }

    public function inventory(string $generation, ?int $limit, ?string $cursor): InventoryPage
    {
        return InventoryPage::fromArray($this->transport->get(
            $this->path('/%s/inventory', $generation),
            ['limit' => $limit, 'cursor' => $cursor],
        ));
    }

    public function plan(string $generation): PlanStatus
    {
        return PlanStatus::fromArray($this->transport->get($this->path('/%s/transfer-plan', $generation)));
    }

    public function replacePlan(string $generation, PlanRequest $plan): PlanStatus
    {
        $result = $this->transport->put($this->path('/%s/transfer-plan', $generation), $plan->toArray());

        return PlanStatus::fromArray($result->body, created: $result->status === 201);
    }

    public function transfers(string $generation, ?int $limit, ?string $cursor): TransferPage
    {
        return TransferPage::fromArray($this->transport->get(
            $this->path('/%s/transfers', $generation),
            ['limit' => $limit, 'cursor' => $cursor],
        ));
    }

    public function parts(string $generation, string $objectId, ?int $limit, ?string $cursor): PartPage
    {
        return PartPage::fromArray($this->transport->get(
            $this->path('/%s/transfers/%s/parts', $generation, $objectId),
            ['limit' => $limit, 'cursor' => $cursor],
        ));
    }

    public function authorize(string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        return ObjectStatus::fromArray($this->transport->put(
            $this->path('/%s/transfers/%s/authorization', $generation, $objectId),
            $authorization->toArray(),
        )->body);
    }

    public function release(string $generation, ReleaseRequest $release): DecisionStatus
    {
        $result = $this->transport->post($this->path('/%s/release', $generation), $release->toArray());

        return DecisionStatus::fromArray($result->body, recorded: $result->status === 202);
    }

    public function override(string $generation, OverrideRequest $override): DecisionStatus
    {
        $result = $this->transport->post($this->path('/%s/override', $generation), $override->toArray());

        return DecisionStatus::fromArray($result->body, recorded: $result->status === 202);
    }

    public function purge(string $generation, ReleaseRequest $purge): DecisionStatus
    {
        $result = $this->transport->post($this->path('/%s/purge', $generation), $purge->toArray());

        return DecisionStatus::fromArray($result->body, recorded: $result->status === 202);
    }

    /**
     * Follow `next_cursor` until the node says there is no next page. A cursor
     * answered twice in a row would loop forever, so it is a ServerException.
     *
     * @template TItem
     *
     * @param  Closure(?string): array{0: list<TItem>, 1: ?string}  $page
     * @return LazyCollection<int, TItem>
     */
    public static function walk(Closure $page): LazyCollection
    {
        return LazyCollection::make(static function () use ($page): Generator {
            $cursor = null;

            do {
                $previous = $cursor;
                [$items, $cursor] = $page($cursor);

                foreach ($items as $item) {
                    yield $item;
                }

                if ($cursor !== null && $cursor === $previous) {
                    throw new ServerException('The node answered the same next_cursor twice in a row; the listing would never end.');
                }
            } while ($cursor !== null);
        });
    }

    private function path(string $format, string ...$segments): string
    {
        return $this->base.$this->transport->path($format, ...$segments);
    }
}
