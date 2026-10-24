<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\StateContext;
use App\Infrastructure\HomeAssistant\CompressedStateDecoder;
use App\Infrastructure\HomeAssistant\DecodedStateUpdate;
use App\Infrastructure\HomeAssistant\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The decoder against the `subscribe_entities` events of a real Home Assistant 2026.10.3 (the dev instance of
 * compose.dev.yaml), captured while entities were changed through its REST API. The only edit is the user id,
 * replaced by a made-up one. Event 1 is the initial burst, the next ones follow the REST calls:
 *
 *  2-3  input_boolean toggled on (and the template binary_sensor following it)   bare string context
 *  4    climate set to heat, then hvac_action idle -> heating with no new context   partial attributes, "lu" only
 *  5-7  a state written over REST with attributes added then removed                "-" attribute removals
 *  8    climate turned off
 *  9-10 the toggles back off
 */
#[CoversClass(CompressedStateDecoder::class)]
#[CoversClass(DecodedStateUpdate::class)]
#[CoversClass(Payload::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(EntityStateChange::class)]
#[UsesClass(StateContext::class)]
final class CompressedStateDecoderRealPayloadTest extends TestCase
{
    private const string USER = '0123456789abcdef0123456789abcdef';

    /** @var array<string, EntityState> */
    private array $cache = [];

    public function testTheInitialBurstIsDecoded(): void
    {
        $update = $this->feed(0);

        self::assertCount(5, $update->changes);
        $climate = $this->cache['climate.living_room_heater'];
        self::assertSame('off', $climate->state);
        self::assertSame(22, $climate->attributes['temperature']);
        self::assertSame(21.5, $climate->attributes['current_temperature']);
        self::assertSame(['heat', 'off'], $climate->attributes['hvac_modes']);
        self::assertEquals(new StateContext('01M5PX9EZN30R49847VT4VBM41', null, self::USER), $climate->context);
        // The initial burst has "lc" only: the last update is the last change.
        self::assertSame('1792844020.726324', $climate->lastChanged->format('U.u'));
        self::assertEquals($climate->lastChanged, $climate->lastUpdated);

        $bedtime = $this->cache['input_datetime.bedtime'];
        self::assertSame('23:15:00', $bedtime->state);
        self::assertTrue($bedtime->attributes['has_time']);
        self::assertSame(23, $bedtime->attributes['hour']);
        self::assertSame(15, $bedtime->attributes['minute']);
    }

    public function testABareContextIdInADiffKeepsTheUserOfThePreviousContext(): void
    {
        $this->feed(0);

        $update = $this->feed(1);

        self::assertCount(1, $update->changes);
        $window = $this->cache['input_boolean.living_window'];
        self::assertSame('on', $window->state);
        self::assertSame('01M5PXBEWQ68TEEBRTXEWFNJTV', $window->context->id);
        self::assertSame(self::USER, $window->context->userId, 'The diff only has the new id: the user did not change.');
        self::assertNull($window->context->parentId);
        self::assertSame('1792844086.167732', $window->lastChanged->format('U.u'));
        self::assertSame('off', $update->changes[0]->old?->state);
        self::assertSame('01M5PX9G0FSAG0GNK7YMG3R1YG', $update->changes[0]->old->context->id);
    }

    public function testAnAttributeOnlyDiffKeepsTheRestAndUpdatesTheLastUpdate(): void
    {
        $this->feed(0);
        $this->feed(1);
        $this->feed(2);

        $this->feed(3);
        $heating = $this->cache['climate.living_room_heater'];
        self::assertSame('heat', $heating->state);
        self::assertSame('idle', $heating->attributes['hvac_action']);
        self::assertSame(22, $heating->attributes['temperature'], 'Attributes absent from the diff are kept.');
        self::assertSame(self::USER, $heating->context->userId);

        $this->feed(4);
        $heating = $this->cache['climate.living_room_heater'];
        self::assertSame('heating', $heating->attributes['hvac_action']);
        self::assertSame('01M5PXBGY6BZGHTXYKXSKV1H16', $heating->context->id, 'No context in the diff: unchanged.');
        self::assertSame('1792844088.263467', $heating->lastChanged->format('U.u'), '"lu" alone does not move the last change.');
        self::assertSame('1792844088.267720', $heating->lastUpdated->format('U.u'));
    }

    public function testAttributesAreAddedAndRemovedByDiffs(): void
    {
        $this->feed(0);

        $this->feed(5);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'x'], $this->cache['sensor.fake']->attributes, 'Added "a", "b", "c", removed the leftover "d".');

        $this->feed(6);
        self::assertSame(['a' => 1], $this->cache['sensor.fake']->attributes);
        self::assertSame('1', $this->cache['sensor.fake']->state);

        $this->feed(7);
        self::assertSame(['a' => 3, 'd' => 4], $this->cache['sensor.fake']->attributes);
        self::assertSame('2', $this->cache['sensor.fake']->state);
    }

    public function testTheWholeRecordingReplaysToTheFinalStatesHomeAssistantReported(): void
    {
        for ($event = 0; $event < $this->events(); ++$event) {
            $this->feed($event);
        }

        self::assertSame('off', $this->cache['input_boolean.living_window']->state);
        self::assertSame('off', $this->cache['binary_sensor.living_room_window']->state);
        self::assertSame('off', $this->cache['climate.living_room_heater']->state);
        self::assertSame('off', $this->cache['climate.living_room_heater']->attributes['hvac_action']);
        self::assertSame('2', $this->cache['sensor.fake']->state);
        self::assertSame('01M5PXBR3K9ARMAFZCPBV83Z74', $this->cache['input_boolean.living_window']->context->id);
        self::assertSame(self::USER, $this->cache['input_boolean.living_window']->context->userId);
    }

    private function events(): int
    {
        return \count(self::recording());
    }

    private function feed(int $index): DecodedStateUpdate
    {
        $update = (new CompressedStateDecoder())->decode(self::recording()[$index], $this->cache, 0 === $index);
        $this->cache = $update->cache;

        return $update;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recording(): array
    {
        $lines = file(__DIR__.'/../../../Fixtures/HomeAssistant/subscribe_entities_2026.10.3.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(static function (string $line): array {
            /** @var array<string, mixed> $event */
            $event = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

            return $event;
        }, $lines);
    }
}
