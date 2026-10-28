<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;

/**
 * The default keyterms provider: none, for every stream.
 *
 * Bind your own before pointing a node's `transcription.keyterms_hook.url` at the
 * route: this answer REPLACES the stream's own `captions.keyterms` for the session
 * (decision D14), so with the node's hook on and this provider in place a stream
 * captions with no keyterms whatever it was PATCHed with.
 */
final class NoKeyterms implements KeytermsProvider
{
    public function keyterms(KeytermsHookRequest $request): array
    {
        return [];
    }
}
