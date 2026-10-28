<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the app-implemented enrolment seams the way the transfer signer is
 * resolved: bound in the container, else the class the config key names. The
 * repository and the facts are required where they are used; the secrets seam
 * (v0.6.0) is optional and answers null when neither is set.
 *
 * Read at use, never at boot, so a test can bind one after the provider has run.
 */
final class EnrollmentSeams
{
    public static function repositoryConfigured(Container $container): bool
    {
        return $container->bound(NodeRepository::class) || ! blank(config('tmesis.enrollment.repository'));
    }

    public static function factsConfigured(Container $container): bool
    {
        return $container->bound(EnrollmentFacts::class) || ! blank(config('tmesis.enrollment.facts'));
    }

    public static function secretsConfigured(Container $container): bool
    {
        return $container->bound(EnrollmentSecrets::class) || ! blank(config('tmesis.enrollment.secrets'));
    }

    /**
     * The optional secrets seam, or null when neither bound nor configured.
     *
     * @throws InvalidConfigurationException naming `tmesis.enrollment.secrets` when it
     *                                       is set to something that is not one
     */
    public static function secrets(Container $container): ?EnrollmentSecrets
    {
        if (! self::secretsConfigured($container)) {
            return null;
        }

        $secrets = self::resolve($container, EnrollmentSecrets::class, 'tmesis.enrollment.secrets');

        if (! $secrets instanceof EnrollmentSecrets) {
            throw self::invalid('tmesis.enrollment.secrets', EnrollmentSecrets::class);
        }

        return $secrets;
    }

    /**
     * @throws InvalidConfigurationException naming `tmesis.enrollment.repository`
     */
    public static function repository(Container $container): NodeRepository
    {
        $repository = self::resolve($container, NodeRepository::class, 'tmesis.enrollment.repository');

        if (! $repository instanceof NodeRepository) {
            throw self::invalid('tmesis.enrollment.repository', NodeRepository::class);
        }

        return $repository;
    }

    /**
     * @throws InvalidConfigurationException naming `tmesis.enrollment.facts`
     */
    public static function facts(Container $container): EnrollmentFacts
    {
        $facts = self::resolve($container, EnrollmentFacts::class, 'tmesis.enrollment.facts');

        if (! $facts instanceof EnrollmentFacts) {
            throw self::invalid('tmesis.enrollment.facts', EnrollmentFacts::class);
        }

        return $facts;
    }

    private static function resolve(Container $container, string $abstract, string $key): mixed
    {
        if ($container->bound($abstract)) {
            return $container->make($abstract);
        }

        $class = config($key);

        if (blank($class)) {
            throw InvalidConfigurationException::missing($key);
        }

        return is_string($class) && class_exists($class) ? $container->make($class) : null;
    }

    private static function invalid(string $key, string $interface): InvalidConfigurationException
    {
        return InvalidConfigurationException::invalid($key, 'it must name a class implementing '.$interface);
    }
}
