<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * GET /api/v1/streams/:id/events: the stream timeline.
 *
 * The rows are the most recent `limit()` inside the bounds the call gave, read
 * OLDEST FIRST by default since rc7 (`order=asc`; `desc` for newest first). The
 * first element of events() is therefore the oldest row of the window, not the
 * newest.
 *
 * @see EventListResponse in docs/contract/openapi.json at v0.1.0-rc2. The class
 * keeps the name a Laravel codebase reads better; the component is the pin.
 */
final readonly class StreamEventList
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function stream(): string
    {
        $value = $this->raw['stream'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function count(): int
    {
        $value = $this->raw['count'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The limit the node applied. Defaults to 200 and is capped at 1000.
     */
    public function limit(): int
    {
        $value = $this->raw['limit'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<StreamEvent>
     */
    public function events(): array
    {
        $events = $this->raw['events'] ?? null;

        if (! is_array($events)) {
            return [];
        }

        $out = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                /** @var array<string, mixed> $event */
                $out[] = StreamEvent::fromArray($event);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
