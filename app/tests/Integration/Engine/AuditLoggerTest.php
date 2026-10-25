<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Audit\AuditLogger;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
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
        $off = Decision::off(new Reason('reason.no_rule'));
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
        self::assertSame(['code' => 'reason.no_rule', 'params' => []], $byMode['off']->getPayload()['reason']);
    }

    public function testWhilePausedAChangeIsRecordedAsWhatWouldHaveBeenDone(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $comfort = Decision::heat(new \App\Domain\Climate\Temperature(21.0), new Reason('reason.schedule', ['preset' => 'comfort', 'until' => '2026-10-26T22:30:00+01:00']));
        $this->audit()->logDecisionChange($room, $comfort, 'startup', Season::Winter);

        $eco = Decision::heat(new \App\Domain\Climate\Temperature(18.0), new Reason('reason.room_vacant', ['preset' => 'eco', 'since' => '2026-10-26T06:00:00+01:00']));
        $this->audit()->logDecisionChange($room, $eco, 'state:binary_sensor.presence', Season::Winter);
        $this->audit()->logDecisionChange($room, $eco, 'schedule', Season::Winter);
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.house_empty', ['since' => '2026-10-26T06:30:00+01:00'])), 'state:person.alice', Season::Winter);

        self::assertSame([], $this->auditEntries('audit.decision.changed'));
        [$vacant, $empty] = $this->auditEntries('audit.decision.simulated');
        self::assertSame(['preset' => 'Eco', 'since' => '2026-10-26T06:00:00+01:00', 'mode' => 'heat', 'target' => 18.0, 'season' => 'winter', 'reason' => 'room_vacant'], $vacant->getParams());
        self::assertSame(['since' => '2026-10-26T06:30:00+01:00', 'mode' => 'off', 'season' => 'winter', 'reason' => 'house_empty'], $empty->getParams());
        self::assertTrue($vacant->getPayload()['simulated']);
        self::assertSame('decision', $empty->getCategory());
        self::assertSame(ActorType::Automation, $empty->getActorType());
    }

    public function testThePausedWordingSaysNothingWasSent(): void
    {
        $translator = $this->service(TranslatorInterface::class);
        $off = ['mode' => 'off', 'season' => 'winter', 'reason' => 'house_empty', 'since' => '2026-10-26T06:00:00+01:00'];
        $heat = ['mode' => 'heat', 'target' => 21.0, 'season' => 'winter', 'reason' => 'schedule', 'preset' => 'Confort', 'until' => '2026-10-26T22:30:00+01:00'];

        self::assertSame("En pause\u{00A0}: HA Climate aurait coupé le chauffage (personne à la maison). Rien n’a été envoyé.", $translator->trans('audit.decision.simulated', $off, 'audit', 'fr'));
        self::assertSame("En pause\u{00A0}: HA Climate aurait réglé le chauffage sur 21\u{00A0}°C (planning Confort). Rien n’a été envoyé.", $translator->trans('audit.decision.simulated', $heat, 'audit', 'fr'));
        self::assertSame('Paused: HA Climate would have turned the heating off (nobody home). Nothing was sent.', $translator->trans('audit.decision.simulated', $off, 'audit', 'en'));
        self::assertSame('Paused: HA Climate would have set the heating to 21 °C (Confort schedule). Nothing was sent.', $translator->trans('audit.decision.simulated', $heat, 'audit', 'en'));
        self::assertSame('Paused: HA Climate would have turned the cooling off (no device for this mode). Nothing was sent.', $translator->trans('audit.decision.simulated', ['mode' => 'off', 'season' => 'summer', 'reason' => 'no_device'], 'audit', 'en'));
    }

    public function testAChangeOfReasonAloneIsRecorded(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_schedule')), 'startup');

        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_rule')), 'reload');

        self::assertCount(1, $this->auditEntries('audit.decision.changed'));
    }

    public function testForgettingARoomMakesItsNextDecisionTheFirstAgain(): void
    {
        $room = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_schedule')), 'startup');

        $this->audit()->forget($room);
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_rule')), 'reload');

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
        $this->audit()->logDecisionChange($room, Decision::off(new Reason('reason.no_rule')), 'reload');
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
        yield 'simulated off winter' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'winter', 'reason' => 'house_empty', 'since' => '2026-10-26T06:00:00+01:00']];
        yield 'simulated off summer' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'summer', 'reason' => 'opening_open', 'since' => '2026-10-26T06:00:00+01:00']];
        yield 'simulated heat' => ['audit.decision.simulated', ['mode' => 'heat', 'target' => 21.0, 'season' => 'winter', 'reason' => 'schedule', 'preset' => 'Comfort', 'until' => '2026-10-26T22:30:00+01:00']];
        yield 'simulated cool' => ['audit.decision.simulated', ['mode' => 'cool', 'target' => 24.0, 'season' => 'summer', 'reason' => 'room_vacant', 'preset' => 'Eco', 'since' => '2026-10-26T06:00:00+01:00']];
        yield 'simulated preheat' => ['audit.decision.simulated', ['mode' => 'heat', 'target' => 21.0, 'season' => 'winter', 'reason' => 'preheat', 'preset' => 'Comfort', 'at' => '2026-10-26T06:30:00+01:00']];
        yield 'simulated vacation' => ['audit.decision.simulated', ['mode' => 'heat', 'target' => 15.0, 'season' => 'winter', 'reason' => 'vacation', 'returnAt' => '2026-11-02T18:00:00+01:00']];
        yield 'simulated boost' => ['audit.decision.simulated', ['mode' => 'heat', 'target' => 22.0, 'season' => 'winter', 'reason' => 'boost', 'delta' => 1.0, 'until' => '2026-10-26T09:00:00+01:00']];
        yield 'simulated manual' => ['audit.decision.simulated', ['mode' => 'heat', 'target' => 23.0, 'season' => 'winter', 'reason' => 'manual_override', 'until' => '2026-10-26T09:00:00+01:00']];
        yield 'simulated without device' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'summer', 'reason' => 'no_device']];
        yield 'simulated without schedule' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'winter', 'reason' => 'no_schedule']];
        yield 'simulated without rule' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'winter', 'reason' => 'no_rule']];
        yield 'simulated old season off' => ['audit.decision.simulated', ['mode' => 'off', 'season' => 'winter', 'reason' => 'season_off']];
        yield 'simulated unknown reason' => ['audit.decision.simulated', ['mode' => 'weird', 'season' => 'winter', 'reason' => 'brand_new']];
        yield 'paused' => ['audit.engine.paused', []];
        yield 'resumed' => ['audit.engine.resumed', []];
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
        yield 'alert opened' => ['audit.alert.opened', ['key' => 'entity.sensor.living_temperature', 'type' => 'entity_unavailable']];
        yield 'alert resolved' => ['audit.alert.resolved', ['key' => 'entity.sensor.living_temperature', 'type' => 'entity_unavailable']];
        yield 'notification sent' => ['audit.notification.sent', ['type' => 'window_open', 'target' => 'notify.mobile_app_phone']];
        yield 'notification failed' => ['audit.notification.failed', ['type' => 'window_open', 'target' => 'notify.mobile_app_phone', 'error' => 'boom']];
        yield 'boost cooling' => ['audit.override.boost_started', ['mode' => 'cool', 'delta' => 1.5, 'until' => '2026-10-26T09:00:00+01:00']];
        yield 'boost heating' => ['audit.override.boost_started', ['mode' => 'heat', 'delta' => 1.5, 'until' => '2026-10-26T09:00:00+01:00']];
        yield 'manual off' => ['audit.override.manual_started', ['mode' => 'off', 'until' => '2026-10-26T10:00:00+01:00']];
        yield 'manual heat' => ['audit.override.manual_started', ['mode' => 'heat', 'target' => 24.0, 'until' => '2026-10-26T10:00:00+01:00']];
        yield 'manual cool' => ['audit.override.manual_started', ['mode' => 'cool', 'target' => 24.0, 'until' => '2026-10-26T10:00:00+01:00']];
        yield 'override cancelled' => ['audit.override.cancelled', ['type' => 'boost']];
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
