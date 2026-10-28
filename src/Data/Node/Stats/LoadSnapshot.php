<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

/**
 * What the node is doing right now: streams, child processes, and the Go
 * runtime's own accounting.
 *
 * The three counters named as caller bugs below are published rather than
 * logged on purpose: a control plane sending accounting for a stream this node
 * never opened should see that in a gauge, not in a log nobody reads.
 */
final readonly class LoadSnapshot
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

    public function activeStreams(): int
    {
        return $this->int('active_streams');
    }

    public function childProcesses(): int
    {
        return $this->int('child_processes');
    }

    public function childRestartsTotal(): int
    {
        return $this->int('child_restarts_total');
    }

    public function cpuSecondsTotal(): ?float
    {
        return $this->nullableFloat('cpu_seconds_total');
    }

    /**
     * accounting updates for a stream this node never opened: a caller bug, visible rather than logged
     */
    public function droppedUpdatesUnknownStream(): int
    {
        return $this->int('dropped_updates_unknown_stream');
    }

    /**
     * the Go runtime's own heap accounting, NOT process RSS; read process_resident_memory_bytes from /metrics for that
     */
    public function goHeapAllocBytes(): int
    {
        return $this->int('go_heap_alloc_bytes');
    }

    /**
     * memory the Go runtime obtained from the OS, NOT process RSS
     */
    public function goSysBytes(): int
    {
        return $this->int('go_sys_bytes');
    }

    public function goroutines(): int
    {
        return $this->int('goroutines');
    }

    /**
     * accounting updates refused as malformed: a caller bug, visible rather than logged
     */
    public function invalidUpdates(): int
    {
        return $this->int('invalid_updates');
    }

    public function publishRejectedTotal(): int
    {
        return $this->int('publish_rejected_total');
    }

    /**
     * streams dropped from tracking at the cardinality cap: a caller bug, visible rather than logged
     */
    public function suppressedStreams(): int
    {
        return $this->int('suppressed_streams');
    }

    public function trackedStreams(): int
    {
        return $this->int('tracked_streams');
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
