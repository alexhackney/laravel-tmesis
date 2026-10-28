<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Events\Fleet\FleetDesiredStateFailed;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the host app's provider what a node should run, and gives it a revision.
 *
 * The revision is the package's: the canonical document is hashed with an HMAC under
 * the app key, and the stored revision moves (by compare-and-set, in FleetStore) only
 * when that hash moves. The host app never counts, the same content never bumps, and
 * the document itself is never stored (it carries relay URLs).
 *
 * A provider that throws is not the heartbeat's failure: resolve() answers null, logs
 * the class (never the message, which can carry anything), fires
 * FleetDesiredStateFailed, and the caller sends `desired_state: null`, so the node
 * keeps what it last applied (principle 1).
 */
class DesiredStates
{
    public function __construct(
        private readonly FleetStore $store,
        private readonly Container $container,
    ) {}

    public function resolve(FleetNode $node): ?ResolvedDesired
    {
        try {
            $document = FleetConfig::provider($this->container)->desiredFor($node);
        } catch (InvalidConfigurationException $e) {
            // A wiring fault, not a bad day at the provider: loud, and still never a
            // failed heartbeat.
            report($e);
            $this->failed($node, $e);

            return null;
        } catch (Throwable $e) {
            $this->failed($node, $e);

            return null;
        }

        $revision = $this->store->revisionFor(
            $node->id,
            self::hash($document),
            $document->revocationsDigest(),
            now()->toImmutable(),
            self::previousHashes($document),
        );

        return new ResolvedDesired($document, $revision);
    }

    /**
     * The keyed hash the revision is keyed on, under the CURRENT app key.
     */
    public static function hash(DesiredDocument $document): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw InvalidConfigurationException::missing('app.key');
        }

        return hash_hmac('sha256', $document->canonical(), $key);
    }

    /**
     * The same document hashed under each of `app.previous_keys` (Laravel's key
     * rotation list, APP_PREVIOUS_KEYS). A stored hash that matches one of these is
     * the same content under a rotated key, and keeps its revision (FleetStore).
     *
     * A rotation done WITHOUT listing the old key bumps every node's revision once,
     * to identical content. That is the documented cost (see the fleet docs), not a
     * silent one: the node receives the same document at a new revision.
     *
     * @return list<string>
     */
    public static function previousHashes(DesiredDocument $document): array
    {
        $previous = config('app.previous_keys', []);

        if (! is_array($previous)) {
            return [];
        }

        $canonical = $document->canonical();
        $out = [];

        foreach ($previous as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = hash_hmac('sha256', $canonical, $key);
            }
        }

        return $out;
    }

    private function failed(FleetNode $node, Throwable $e): void
    {
        Log::error('Tmesis fleet: the desired-state provider failed; the node keeps what it last applied.', [
            'node' => $node->name,
            'node_id' => $node->id,
            'exception' => $e::class,
        ]);

        Event::dispatch(new FleetDesiredStateFailed($node, $e::class));
    }
}
