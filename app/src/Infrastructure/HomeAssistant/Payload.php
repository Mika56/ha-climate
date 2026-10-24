<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\InvalidPayloadException;

/**
 * Narrowing helpers for decoded JSON: turn `mixed` into precise types or fail loudly.
 */
final class Payload
{
    private function __construct()
    {
    }

    /**
     * Decodes a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidPayloadException
     */
    public static function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPayloadException('Invalid JSON received from Home Assistant: '.$e->getMessage(), 0, $e);
        }

        return self::map($decoded, 'message');
    }

    /**
     * @param array<string, mixed> $message
     *
     * @throws InvalidPayloadException
     */
    public static function encode(array $message): string
    {
        try {
            return json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new InvalidPayloadException('Cannot encode message: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPayloadException
     */
    public static function map(mixed $value, string $what): array
    {
        if (!\is_array($value)) {
            throw new InvalidPayloadException(\sprintf('Expected %s to be an object, got %s.', $what, get_debug_type($value)));
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     *
     * @throws InvalidPayloadException
     */
    public static function list(mixed $value, string $what): array
    {
        if (!\is_array($value)) {
            throw new InvalidPayloadException(\sprintf('Expected %s to be a list, got %s.', $what, get_debug_type($value)));
        }

        return array_values($value);
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return list<mixed>
     *
     * @throws InvalidPayloadException
     */
    public static function optionalList(array $map, string $key): array
    {
        $value = $map[$key] ?? null;

        return null === $value ? [] : self::list($value, $key);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPayloadException
     */
    public static function listOfMaps(mixed $value, string $what): array
    {
        return array_map(
            static fn (mixed $item): array => self::map($item, $what.' item'),
            self::list($value, $what),
        );
    }

    /**
     * @param array<string, mixed> $map
     *
     * @throws InvalidPayloadException
     */
    public static function string(array $map, string $key): string
    {
        $value = $map[$key] ?? null;
        if (!\is_string($value)) {
            throw new InvalidPayloadException(\sprintf('Expected "%s" to be a string, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @throws InvalidPayloadException
     */
    public static function optionalString(array $map, string $key): ?string
    {
        $value = $map[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new InvalidPayloadException(\sprintf('Expected "%s" to be a string or null, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     *
     * @throws InvalidPayloadException
     */
    public static function optionalMap(array $map, string $key): array
    {
        $value = $map[$key] ?? null;

        return null === $value ? [] : self::map($value, $key);
    }

    /**
     * @param array<string, mixed> $map
     *
     * @throws InvalidPayloadException
     */
    public static function optionalFloat(array $map, string $key): ?float
    {
        $value = $map[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw new InvalidPayloadException(\sprintf('Expected "%s" to be a number, got %s.', $key, get_debug_type($value)));
        }

        return (float) $value;
    }
}
