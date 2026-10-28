<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TableNodeResolver;
use AlexHackney\Tmesis\Enrollment\Bootstrap;
use AlexHackney\Tmesis\Enrollment\BootstrapToken;
use AlexHackney\Tmesis\Enrollment\ConfigRenderer;
use AlexHackney\Tmesis\Enrollment\EnrollmentSeams;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\RenderedDocument;
use AlexHackney\Tmesis\Enrollment\RenderMode;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Enrollment\TemplateKind;
use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Fleet\JoinToken;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use SensitiveParameterValue;

/**
 * Enrol one box: mint its secrets and bootstrap token, keep the enrolment through the
 * app's NodeRepository, render its document when there is a template, and show the
 * secrets once.
 *
 * The secrets are printed to the console and nowhere else, never to the log. A
 * config render leaves them as `${...}` for the node's `.env`; a user-data render
 * (`cloud-init.<role>.yml`, or `--mode=user-data`, which is how a shipped
 * `config.<role>.yml` is rendered with its secrets) carries them, so it is written to
 * `--out` with mode 0600 or printed once under the same warning as the `.env`
 * block. The template is rendered BEFORE anything is stored, so a refused
 * placeholder leaves no half-made enrolment behind.
 */
class EnrollNodeCommand extends Command
{
    protected $signature = 'tmesis:enroll-node
        {name : The node name: its hostname and its X-Tmesis-Node}
        {--role=edge : edge, origin, transcoder or standalone}
        {--region= : The region, for the app\'s own records}
        {--plan= : The hosting plan, for the app\'s own records}
        {--template= : A template file to render instead of the configured directory\'s}
        {--mode= : config or user-data (default: user-data for a cloud-init.* file, else config)}
        {--out= : Write the rendered document here (mode 0600) instead of printing it}
        {--replace : Re-mint the secrets of a node that is already enrolled}
        {--distinct-hook-secret : Mint a hook secret separate from the webhook secret}
        {--distinct-keyterms-secret : Mint a keyterms hook secret separate from the webhook secret}
        {--fleet : Also mint a one-time fleet join token for this name, for the user-data\'s `tmesis join`}';

    protected $description = 'Enrol a Tmesis node: mint its secrets and render its user-data';

    public function handle(Container $container, SecretMinter $minter, ConfigRenderer $renderer): int
    {
        try {
            $role = $this->role();
            $repository = EnrollmentSeams::repository($container);
            $this->refuseUnreadableDistinctSecrets($container);

            [$node, $token] = $minter->enrollment(
                $this->stringArgument('name'),
                $role,
                $this->stringOption('region'),
                $this->stringOption('plan'),
                (bool) $this->option('distinct-hook-secret'),
                (bool) $this->option('distinct-keyterms-secret'),
            );

            $secrets = EnrollmentSeams::secrets($container);
            $supplied = $renderer->suppliedSecrets($node, $secrets);

            $joinToken = $this->option('fleet') ? $this->draftJoinToken($container, $node) : null;

            $template = $this->template($renderer, $role, EnrollmentSeams::factsConfigured($container));
            $document = $template === null
                ? null
                : $renderer->render($template[0], $node, EnrollmentSeams::facts($container), $template[1], $token, $secrets, $joinToken, $template[2]);

            $repository->enroll($node, (bool) $this->option('replace'));

            // Live only once the render and the enrolment succeeded: a refused render
            // leaves no join token that could enroll anything.
            if ($joinToken !== null) {
                $container->make(FleetControlPlane::class)->storeJoinToken($joinToken);
            }
        } catch (TmesisException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("  Enrolled [{$node->name}] as {$node->role->value}.");

        $written = $this->emit($document);

        $this->shownOnce($node, $token, $document, $supplied, $joinToken ?? null);

        return $written ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * A distinct hook or keyterms hook secret is minted into the box's `.env`, but the
     * receiver verifies with whatever the node resolver reads back, and blank there
     * falls back to the webhook secret. With TableNodeResolver and no column for it,
     * nothing ever reads the distinct secret, so every hook from the box would be a
     * 401 that looks like "the hook does nothing". Refused before anything is minted
     * or stored. A custom resolver, or config-only nodes, may well carry it: allowed.
     *
     * @throws EnrollmentException naming the option and the column to configure
     */
    private function refuseUnreadableDistinctSecrets(Container $container): void
    {
        $resolver = $container->make(NodeRegistry::class)->resolver();

        if (! $resolver instanceof TableNodeResolver) {
            return;
        }

        foreach (['distinct-hook-secret' => 'hook_secret', 'distinct-keyterms-secret' => 'keyterms_hook_secret'] as $option => $column) {
            if ($this->option($option) && blank(config("tmesis.nodes_table.columns.{$column}"))) {
                throw EnrollmentException::unreadableDistinctSecret($option, "tmesis.nodes_table.columns.{$column}");
            }
        }
    }

    /**
     * The template text and its mode (--mode, else by file name): --template, else
     * the configured directory's file for the role (the bundled contract templates
     * by default), else null (the `.env` block alone). With no --template and no
     * EnrollmentFacts configured it is null too: the bundle is always there, and an
     * app that enrols without facts got the `.env` block alone before the templates
     * were bundled, so it still does.
     *
     * @return array{0: string, 1: RenderMode, 2: TemplateKind|null}|null
     *
     * @throws EnrollmentException when --template cannot be read, --mode is not a
     *                             mode, or the configured directory has no file for
     *                             the role
     */
    private function template(ConfigRenderer $renderer, NodeRole $role, bool $factsConfigured): ?array
    {
        $path = $this->stringOption('template');
        $mode = $this->stringOption('mode');
        $mode = $mode === null ? null : (RenderMode::tryFrom($mode) ?? throw EnrollmentException::invalidMode($mode));

        if ($path === null) {
            if ($renderer->directory() === null || ! $factsConfigured) {
                return null;
            }

            $path = $renderer->templatePath($role) ?? throw EnrollmentException::noTemplate($role->value, $renderer->directory());
        }

        return [ConfigRenderer::read($path), $mode ?? RenderMode::forPath($path), TemplateKind::forPath($path)];
    }

    /**
     * Write the document to --out with mode 0600, or print a config document. A
     * user-data document with no --out is printed later, in shownOnce(). False when
     * the write failed; the enrolment is kept either way and the secrets are still
     * shown.
     */
    private function emit(?RenderedDocument $document): bool
    {
        if ($document === null) {
            $this->comment('  no template, or no EnrollmentFacts to render one with; .env block only');

            return true;
        }

        $out = $this->stringOption('out');

        if ($out === null) {
            if (! $document->carriesSecrets()) {
                $this->newLine();
                $this->line($document->reveal());
            }

            return true;
        }

        // Narrow the mode before a byte is written, so the file is never readable
        // by anyone else, even for the length of the write.
        if (@touch($out) === false || @chmod($out, 0600) === false || @file_put_contents($out, $document->reveal()) === false) {
            $this->error("  Could not write the document to [{$out}].");

            return false;
        }

        $this->line("  Wrote the {$document->mode->value} document to [{$out}] (mode 0600).");

        return true;
    }

    /**
     * @param  array<string, SensitiveParameterValue>  $supplied
     */
    private function shownOnce(NodeEnrollment $node, BootstrapToken $token, ?RenderedDocument $document, array $supplied, ?JoinToken $joinToken = null): void
    {
        $this->newLine();
        $this->warn('  Shown once, store it now: these secrets are not kept in a form this command can print again.');
        $this->newLine();

        if ($document !== null && $document->carriesSecrets() && $this->stringOption('out') === null) {
            $this->line($document->reveal());
            $this->newLine();
        }

        $this->line($node->envBlock($supplied));
        $this->line('  bootstrap token  '.$token->plain());
        $this->line('  expires          '.$token->expiresAt->format(DATE_RFC3339));

        if (Route::has('tmesis.enroll.install')) {
            $this->line('  install          curl -fsS -H "Authorization: Bearer '.$token->plain().'" '.route('tmesis.enroll.install'));
        }

        if ($joinToken !== null) {
            $this->line('  fleet join token '.$joinToken->reveal());
            $this->line('  join expires     '.$joinToken->expiresAt->format(DATE_RFC3339));
            $this->line('  join (stopped)   tmesis join '.route('tmesis.fleet.enroll').' --token '.$joinToken->reveal());
        }

        // The order after install.sh --no-start (tmesis v0.9.0, handover 6.1).
        $this->line('  then, stopped    '.Bootstrap::LIVE_BENCH);
        $this->line('                   '.Bootstrap::JOB_BENCH);
        $this->line('  then             '.Bootstrap::START);
    }

    /**
     * A join token for the enrolment's name and role, drafted, not yet live.
     *
     * @throws EnrollmentException when the fleet control plane is not enabled
     */
    private function draftJoinToken(Container $container, NodeEnrollment $node): JoinToken
    {
        if (! Route::has('tmesis.fleet.enroll')) {
            throw EnrollmentException::fleetNotEnabled();
        }

        return $container->make(FleetControlPlane::class)->draftJoinToken($node->name, $node->role->value);
    }

    /**
     * @throws EnrollmentException when --role is not a role
     */
    private function role(): NodeRole
    {
        $role = $this->stringOption('role') ?? NodeRole::Edge->value;

        return NodeRole::tryFrom($role) ?? throw EnrollmentException::invalidRole($role);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
