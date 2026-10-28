<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Resources\Streams;
use Illuminate\Console\Command;

class StreamsCommand extends Command
{
    protected $signature = 'tmesis:streams {--node= : One node by name} {--state= : Filter by stream state}';

    protected $description = 'List the streams in a Tmesis node registry';

    public function handle(TmesisClientInterface $client, NodeRegistry $registry): int
    {
        $state = $this->option('state');

        // Validated here rather than at the node, so a typo is a message instead of
        // a 422 round trip.
        if (is_string($state) && $state !== '' && ! in_array($state, Streams::STATES, true)) {
            $this->error("[{$state}] is not a stream state. Use one of: ".implode(', ', Streams::STATES).'.');

            return Command::FAILURE;
        }

        $node = $this->option('node');
        $node = is_string($node) && $node !== '' ? $node : $registry->defaultName();

        $list = $client->node($node)->listStreams(is_string($state) && $state !== '' ? $state : null);

        $this->table(
            ['id', 'state', 'session', 'epoch', 'protocol', 'ladder', 'delivery', 'version'],
            array_map(fn (StreamRecord $stream): array => [
                $stream->id(),
                $stream->state(),
                $stream->session() ?? '-',
                $stream->epoch()?->toString() ?? '-',
                $stream->protocol() ?? '-',
                $stream->ladder(),
                $stream->deliveryMode(),
                (string) $stream->version(),
            ], $list->streams()),
        );

        $this->line('  '.$list->count().' stream(s) on ['.$node.'].');

        return Command::SUCCESS;
    }
}
