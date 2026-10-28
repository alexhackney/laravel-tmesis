<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Enrollment\Bootstrap;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Mint a one-time fleet join token and print it ONCE with the exact `tmesis join` line.
 *
 * The token is printed to the console and nowhere else, never to the log; only its
 * sha256 is stored. For a box enrolled through `tmesis:enroll-node`, `--fleet` there
 * mints the same thing into the user-data instead.
 */
class FleetJoinTokenCommand extends Command
{
    protected $signature = 'tmesis:fleet:join-token
        {name : The name the fleet will know the node by (its node_name)}
        {--role= : Refuse an enroll that reports another role (edge, origin, transcoder, standalone)}
        {--label=* : key=value labels kept on the node for the app}';

    protected $description = 'Mint a one-time fleet join token for `tmesis join`';

    public function handle(FleetControlPlane $fleet): int
    {
        if (! Route::has('tmesis.fleet.enroll')) {
            $this->error('The fleet control plane is not enabled (tmesis.fleet.enabled): a join token would have nowhere to enroll.');

            return Command::FAILURE;
        }

        $labels = [];

        foreach ((array) $this->option('label') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $this->error("A label is key=value (got [{$this->stringify($pair)}]).");

                return Command::FAILURE;
            }

            [$key, $value] = explode('=', $pair, 2);
            $labels[$key] = $value;
        }

        $role = $this->option('role');

        try {
            $token = $fleet->joinToken($this->argument('name'), is_string($role) && $role !== '' ? $role : null, $labels);
        } catch (TmesisException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->warn('  Shown once, store it now: only its hash is kept.');
        $this->newLine();
        $this->line('  join token  '.$token->reveal());
        $this->line('  expires     '.$token->expiresAt->format(DATE_RFC3339).' (unused)');
        $this->newLine();
        $this->line('  On the box, with the service stopped (DEPLOY.md 5.7):');
        $this->line('    '.Bootstrap::STOP);
        $this->line('    tmesis join '.route('tmesis.fleet.enroll').' --token '.$token->reveal());
        $this->line('  On a box not yet measured (new, or upgraded in place to 0.9.0), before the start (DEPLOY.md 6.5):');
        $this->line('    '.Bootstrap::BENCH_OWNERSHIP);
        $this->line('    '.Bootstrap::LIVE_BENCH);
        $this->line('    '.Bootstrap::JOB_BENCH);
        $this->line('  Then:');
        $this->line('    '.Bootstrap::START);

        return Command::SUCCESS;
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
