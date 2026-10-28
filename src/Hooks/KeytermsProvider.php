<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;

/**
 * Answers the keyterms hook (tmesis `v0.8.0`, `docs/WEBHOOKS.md` 13): a captioning
 * stream's vocabulary, the show's fight card (fighter names, promotion, venue, city),
 * in the order the provider should get them.
 *
 * Name the class in `tmesis.hooks.keyterms.provider` (`TMESIS_KEYTERMS_PROVIDER`);
 * it is resolved from the container. The node asks at the session's open and every
 * `transcription.keyterms_hook.interval_seconds` (30 by default) after, under a
 * 2000 ms timeout by default, and never waits: captions open with the stream's own
 * `captions.keyterms` and are never interrupted by this.
 *
 * **What an answer means (decision D14).** Once the node has an answer in a session,
 * it REPLACES the stream's `captions.keyterms` for that session, including an answer
 * of none (`[]`), and a later PATCH of the stream does not displace it. So return
 * the list the session should caption with, not a delta.
 *
 * The route holds the answer to the node's keyterm rules before it leaves
 * (Support\Keyterms): each term is trimmed and duplicates removed case-insensitively
 * (keeping the first spelling and the order), which the node would do too; more than
 * 100 terms, an empty one, one over 50 characters after trimming, one with a control
 * character or a non-string is REFUSED, because the node would ignore the whole list:
 * the route reports an InvalidKeytermsException and answers `500`, so the list in
 * force stays. The provider's own token cap (Deepgram's, about 500) is the node's to
 * judge.
 *
 * Throwing is allowed: the route answers `503` (never an empty list, which would
 * clear the session's keyterms) and logs the provider and the exception CLASS, never
 * the message. A provider that wants its failure reported reports it itself.
 */
interface KeytermsProvider
{
    /**
     * @return list<string>
     */
    public function keyterms(KeytermsHookRequest $request): array;
}
