<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\InvalidPayloadException;
use App\Application\Port\HomeAssistant\StateContext;

/**
 * Decodes the compressed event format of the `subscribe_entities` command.
 *
 * Event keys (all optional, unknown keys are ignored):
 *  - "a": added/initial entities, `{entity_id: {"s", "a", "c", "lc", "lu"}}`
 *  - "c": changed entities, `{entity_id: {"+": {partial s/a/c/lc/lu}, "-": {"a": [removed attribute keys]}}}`
 *  - "r": removed entity ids
 *
 * "c" (context) is either the context id or an object `{"id", "parent_id", "user_id"}`; "lu" absent
 * means "same as lc". The decoder is pure: the caller keeps the cache of full states between events.
 */
final class CompressedStateDecoder
{
    /**
     * @param array<string, mixed>       $payload   the `event` object of the WS message
     * @param array<string, EntityState> $cache     full states known before this event
     * @param bool                       $isInitial true for the first event of a (re)subscription: cached
     *                                              entities absent from "a" are then reported as removed
     *
     * @throws InvalidPayloadException
     */
    public function decode(array $payload, array $cache, bool $isInitial = false): DecodedStateUpdate
    {
        $changes = [];

        $added = Payload::optionalMap($payload, 'a');
        foreach ($added as $entityId => $raw) {
            $new = $this->decodeFullState($entityId, Payload::map($raw, 'entity '.$entityId));
            $old = $cache[$entityId] ?? null;
            $cache[$entityId] = $new;
            if (null === $old || !self::isSameState($old, $new)) {
                $changes[] = new EntityStateChange($entityId, $old, $new);
            }
        }

        if ($isInitial) {
            foreach ($cache as $entityId => $old) {
                if (!\array_key_exists($entityId, $added)) {
                    unset($cache[$entityId]);
                    $changes[] = new EntityStateChange($entityId, $old, null);
                }
            }
        }

        foreach (Payload::optionalMap($payload, 'c') as $entityId => $raw) {
            $old = $cache[$entityId] ?? null;
            if (null === $old) {
                continue; // diff for an entity we never saw: nothing to rebuild from
            }
            $new = $this->applyDiff($old, Payload::map($raw, 'diff of '.$entityId));
            $cache[$entityId] = $new;
            $changes[] = new EntityStateChange($entityId, $old, $new);
        }

        foreach (Payload::optionalList($payload, 'r') as $entityId) {
            if (!\is_string($entityId)) {
                throw new InvalidPayloadException('Removed entity id must be a string.');
            }
            $old = $cache[$entityId] ?? null;
            if (null === $old) {
                continue;
            }
            unset($cache[$entityId]);
            $changes[] = new EntityStateChange($entityId, $old, null);
        }

        return new DecodedStateUpdate($changes, $cache);
    }

    /**
     * Builds a state from the `states` of the `get_states` command or a REST-like state object.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidPayloadException
     */
    public static function fromStateObject(array $raw): EntityState
    {
        $lastChanged = self::parseDate(Payload::string($raw, 'last_changed'));
        $lastUpdated = null !== Payload::optionalString($raw, 'last_updated')
            ? self::parseDate(Payload::string($raw, 'last_updated'))
            : $lastChanged;

        return new EntityState(
            Payload::string($raw, 'entity_id'),
            Payload::string($raw, 'state'),
            Payload::optionalMap($raw, 'attributes'),
            $lastChanged,
            $lastUpdated,
            self::parseContext($raw['context'] ?? null),
        );
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function parseContext(mixed $raw): StateContext
    {
        if (\is_string($raw)) {
            return new StateContext($raw);
        }

        $map = Payload::map($raw, 'context');

        return new StateContext(
            Payload::string($map, 'id'),
            Payload::optionalString($map, 'parent_id'),
            Payload::optionalString($map, 'user_id'),
        );
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function fromEpoch(float $timestamp): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $timestamp));
        if (false === $date) {
            throw new InvalidPayloadException(\sprintf('Invalid timestamp %s.', $timestamp));
        }

        return $date;
    }

    /**
     * @throws InvalidPayloadException
     */
    private static function parseDate(string $date): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            throw new InvalidPayloadException(\sprintf('Invalid date "%s".', $date), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidPayloadException
     */
    private function decodeFullState(string $entityId, array $raw): EntityState
    {
        $lastChanged = Payload::optionalFloat($raw, 'lc');
        $lastUpdated = Payload::optionalFloat($raw, 'lu');
        $lastChanged ??= $lastUpdated;
        if (null === $lastChanged) {
            throw new InvalidPayloadException(\sprintf('Entity %s has neither "lc" nor "lu".', $entityId));
        }

        return new EntityState(
            $entityId,
            Payload::string($raw, 's'),
            Payload::optionalMap($raw, 'a'),
            self::fromEpoch($lastChanged),
            self::fromEpoch($lastUpdated ?? $lastChanged),
            self::parseContext($raw['c'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $diff
     *
     * @throws InvalidPayloadException
     */
    private function applyDiff(EntityState $old, array $diff): EntityState
    {
        $state = $old->state;
        $attributes = $old->attributes;
        $context = $old->context;
        $lastChanged = $old->lastChanged;
        $lastUpdated = $old->lastUpdated;

        $plus = Payload::optionalMap($diff, '+');
        if (\array_key_exists('s', $plus)) {
            $state = Payload::string($plus, 's');
        }
        foreach (Payload::optionalMap($plus, 'a') as $key => $value) {
            $attributes[$key] = $value;
        }
        if (\array_key_exists('c', $plus)) {
            $context = self::parseContext($plus['c']);
        }
        $newLastChanged = Payload::optionalFloat($plus, 'lc');
        $newLastUpdated = Payload::optionalFloat($plus, 'lu');
        if (null !== $newLastChanged) {
            $lastChanged = self::fromEpoch($newLastChanged);
            $lastUpdated = self::fromEpoch($newLastUpdated ?? $newLastChanged);
        } elseif (null !== $newLastUpdated) {
            $lastUpdated = self::fromEpoch($newLastUpdated);
        }

        $minus = Payload::optionalMap($diff, '-');
        foreach (Payload::optionalList($minus, 'a') as $key) {
            if (\is_string($key)) {
                unset($attributes[$key]);
            }
        }

        return new EntityState($old->entityId, $state, $attributes, $lastChanged, $lastUpdated, $context);
    }

    private static function isSameState(EntityState $a, EntityState $b): bool
    {
        return $a->state === $b->state
            && $a->attributes === $b->attributes
            && $a->context->id === $b->context->id
            && $a->context->parentId === $b->context->parentId
            && $a->context->userId === $b->context->userId
            && $a->lastChanged->format('U.u') === $b->lastChanged->format('U.u')
            && $a->lastUpdated->format('U.u') === $b->lastUpdated->format('U.u');
    }
}
