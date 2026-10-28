<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use Illuminate\Console\Command;

/**
 * Debug only. Nothing is stored, and the node is never contacted: a key is
 * self-validating, so minting is pure computation over the configured secret.
 */
class MintKeyCommand extends Command
{
    protected $signature = 'tmesis:mint-key
        {stream : The stream id}
        {epoch : The KEY epoch, not the session epoch}
        {--template= : A publish URL template carrying a literal {key} to substitute into}';

    protected $description = 'Mint a Tmesis publish key (debug only, nothing is stored)';

    public function handle(StreamKeyMinter $minter): int
    {
        $stream = $this->stringArgument('stream');
        $epoch = $this->stringArgument('epoch');

        try {
            $key = $minter->mint($stream, KeyEpoch::fromString($epoch));
        } catch (TmesisException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line('  key      '.$key->toString());
        $this->line('  fragment '.$key->fragment().'   (the only form safe to log)');

        $template = $this->option('template');

        if (is_string($template) && $template !== '') {
            try {
                $this->line('  publish  '.$key->substituteInto($template));
            } catch (TmesisException $e) {
                $this->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $this->newLine();
        $this->comment('  Debug only: nothing was stored and the node was not contacted.');

        return Command::SUCCESS;
    }

    /**
     * One console argument, as a string.
     *
     * `Command::argument()` is typed `array|bool|float|int|string|null`, because a
     * signature may declare an array argument. Both of ours are declared single
     * valued, so a non-string cannot arrive; this narrows rather than casts, because
     * casting an array to string is a PHP warning and the literal "Array", and
     * because the narrowing must not depend on a static analyser being able to
     * resolve this command's signature. An empty string is refused downstream by
     * StreamId and KeyEpoch, which is the same failure path a bad value takes.
     */
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
