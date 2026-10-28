<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * One node's connection settings.
 *
 * Nothing here is trimmed. A secret with surrounding whitespace is a DIFFERENT secret
 * and changes every HMAC it computes; silently trimming would make this package
 * disagree with the node about every signature. `tmesis:doctor` reports it instead.
 */
final readonly class NodeConfig
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $token = null,
        public ?string $webhookSecret = null,
        /**
         * The secret for synchronous publish hooks. Always null in v0.1: the hook
         * endpoint is v0.2. The field exists so the secret resolver already has the
         * shape it will need, with webhookSecret as the documented fallback.
         */
        public ?string $hookSecret = null,
        /**
         * The secret for the keyterms hook GET (tmesis v0.8.0,
         * `transcription.keyterms_hook.secret`), webhookSecret when null.
         */
        public ?string $keytermsHookSecret = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $url = $config['url'] ?? '';

        return new self(
            name: $name,
            url: is_string($url) ? rtrim($url, '/') : '',
            token: self::string($config, 'token'),
            webhookSecret: self::string($config, 'webhook_secret'),
            hookSecret: self::string($config, 'hook_secret'),
            keytermsHookSecret: self::string($config, 'keyterms_hook_secret'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
