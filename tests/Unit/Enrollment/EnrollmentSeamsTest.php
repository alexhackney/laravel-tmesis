<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\EnrollmentSeams;
use AlexHackney\Tmesis\Enrollment\EnrollmentSecrets;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRepository;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;

final class SeamsTestFacts implements EnrollmentFacts
{
    public function values(NodeEnrollment $node): array
    {
        return [];
    }

    public function placeholders(NodeEnrollment $node): array
    {
        return [];
    }
}

it('reports neither seam configured by default', function (): void {
    expect(EnrollmentSeams::repositoryConfigured(app()))->toBeFalse()
        ->and(EnrollmentSeams::factsConfigured(app()))->toBeFalse();
});

it('refuses an unconfigured repository, naming the key', function (): void {
    EnrollmentSeams::repository(app());
})->throws(InvalidConfigurationException::class, '[tmesis.enrollment.repository] is required');

it('refuses unconfigured facts, naming the key', function (): void {
    EnrollmentSeams::facts(app());
})->throws(InvalidConfigurationException::class, '[tmesis.enrollment.facts] is required');

it('resolves a bound repository', function (): void {
    $repository = ArrayNodeRepository::fake();

    expect(EnrollmentSeams::repositoryConfigured(app()))->toBeTrue()
        ->and(EnrollmentSeams::repository(app()))->toBe($repository);
});

it('resolves the classes the config names', function (): void {
    config()->set('tmesis.enrollment.repository', ArrayNodeRepository::class);
    config()->set('tmesis.enrollment.facts', SeamsTestFacts::class);

    expect(EnrollmentSeams::repository(app()))->toBeInstanceOf(ArrayNodeRepository::class)
        ->and(EnrollmentSeams::facts(app()))->toBeInstanceOf(SeamsTestFacts::class)
        ->and(EnrollmentSeams::factsConfigured(app()))->toBeTrue();
});

it('refuses a configured class that is not the seam', function (string $key, string $class): void {
    config()->set($key, $class);

    $key === 'tmesis.enrollment.repository' ? EnrollmentSeams::repository(app()) : EnrollmentSeams::facts(app());
})->with([
    ['tmesis.enrollment.repository', SeamsTestFacts::class],
    ['tmesis.enrollment.repository', 'App\\Missing'],
    ['tmesis.enrollment.facts', ArrayNodeRepository::class],
])->throws(InvalidConfigurationException::class, 'it must name a class implementing');

it('prefers a binding over the config', function (): void {
    config()->set('tmesis.enrollment.repository', 'App\\Missing');
    app()->instance(NodeRepository::class, $repository = new ArrayNodeRepository);

    expect(EnrollmentSeams::repository(app()))->toBe($repository);
});

it('answers null for the optional secrets seam when neither bound nor configured', function (): void {
    expect(EnrollmentSeams::secretsConfigured(app()))->toBeFalse()
        ->and(EnrollmentSeams::secrets(app()))->toBeNull();
});

it('resolves the secrets seam bound or named in the config, and refuses a class that is not one', function (): void {
    config()->set('tmesis.enrollment.secrets', SeamsTestSecrets::class);

    expect(EnrollmentSeams::secretsConfigured(app()))->toBeTrue()
        ->and(EnrollmentSeams::secrets(app()))->toBeInstanceOf(SeamsTestSecrets::class);

    $bound = new SeamsTestSecrets;
    app()->instance(EnrollmentSecrets::class, $bound);

    expect(EnrollmentSeams::secrets(app()))->toBe($bound);

    app()->forgetInstance(EnrollmentSecrets::class);
    app()->offsetUnset(EnrollmentSecrets::class);
    config()->set('tmesis.enrollment.secrets', SeamsTestFacts::class);

    expect(fn () => EnrollmentSeams::secrets(app()))
        ->toThrow(InvalidConfigurationException::class, 'it must name a class implementing');
});

final class SeamsTestSecrets implements EnrollmentSecrets
{
    public function secrets(NodeEnrollment $node): array
    {
        return [];
    }
}
