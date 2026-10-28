<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use JsonException;
use stdClass;

/**
 * Strict reads over a fleet request body, decoded to OBJECTS.
 *
 * Objects, not associative arrays: `{}` and `[]` decode to the same PHP array under an
 * assoc decode, and the contract tells them apart (`stats` is an object, `streams` a
 * list). "Required" means PRESENT (a key with a null value is not a missing key, and
 * is refused only where the schema refuses null). Unknown fields are ignored: the node
 * is additive, and a newer node's extra field must never fail an older receiver.
 *
 * @internal
 */
final class FleetWire
{
    /**
     * @throws FleetPayloadException
     */
    public static function decode(string $raw): stdClass
    {
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FleetPayloadException('$', 'the body is not JSON');
        }

        if (! $decoded instanceof stdClass) {
            throw new FleetPayloadException('$', 'the body is not a JSON object');
        }

        return $decoded;
    }

    public static function object(stdClass $o, string $key, string $path): stdClass
    {
        $value = self::present($o, $key, $path);

        if (! $value instanceof stdClass) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be an object');
        }

        return $value;
    }

    public static function string(stdClass $o, string $key, string $path): string
    {
        $value = self::present($o, $key, $path);

        if (! is_string($value)) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be a string');
        }

        return $value;
    }

    public static function nonEmptyString(stdClass $o, string $key, string $path): string
    {
        $value = self::string($o, $key, $path);

        if ($value === '') {
            throw new FleetPayloadException("{$path}.{$key}", 'must not be empty');
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function enum(stdClass $o, string $key, string $path, array $allowed): string
    {
        $value = self::string($o, $key, $path);

        if (! in_array($value, $allowed, true)) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be one of '.implode(', ', array_map(fn (string $a): string => $a === '' ? '""' : $a, $allowed)));
        }

        return $value;
    }

    public static function integer(stdClass $o, string $key, string $path, ?int $minimum = null): int
    {
        $value = self::present($o, $key, $path);

        if (! is_int($value)) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be an integer');
        }

        if ($minimum !== null && $value < $minimum) {
            throw new FleetPayloadException("{$path}.{$key}", "must be {$minimum} or above");
        }

        return $value;
    }

    public static function boolean(stdClass $o, string $key, string $path): bool
    {
        $value = self::present($o, $key, $path);

        if (! is_bool($value)) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be a boolean');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function list(stdClass $o, string $key, string $path): array
    {
        $value = self::present($o, $key, $path);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new FleetPayloadException("{$path}.{$key}", 'must be an array');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function stringList(stdClass $o, string $key, string $path): array
    {
        $out = [];

        foreach (self::list($o, $key, $path) as $i => $item) {
            if (! is_string($item)) {
                throw new FleetPayloadException("{$path}.{$key}[{$i}]", 'must be a string');
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(stdClass $o): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) json_encode($o, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    }

    private static function present(stdClass $o, string $key, string $path): mixed
    {
        if (! property_exists($o, $key)) {
            throw new FleetPayloadException("{$path}.{$key}", 'is required');
        }

        return $o->{$key};
    }
}
