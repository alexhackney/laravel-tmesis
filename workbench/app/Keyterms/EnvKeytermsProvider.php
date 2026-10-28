<?php

declare(strict_types=1);

namespace Workbench\App\Keyterms;

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Hooks\KeytermsProvider;
use Illuminate\Support\Facades\Log;

/**
 * The reference receiver's keyterms provider: one list for every stream, from
 * `TMESIS_RECEIVER_KEYTERMS` (comma separated), and one log line per ask.
 *
 * Unset answers none, which REPLACES a stream's own captions.keyterms for the session
 * (tmesis decision D14): set it before pointing a captioning node at the route.
 */
class EnvKeytermsProvider implements KeytermsProvider
{
    public function keyterms(KeytermsHookRequest $request): array
    {
        $configured = getenv('TMESIS_RECEIVER_KEYTERMS');
        $terms = is_string($configured) && trim($configured) !== ''
            ? array_values(array_map('trim', explode(',', $configured)))
            : [];

        Log::info('keyterms.hook', [
            'node' => $request->node,
            'stream' => $request->stream,
            'session' => $request->session,
            'lang' => $request->lang,
            'answered' => count($terms),
        ]);

        return $terms;
    }
}
