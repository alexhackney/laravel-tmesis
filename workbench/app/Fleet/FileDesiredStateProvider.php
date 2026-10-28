<?php

declare(strict_types=1);

namespace Workbench\App\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredRelay;
use AlexHackney\Tmesis\Fleet\DesiredStateProvider;
use AlexHackney\Tmesis\Fleet\DesiredStream;

/**
 * The reference receiver's desired-state provider: a JSON file, re-read on every call,
 * so a test (the `fleet-node` acceptance run) changes what the node should run by
 * rewriting the file. The path is `TMESIS_RECEIVER_FLEET_DESIRED`; no file is the empty
 * document. The shape is the wire's desired state minus the revision (the package owns
 * that):
 *
 *     {"streams": [{"id": "...", "min_key_epoch": 0, "labels": {},
 *                   "recording": {"enabled": false, "retention_hours": 72,
 *                                 "destination": null, "destination_ref": null},
 *                   "relays": [{"id": "...", "url": "srt://...", "enabled": true}]}],
 *      "revocations": [], "open_posture": false}
 *
 * A gate tool: nothing here ships to a consumer (workbench/ is dev-only).
 */
class FileDesiredStateProvider implements DesiredStateProvider
{
    public function desiredFor(FleetNode $node): DesiredDocument
    {
        $path = getenv('TMESIS_RECEIVER_FLEET_DESIRED');
        $document = DesiredDocument::make();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return $document;
        }

        /** @var array{streams?: list<array<string, mixed>>, revocations?: list<string>, open_posture?: bool} $data */
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        foreach ($data['streams'] ?? [] as $stream) {
            /** @var array{enabled?: bool, retention_hours?: int, destination?: string|null, destination_ref?: string|null} $recording */
            $recording = $stream['recording'] ?? [];
            $relays = [];

            foreach ((array) ($stream['relays'] ?? []) as $relay) {
                /** @var array{id: string, url: string, enabled?: bool} $relay */
                $relays[] = DesiredRelay::make($relay['id'], $relay['url'], $relay['enabled'] ?? true);
            }

            /** @var array<string, string> $labels */
            $labels = (array) ($stream['labels'] ?? []);

            $document->stream(DesiredStream::make(
                (string) $stream['id'],
                DesiredRecording::make((bool) ($recording['enabled'] ?? false), (int) ($recording['retention_hours'] ?? 72), $recording['destination'] ?? null, $recording['destination_ref'] ?? null),
                (int) ($stream['min_key_epoch'] ?? 0),
                $labels,
                $relays,
            ));
        }

        $document->revoke(...($data['revocations'] ?? []));

        return $document->openPosture((bool) ($data['open_posture'] ?? false));
    }
}
