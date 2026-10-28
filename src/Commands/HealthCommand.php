<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthLiveCapacity;
use AlexHackney\Tmesis\Data\Node\HealthVideoEngine;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NodeResolverException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Health\NodeProbed;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

class HealthCommand extends Command
{
    protected $signature = 'tmesis:health {--node= : One node by name} {--all : Every configured node and every node the resolver supplies} {--emit : Dispatch a NodeProbed event per node probed, for a scheduled liveness check}';

    protected $description = 'Show the health of one or every known Tmesis node';

    public function handle(TmesisClientInterface $client, NodeRegistry $registry, Dispatcher $events): int
    {
        try {
            $names = $this->nodeNames($registry);
        } catch (TmesisException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        $warnings = [];
        $failed = false;

        $emit = (bool) $this->option('emit');

        foreach ($names as $name) {
            $probedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $started = hrtime(true);

            try {
                $health = $client->node($name)->getHealth();
            } catch (TmesisException $e) {
                $failed = true;
                $rows[] = [$e->code() ?? 'error', $name, '', '', '', '', '', $e->getMessage(), '', '', '', '', '', '', ''];

                // Our own configuration failing (a blank token, a name nobody knows,
                // a resolver that threw) never reached the node, so it says nothing
                // about whether the node is up and is not emitted as down.
                if ($emit && ! $e instanceof InvalidConfigurationException && ! $e instanceof NodeResolverException) {
                    $events->dispatch(NodeProbed::unreachable($name, $e, $this->elapsedMs($started), $probedAt));
                }

                continue;
            }

            if ($emit) {
                $events->dispatch(NodeProbed::reached($name, $health, $this->elapsedMs($started), $probedAt));
            }

            if ($health->status() === 'unavailable') {
                $failed = true;
            }

            $rows[] = [
                $health->status(),
                $health->node(),
                $health->nodeRole(),
                $health->version(),
                (string) $health->contractVersion(),
                $health->ready() ? 'yes' : 'no',
                $health->draining() ? 'yes' : 'no',
                $health->ingest(),
                implode(', ', $health->degradedReasons()) ?: '-',
                (string) ($health->journal()?->outboxDepth() ?? 0),
                $this->liveCapacity($health->liveCapacity()),
                $this->deviceSessions($health->liveCapacity()),
                $this->captionSessions($health->liveCapacity()),
                $this->jobsPause($health),
                $this->encoders($health),
            ];

            foreach (['live' => $health->videoEngine(), 'jobs' => $health->jobsVideoEngine()] as $workload => $engine) {
                if ($engine !== null && ! $engine->usable()) {
                    $reason = $engine->reason();
                    $warnings[] = "[{$health->node()}] the pinned {$workload} encoder {$engine->encoder()} is not usable".($reason === '' ? '' : ": {$reason}");
                }
            }

            foreach ($health->transcription() as $transcription) {
                if ($transcription->unavailable()) {
                    $detail = $transcription->detail();
                    $warnings[] = "[{$health->node()}] the {$transcription->role()} transcription provider {$transcription->provider()} is unavailable".($detail === null || trim($detail) === '' ? '' : ": {$detail}");
                }
            }
        }

        $this->table(
            ['status', 'node', 'role', 'version', 'contract', 'ready', 'draining', 'ingest', 'degraded', 'outbox', 'live', 'device', 'captions', 'jobs pause', 'encoders'],
            $rows,
        );

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * `on/measured 3/7 production`: admission, source, units used over the limit
     * (`-` while admission is off, which is not a fault) and the unit ladder.
     */
    private function liveCapacity(HealthLiveCapacity $live): string
    {
        if ($live->admission() === '') {
            return '-';
        }

        if (! $live->applicable()) {
            return 'n/a';
        }

        $line = "{$live->admission()}/{$live->source()} {$live->encodeSlotsUsed()}/".($live->encodeSlotsTotal() ?? '-');

        return $live->unitLadder() === '' ? $line : "{$line} {$live->unitLadder()}";
    }

    /**
     * Device encoder sessions used over the ceiling, `-` for an unknown ceiling.
     */
    private function deviceSessions(HealthLiveCapacity $live): string
    {
        if ($live->admission() === '') {
            return '-';
        }

        return $live->deviceSessionsUsed().'/'.($live->deviceSessionsTotal() ?? '-');
    }

    /**
     * Self-hosted captioned sessions used over the ceiling, `-` when there is no
     * ceiling (no self-hosted captions, or the caption step was never measured).
     */
    private function captionSessions(HealthLiveCapacity $live): string
    {
        if ($live->admission() === '') {
            return '-';
        }

        $total = $live->captionSessionsTotal();

        return $total === null ? '-' : ($live->captionSessionsUsed() ?? 0).'/'.$total;
    }

    private function jobsPause(HealthFull $health): string
    {
        $jobs = $health->jobs();

        if ($jobs === null) {
            return 'no jobs';
        }

        $pause = $jobs->paused();

        if ($pause === null) {
            return '-';
        }

        return "{$pause->source()} until ".($pause->untilRaw() ?? 'cleared');
    }

    /**
     * Both encoder pins, `requested>encoder` each, ` UNUSABLE` marking a pin that failed.
     */
    private function encoders(HealthFull $health): string
    {
        $out = [];

        foreach (['live' => $health->videoEngine(), 'jobs' => $health->jobsVideoEngine()] as $workload => $engine) {
            $out[] = $workload.' '.($engine === null ? '-' : $this->engine($engine));
        }

        return implode(', ', $out);
    }

    private function engine(HealthVideoEngine $engine): string
    {
        $line = "{$engine->requested()}>{$engine->encoder()}";

        return $engine->usable() ? $line : "{$line} UNUSABLE";
    }

    private function elapsedMs(int|float $started): float
    {
        return round((hrtime(true) - $started) / 1e6, 3);
    }

    /**
     * @return list<string>
     */
    private function nodeNames(NodeRegistry $registry): array
    {
        if ($this->option('all')) {
            return $this->callable($registry);
        }

        $node = $this->option('node');

        return [is_string($node) && $node !== '' ? $node : $registry->defaultName()];
    }

    /**
     * Every known node that has an admin url. A resolver can supply receive-only
     * nodes (they heartbeat in and are never called); those are skipped by name
     * rather than reported as failures.
     *
     * @return list<string>
     */
    private function callable(NodeRegistry $registry): array
    {
        $names = [];

        foreach ($registry->names() as $name) {
            if (! is_array(config("tmesis.nodes.{$name}")) && blank($registry->get($name)->url)) {
                $this->line("Skipping [{$name}]: no admin url.");

                continue;
            }

            $names[] = $name;
        }

        return $names;
    }
}
