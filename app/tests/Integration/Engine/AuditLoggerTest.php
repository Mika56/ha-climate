<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Audit\AuditLogger;
use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Enum\ActorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AuditLogger::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class AuditLoggerTest extends EngineTestCase
{
    public function testLogsAnEntryWithItsRoomActorAndPayload(): void
    {
        $room = $this->createRoom();

        $this->audit()->log(ActorType::User, 'settings', 'audit.configuration.reloaded', ['rooms' => 1, 'entities' => 4], new RoomId($room->getId()->toRfc4122()), ['why' => 'test'], 'user-1');
        $this->audit()->log(ActorType::System, 'engine', 'audit.connection.lost');

        [$withRoom, $withoutRoom] = $this->auditEntries();
        self::assertSame('Living room', $withRoom->getRoomName());
        self::assertSame($room->getId()->toRfc4122(), $withRoom->getRoom()?->getId()->toRfc4122());
        self::assertSame(ActorType::User, $withRoom->getActorType());
        self::assertSame('user-1', $withRoom->getActorId());
        self::assertSame(['why' => 'test'], $withRoom->getPayload());
        self::assertSame('2026-10-26 07:00:00', $withRoom->getOccurredAt()->format('Y-m-d H:i:s'));
        self::assertNull($withoutRoom->getRoom());
        self::assertSame([], $withoutRoom->getParams());
    }

    public function testADecisionIsOnlyRecordedWhenItChanges(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $comfort = Decision::heat(new \App\Domain\Climate\Temperature(21.0), new Reason('reason.schedule', ['preset' => 'comfort', 'until' => '22:30']));

        $this->audit()->logDecisionChange($room, $comfort, 'startup');
        $this->audit()->logDecisionChange($room, $comfort, 'state:sensor.x');
        self::assertSame([], $this->auditEntries('audit.decision.changed'), 'The first decision of a room is not news.');

        $night = Decision::heat(new \App\Domain\Climate\Temperature(17.0), new Reason('reason.schedule', ['preset' => 'night', 'until' => '06:30']));
        $this->audit()->logDecisionChange($room, $night, 'schedule');
        $this->audit()->logDecisionChange($room, $night, 'schedule');
        $off = Decision::off(new Reason('reason.season_off'));
        $this->audit()->logDecisionChange($room, $off, 'reload');

        $entries = $this->auditEntries('audit.decision.changed');
        self::assertCount(2, $entries);
        $byMode = [];
        foreach ($entries as $entry) {
            $byMode[self::auditString($entry, 'mode')] = $entry;
        }
        self::assertSame(['mode' => 'heat', 'target' => 17.0], $byMode['heat']->getParams());
        self::assertSame('schedule', $byMode['heat']->getPayload()['cause']);
        self::assertSame(['mode' => 'heat', 'target' => 21.0, 'reason' => ['code' => 'reason.schedule', 'params' => ['preset' => 'comfort', 'until' => '22:30']]], $byMode['heat']->getPayload()['previous']);
        self::assertSame(['mode' => HvacMode::Off->value], $byMode['off']->getParams());
        self::assertSame(['code' => 'reason.season_off', 'params' => []], $byMode['off']->getPayload()['reason']);
    }

    public function testAChangeOfReasonAloneIsRecorded(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_schedule')), 'startup');

        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.season_off')), 'reload');

        self::assertCount(1, $this->auditEntries('audit.decision.changed'));
    }

    public function testForgettingARoomMakesItsNextDecisionTheFirstAgain(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_schedule')), 'startup');

        $this->audit()->forget($room);
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.season_off')), 'reload');

        self::assertSame([], $this->auditEntries('audit.decision.changed'));
    }

    public function testAnEvaluationFailureIsRecordedOncePerDistinctProblem(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());

        $this->audit()->logEvaluationFailure($room, 'No temperature');
        $this->audit()->logEvaluationFailure($room, 'No temperature');
        self::assertCount(1, $this->auditEntries('audit.evaluation.failed'));

        $this->audit()->logEvaluationFailure($room, 'Other problem');
        self::assertCount(2, $this->auditEntries('audit.evaluation.failed'));

        // Once the room works again, a new failure is news again.
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.season_off')), 'reload');
        $this->audit()->logEvaluationFailure($room, 'Other problem');
        self::assertCount(3, $this->auditEntries('audit.evaluation.failed'));
    }

    /**
     * Every audit key the code can write must be translated in each supported language.
     *
     * @param array<string, scalar|null> $params
     */
    #[DataProvider('auditMessages')]
    public function testEveryAuditKeyIsTranslatedInEnglishAndFrench(string $key, array $params): void
    {
        $translator = $this->service(TranslatorInterface::class);

        foreach (['en', 'fr'] as $locale) {
            $text = $translator->trans($key, $params, 'audit', $locale);
            self::assertNotSame($key, $text, \sprintf('"%s" is not translated in %s.', $key, $locale));
            self::assertStringNotContainsString('{', $text, \sprintf('"%s" has an unresolved placeholder in %s: %s', $key, $locale, $text));
            self::assertStringNotContainsString('=', $text);
        }
        self::assertNotSame($translator->trans($key, $params, 'audit', 'en'), $translator->trans($key, $params, 'audit', 'fr'));
    }

    /**
     * @return iterable<string, array{string, array<string, scalar|null>}>
     */
    public static function auditMessages(): iterable
    {
        yield 'engine started' => ['audit.engine.started', ['rooms' => 2, 'timers' => 1, 'overrides' => 0, 'commands' => 3]];
        yield 'decision heat' => ['audit.decision.changed', ['mode' => 'heat', 'target' => 21.5]];
        yield 'decision cool' => ['audit.decision.changed', ['mode' => 'cool', 'target' => 24.0]];
        yield 'decision off' => ['audit.decision.changed', ['mode' => 'off']];
        yield 'command heat' => ['audit.command.sent', ['device' => 'climate.a', 'service' => 'climate.set_temperature', 'mode' => 'heat', 'target' => 21.0]];
        yield 'command cool' => ['audit.command.sent', ['device' => 'climate.a', 'service' => 'climate.set_temperature', 'mode' => 'cool', 'target' => 24.0]];
        yield 'command off' => ['audit.command.sent', ['device' => 'climate.a', 'service' => 'climate.set_hvac_mode', 'mode' => 'off', 'target' => null]];
        yield 'command failed' => ['audit.command.failed', ['device' => 'climate.a', 'service' => 'climate.set_temperature', 'error' => 'boom']];
        yield 'command deferred' => ['audit.command.deferred', ['device' => 'climate.a', 'until' => '2026-10-26T08:00:00+01:00', 'reason' => 'reason.min_off']];
        yield 'manual change' => ['audit.manual_change.detected', ['device' => 'climate.a', 'oldMode' => 'heat', 'newMode' => 'heat', 'oldTemperature' => '21 °C', 'newTemperature' => '24 °C']];
        yield 'evaluation failed' => ['audit.evaluation.failed', ['error' => 'boom']];
        yield 'maintenance' => ['audit.maintenance.done', ['audit' => 12, 'commands' => 1, 'overrides' => 0]];
        yield 'reloaded' => ['audit.configuration.reloaded', ['rooms' => 2, 'entities' => 9]];
        yield 'connection lost' => ['audit.connection.lost', []];
        yield 'connection restored' => ['audit.connection.restored', []];
    }

    public function testTheTestedKeysAreExactlyThoseUsedInTheSources(): void
    {
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src'));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                preg_match_all("/'(audit\\.[a-z_]+(?:\\.[a-z_]+)+)'/", (string) file_get_contents($file->getPathname()), $matches);
                $used = [...$used, ...$matches[1]];
            }
        }
        $tested = array_unique(array_map(static fn (array $case): string => $case[0], [...self::auditMessages()]));

        $used = array_unique($used);
        sort($used);
        sort($tested);
        self::assertSame($used, $tested, 'Add the new audit key to auditMessages() and to both audit translation files.');
    }

    private function audit(): AuditLogger
    {
        return $this->service(AuditLogger::class);
    }
}
