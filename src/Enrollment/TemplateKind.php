<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * Which of an EnrollmentFacts' two answers a template takes, by its FILE.
 *
 *  - Config: a `config.<role>.yml` (the bundled contract copy or a
 *    `tmesis.enrollment.templates` override). EnrollmentFacts::values() set its plain
 *    YAML values, and a values() path it does not carry is refused. placeholders()
 *    fill any `${NAME}` it names; one it does not name is left to the app's own
 *    template, and refused only when the role HAS no app template
 *    (ConfigRenderer::hasAppTemplate()), so a fact no document uses still fails.
 *  - App: a `cloud-init.<role>.yml`, the app's own template. placeholders() fill it
 *    and one it does not name is refused; values() are not applied, because they
 *    belong to the config document.
 *
 * Any other file name (a one-off `--template` file) has NO kind: it is the only
 * document of that render, so it takes both answers and is held to both checks, as
 * before the kinds existed.
 *
 * So one EnrollmentFacts serves both documents of the two-document flow
 * (`enroll/install` serves the app's cloud-init, `enroll/config` the role's config).
 * The kind is never the RenderMode: `--mode=user-data` over a `config.<role>.yml` is
 * still a config document.
 */
enum TemplateKind: string
{
    case Config = 'config';
    case App = 'app';

    /**
     * By file name: `config.*` is a config document, `cloud-init.*` an app template,
     * anything else null (both checks; ConfigRenderer::render()'s `$kind`).
     */
    public static function forPath(string $path): ?self
    {
        return match (true) {
            str_starts_with(basename($path), 'config.') => self::Config,
            str_starts_with(basename($path), 'cloud-init.') => self::App,
            default => null,
        };
    }
}
