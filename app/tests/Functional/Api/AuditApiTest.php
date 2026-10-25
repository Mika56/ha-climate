<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ApiTime;
use App\Application\Api\Audit\AuditEntryNormalizer;
use App\Application\Api\Audit\AuditMessageRenderer;
use App\Application\Api\Audit\AuditService;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Input\AuditQuery;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\AuditController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(AuditController::class)]
#[CoversClass(AuditService::class)]
#[CoversClass(AuditEntryNormalizer::class)]
#[CoversClass(AuditMessageRenderer::class)]
#[CoversClass(AuditQuery::class)]
#[CoversClass(AuditEntryRepository::class)]
#[CoversClass(ApiTime::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(ValidationException::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class AuditApiTest extends ApiTestCase
{
    private string $roomId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomId = $this->createRoom();
        $this->em()->createQuery('DELETE FROM '.AuditEntry::class.' e')->execute();
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function entry(string $at, string $key, array $params = [], ActorType $actor = ActorType::Automation, bool $withRoom = true, ?string $actorName = null): void
    {
        $room = $withRoom ? $this->em()->find(Room::class, $this->roomId) : null;
        $this->em()->persist(new AuditEntry(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), $actor, 'decision', $key, $params, [], 'actor-id', $actorName, $room));
        $this->em()->flush();
        $this->em()->clear();
    }

    public function testTheEntriesAreListedNewestFirstWithTheirMessageInTheRequestLocale(): void
    {
        $this->entry('2026-10-26 06:00:00', 'audit.decision.changed', ['mode' => 'heat', 'target' => 21.5]);
        $this->entry('2026-10-26 07:00:00', 'audit.decision.changed', ['mode' => 'off'], ActorType::System, false, 'Engine');

        $english = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'en'])->assertStatus(200)->data();
        $french = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'fr'])->data();

        self::assertSame(2, $english->at('total')->value());
        self::assertSame(['Switched off', 'Heating set to 21.5 °C'], $english->at('items')->column('message'));
        self::assertSame(['Arrêt', 'Chauffage réglé à 21,5 °C'], $french->at('items')->column('message'));
        $first = $english->at('items.0');
        self::assertSame('2026-10-26T08:00:00+01:00', $first->at('occurredAt')->value());
        self::assertSame('system', $first->at('actorType')->value());
        self::assertSame('Engine', $first->at('actorName')->value());
        self::assertNull($first->at('roomId')->value());
        self::assertNull($first->at('roomName')->value());
        self::assertSame(['mode' => 'off'], $first->at('params')->value());
        $second = $english->at('items.1');
        self::assertSame($this->roomId, $second->at('roomId')->value());
        self::assertSame('Living room', $second->at('roomName')->value());
        self::assertSame('actor-id', $second->at('actorName')->value(), 'Without a name the actor id is shown.');
    }

    public function testEmptyParametersStayAnObject(): void
    {
        $this->entry('2026-10-26 06:00:00', 'audit.connection.lost');

        $response = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'en'])->assertStatus(200);

        self::assertStringContainsString('"params":{}', $response->body());
        self::assertStringContainsString('Connection to Home Assistant lost.', $response->body());
    }

    public function testTheFiltersAndThePagingApply(): void
    {
        foreach (range(0, 4) as $minute) {
            $this->entry(\sprintf('2026-10-26 06:0%d:00', $minute), 'audit.connection.lost');
        }
        $this->entry('2026-10-26 06:30:00', 'audit.connection.lost', [], ActorType::User, false);

        $page = $this->call('searchAudit', 'GET', '/api/audit?limit=2&offset=1')->assertStatus(200)->data();
        self::assertSame(6, $page->at('total')->value());
        self::assertSame(['2026-10-26T07:04:00+01:00', '2026-10-26T07:03:00+01:00'], $page->at('items')->column('occurredAt'));

        self::assertSame(1, $this->call('searchAudit', 'GET', '/api/audit?actorType=user')->json()['total']);
        self::assertSame(5, $this->call('searchAudit', 'GET', '/api/audit?roomId='.$this->roomId)->json()['total']);
        self::assertSame(0, $this->call('searchAudit', 'GET', '/api/audit?roomId=0199e3a0-0000-7000-8000-000000000000')->json()['total']);
        self::assertSame(3, $this->call('searchAudit', 'GET', '/api/audit?from='.rawurlencode('2026-10-26T07:01:00+01:00').'&to='.rawurlencode('2026-10-26T06:03:00Z'))->json()['total']);
    }

    public function testBadFiltersAreRefused(): void
    {
        $this->call('searchAudit', 'GET', '/api/audit?limit=0')->assertViolation('limit');
        $this->call('searchAudit', 'GET', '/api/audit?limit=201')->assertViolation('limit');
        $this->call('searchAudit', 'GET', '/api/audit?offset=-1')->assertViolation('offset');
        $this->call('searchAudit', 'GET', '/api/audit?roomId=nope')->assertViolation('roomId');
        $this->call('searchAudit', 'GET', '/api/audit?actorType=ghost')->assertViolation('actorType');
        $this->call('searchAudit', 'GET', '/api/audit?from=yesterday-ish')->assertViolation('from');
    }

    public function testInstantsInTheParametersAreShownInLocalTime(): void
    {
        $this->clock()->modify('2026-10-26 12:00:00 UTC');
        $this->entry('2026-10-26 06:00:00', 'audit.command.deferred', ['device' => 'climate.living', 'until' => '2026-10-26T08:30:00+00:00']);
        $this->entry('2026-10-26 06:01:00', 'audit.command.deferred', ['device' => 'climate.living', 'until' => '2026-10-27T08:30:00Z']);

        $english = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'en'])->data()->at('items');
        $french = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'fr'])->data()->at('items');

        self::assertSame(['Command to climate.living postponed until 2026-10-27 09:30.', 'Command to climate.living postponed until 09:30.'], $english->column('message'));
        self::assertSame('Commande vers climate.living reportée jusqu’à 27/10/2026 09:30.', $french->at('0.message')->value());
    }

    public function testAnUnknownKeyShowsItselfAndAMissingParameterItsPlaceholder(): void
    {
        $this->entry('2026-10-26 06:00:00', 'audit.something.new', ['a' => 1]);
        $this->entry('2026-10-26 06:01:00', 'audit.command.deferred', ['device' => 'x']);
        $this->entry('2026-10-26 06:02:00', 'audit.alert.opened', ['key' => 'k', 'type' => 't', 'flag' => true, 'nothing' => null, 'list' => 'not-a-date']);

        $messages = $this->call('searchAudit', 'GET', '/api/audit', null, ['Accept-Language' => 'en'])->data()->at('items')->column('message');

        self::assertSame(['Alert k opened (t).', 'Command to x postponed until {until}.', 'audit.something.new'], $messages, 'An unknown key is shown as is, a missing parameter leaves its placeholder.');
    }

    public function testTheTrailIsExportedAsAStreamedCsvAttachment(): void
    {
        $this->entry('2026-10-26 06:00:00', 'audit.decision.changed', ['mode' => 'heat', 'target' => 20.0], ActorType::Automation, true, '=HYPERLINK("x")');
        $this->entry('2026-10-26 07:00:00', 'audit.connection.lost', [], ActorType::System, false);

        $response = $this->call('exportAudit', 'GET', '/api/audit/export?actorType=automation', null, ['Accept-Language' => 'fr'])->assertStatus(200);

        self::assertStringStartsWith('text/csv', (string) $response->header('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", $response->body()), static fn (string $line): bool => '' !== $line));
        self::assertSame(['occurredAt', 'actorType', 'actorName', 'room', 'category', 'messageKey', 'message'], $rows[0]);
        self::assertCount(2, $rows);
        self::assertSame(['2026-10-26T07:00:00+01:00', 'automation', "'=HYPERLINK(\"x\")", 'Living room', 'decision', 'audit.decision.changed', 'Chauffage réglé à 20 °C'], $rows[1]);
    }

    public function testALongTrailIsExportedPageByPage(): void
    {
        $room = $this->em()->find(Room::class, $this->roomId);
        for ($index = 0; $index < 503; ++$index) {
            $this->em()->persist(new AuditEntry(new \DateTimeImmutable('@'.(1790000000 + $index)), ActorType::System, 'engine', 'audit.connection.lost', [], [], null, null, $room));
        }
        $this->em()->flush();
        $this->em()->clear();

        $response = $this->call('exportAudit', 'GET', '/api/audit/export')->assertStatus(200);

        self::assertCount(504, array_filter(explode("\n", $response->body()), static fn (string $line): bool => '' !== $line));
        $this->call('exportAudit', 'GET', '/api/audit/export?roomId=0199e3a0-0000-7000-8000-000000000000')->assertStatus(200);
    }
}
