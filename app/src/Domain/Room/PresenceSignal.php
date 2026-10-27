<?php

declare(strict_types=1);

namespace App\Domain\Room;

use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;

/**
 * One way to tell that a room is in use: an entity and the rule that makes it "active".
 *
 * - `active`: the entity is `on` (`playing`, `paused` or `on` for a media player); only for the domains in
 *   {@see self::ACTIVE_DOMAINS};
 * - `above`: the numeric state is strictly above the threshold;
 * - `equals`: the state is exactly the value.
 */
final readonly class PresenceSignal
{
    public const int MAX_VALUE_LENGTH = 255;

    /** Domains whose `on` state means activity. */
    public const array ACTIVE_DOMAINS = ['binary_sensor', 'light', 'switch', 'input_boolean', 'fan', 'media_player'];

    private const array MEDIA_PLAYER_ACTIVE_STATES = ['playing', 'paused', 'on'];

    public function __construct(
        public EntityId $entityId,
        public PresenceSignalKind $kind,
        public ?float $threshold = null,
        public ?string $value = null,
    ) {
        switch ($kind) {
            case PresenceSignalKind::Active:
                if (!\in_array($entityId->domain(), self::ACTIVE_DOMAINS, true)) {
                    throw new InvalidValueException(\sprintf('"%s" cannot be an "active" presence signal: its domain must be one of %s.', $entityId, implode(', ', self::ACTIVE_DOMAINS)));
                }
                self::forbid(null !== $threshold || null !== $value, $kind);
                break;
            case PresenceSignalKind::Above:
                if (null === $threshold || !is_finite($threshold)) {
                    throw new InvalidValueException('An "above" presence signal needs a finite threshold.');
                }
                self::forbid(null !== $value, $kind);
                break;
            case PresenceSignalKind::Equals:
                if (null === $value || '' === trim($value) || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                    throw new InvalidValueException(\sprintf('An "equals" presence signal needs a value of 1 to %d characters.', self::MAX_VALUE_LENGTH));
                }
                self::forbid(null !== $threshold, $kind);
                break;
        }
    }

    /**
     * Whether this signal says "somebody is here" for the given state. Only meaningful for a usable state: an
     * unavailable or unknown state must be ignored by the caller.
     */
    public function isActive(string $state): bool
    {
        return match ($this->kind) {
            PresenceSignalKind::Active => 'media_player' === $this->entityId->domain()
                ? \in_array($state, self::MEDIA_PLAYER_ACTIVE_STATES, true)
                : 'on' === $state,
            PresenceSignalKind::Above => is_numeric($state) && (float) $state > $this->threshold,
            PresenceSignalKind::Equals => $state === $this->value,
        };
    }

    private static function forbid(bool $extra, PresenceSignalKind $kind): void
    {
        if ($extra) {
            throw new InvalidValueException(\sprintf('A "%s" presence signal takes neither a threshold nor a value that is not its own.', $kind->value));
        }
    }
}
