<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Enrollment\ConfigRenderer;
use AlexHackney\Tmesis\Enrollment\NodeRole;

/**
 * Enrolment refused something.
 *
 * Messages name placeholders, node names and template paths, and never a secret, a
 * bootstrap token or any part of a rendered document.
 */
class EnrollmentException extends TmesisException
{
    /**
     * A `${NAME}` the template uses that neither the facts nor the node's `.env`
     * fills. The document is refused rather than handed out with `${` left in.
     */
    public static function unfilledPlaceholder(string $name): self
    {
        return new self("The enrolment template uses [\${{$name}}], which no enrolment fact fills and which is not reserved.");
    }

    /**
     * A fact named after one of the node's `.env` secrets. Facts are the NON-secret
     * values; the secrets reach the box through its `.env` and nowhere else.
     */
    public static function reservedPlaceholder(string $name): self
    {
        return new self("The enrolment facts supply [{$name}], which is reserved: the node's .env secrets and its bootstrap token are never supplied as facts.");
    }

    /**
     * A user-data template that names `${TMESIS_BOOTSTRAP_TOKEN}`, rendered with no
     * token to put there.
     */
    public static function bootstrapTokenRequired(): self
    {
        return new self('The user-data template uses [${TMESIS_BOOTSTRAP_TOKEN}] and no bootstrap token was given to render it with.');
    }

    /**
     * A user-data template names the fleet join token and none was minted: the
     * command was run without --fleet.
     */
    public static function joinTokenRequired(): self
    {
        return new self('The user-data template uses [${TMESIS_FLEET_JOIN_TOKEN}] and no fleet join token was given to render it with (run tmesis:enroll-node with --fleet).');
    }

    /**
     * --fleet asked for a join token and the fleet control plane is not enabled here.
     */
    public static function fleetNotEnabled(): self
    {
        return new self('--fleet mints a fleet join token, and the fleet control plane is not enabled (tmesis.fleet.enabled).');
    }

    public static function invalidMode(string $mode): self
    {
        return new self("The render mode [{$mode}] is not one of config, user-data.");
    }

    public static function invalidFact(string $name): self
    {
        return new self("The enrolment fact [{$name}] must be a string.");
    }

    /**
     * The EnrollmentSecrets seam answered a name it may not supply, or a value that
     * is not a string. Names the entry, never the value.
     */
    public static function invalidSecret(string $name): self
    {
        return new self("The enrolment secrets supply [{$name}], which is not one of ".implode(', ', ConfigRenderer::SUPPLIED_SECRETS).' with a string value.');
    }

    /**
     * A placeholder fact the template does not name (tmesis `v0.8.0`): it would be
     * dropped, so it is refused. The bundled templates name secrets only since then,
     * and a non-secret is a value fact keyed by its config path.
     */
    /**
     * A secret a user-data render cannot write where its template names it so that
     * the node reads back the same value. Names the variable and why, never the value.
     */
    public static function unplaceableSecret(string $name, string $why): self
    {
        return new self("The enrolment template's [\${{$name}}] cannot be filled in a user-data render: {$why}.");
    }

    /**
     * The node's own sentence (`internal/ingest/encryption.go` `PassphraseProblem`).
     * Never the value, nor its length.
     */
    public static function invalidSrtPassphrase(): self
    {
        return new self("The enrolment secrets supply TMESIS_SRT_PASSPHRASE, and TMESIS_SRT_PASSPHRASE is outside SRT's 10 to 79 bytes.");
    }

    public static function unreadableDistinctSecret(string $option, string $column): self
    {
        return new self("--{$option} mints a secret the receiver could never read: the node resolver is TableNodeResolver and [{$column}] is not set, so verification would fall back to the webhook secret and refuse every such hook. Configure the column, or enrol without the option.");
    }

    public static function unusedPlaceholder(string $name): self
    {
        return new self("The enrolment facts supply the placeholder [{$name}], which the template does not use. Since tmesis v0.8.0 the bundled templates carry plain values: set it through EnrollmentFacts::values() keyed by its config path (webhooks.url, node.name).");
    }

    /**
     * A value fact naming a config path the template does not carry. Refused rather
     * than dropped, so a render never goes out missing what the app meant to set.
     */
    public static function unknownPath(string $path, string $role): self
    {
        return new self("The enrolment fact [{$path}] names a setting the template rendered for the [{$role}] role does not carry; it is refused rather than dropped.");
    }

    /**
     * A value fact aimed at a mapping, a block sequence or a block scalar.
     */
    public static function notAValue(string $path, string $kind): self
    {
        return new self("The enrolment fact [{$path}] names a {$kind} in the template, not a single value; set the values under it by their own paths.");
    }

    /**
     * A value fact at, or inside, a span the renderer cannot read exactly (a
     * multi-line scalar, a block scalar, a nested block sequence, an anchor or tag, a
     * flow collection it could not parse, a duplicated key). It is refused rather than
     * edited by a guess.
     */
    public static function unreadableValue(string $path, string $span): self
    {
        return new self("The enrolment fact [{$path}] falls at or inside [{$span}], which the template writes in a form the renderer cannot edit exactly; it is refused rather than guessed.");
    }

    /**
     * A value fact aimed at a key whose template value is a reserved `${NAME}`: a
     * secret, which reaches the box through its `.env` and never as a fact.
     */
    public static function reservedPath(string $path): self
    {
        return new self("The enrolment fact [{$path}] names a setting the template fills from a reserved .env secret; secrets are never supplied as facts.");
    }

    public static function invalidPath(string $path): self
    {
        return new self("The enrolment fact [{$path}] is not a config path: dot-separated keys and indices, such as webhooks.url or relays.0.url.");
    }

    /**
     * Names the path, never the value.
     */
    public static function invalidValue(string $path): self
    {
        return new self("The enrolment fact [{$path}] must be a string, number, bool or a list of those, and a string may not carry \${, which the node would interpolate.");
    }

    /**
     * @param  list<string>  $paths
     */
    public static function overlappingPaths(array $paths): self
    {
        return new self('The enrolment facts set values that overlap (a sequence and one of its items): ['.implode('], [', $paths).'].');
    }

    public static function noTemplate(string $role, ?string $directory): self
    {
        if ($directory === null) {
            return new self("No enrolment template is configured for the [{$role}] role: set tmesis.enrollment.templates or pass a template.");
        }

        return new self("No enrolment template for the [{$role}] role in [{$directory}]: expected cloud-init.{$role}.yml or config.{$role}.yml.");
    }

    public static function unreadableTemplate(string $path): self
    {
        return new self("The enrolment template [{$path}] cannot be read.");
    }

    public static function invalidName(string $name): self
    {
        return new self("The node name [{$name}] is not a hostname: it is the node's X-Tmesis-Node, lower case letters, digits, dots and hyphens.");
    }

    public static function invalidRole(string $role): self
    {
        return new self("The node role [{$role}] is not one of ".implode(', ', NodeRole::values()).'.');
    }

    public static function alreadyEnrolled(string $name): self
    {
        return new self("The node [{$name}] is already enrolled; enrol it with replace to re-mint its secrets.");
    }

    public static function invalidRegistration(string $field): self
    {
        return new self("The node registration's [{$field}] is invalid.");
    }
}
