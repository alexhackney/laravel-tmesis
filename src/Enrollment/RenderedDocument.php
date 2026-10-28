<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use SensitiveParameter;
use SensitiveParameterValue;

/**
 * A rendered template, held so that it cannot be logged by accident.
 *
 * A user-data document carries the box's secrets and its bootstrap token in full,
 * and a config document is returned the same way so a caller never has to know
 * which one it holds before deciding it is safe to print. The SignedRequest rules:
 *
 *  - the text is wrapped in `SensitiveParameterValue`: an `(array)` cast,
 *    `var_export()`, `json_encode()` and VarDumper show nothing of it, and
 *    `var_dump()` / `print_r()` show `__debugInfo`, which gives the mode and length;
 *  - `serialize()` THROWS;
 *  - there is no `__toString`, so interpolating one into a log line is an error;
 *  - `reveal()` is the text, for the file written with mode 0600, the one console
 *    print, or the install response. Nothing else may call it.
 */
final readonly class RenderedDocument
{
    private SensitiveParameterValue $text;

    public function __construct(
        #[SensitiveParameter] string $text,
        public RenderMode $mode,
    ) {
        $this->text = new SensitiveParameterValue($text);
    }

    public function reveal(): string
    {
        return self::unwrap($this->text);
    }

    /**
     * Whether it carries the secrets (a user-data render).
     */
    public function carriesSecrets(): bool
    {
        return $this->mode === RenderMode::UserData;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'mode' => $this->mode->value,
            'bytes' => strlen($this->reveal()),
            'text' => '[redacted]',
        ];
    }

    private static function unwrap(SensitiveParameterValue $value): string
    {
        $revealed = $value->getValue();

        return is_string($revealed) ? $revealed : '';
    }
}
