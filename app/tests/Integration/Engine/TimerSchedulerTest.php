<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Timer\ArmedTimer;
use App\Application\Engine\Timer\TimerScheduler;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use App\Tests\Double\ManualTimerDriver;
use App\Tests\Double\RecordingTimerHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TimerScheduler::class)]
#[CoversClass(ArmedTimer::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class TimerSchedulerTest extends EngineTestCase
{
    private RecordingTimerHandler $handler;

    private ?TimerScheduler $scheduler = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new RecordingTimerHandler();
    }

    public function testATimerIsPersistedAndFiresAtItsInstant(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $roomId = Uuid::v7();

        $scheduler->schedule('t1', $this->in(600), 'test_timer', new RoomId($roomId->toRfc4122()), ['k' => 'v']);

        $stored = $this->repository()->findOneByKey('t1');
        self::assertInstanceOf(Timer::class, $stored);
        self::assertSame('test_timer', $stored->getType());
        self::assertSame(['k' => 'v'], $stored->getPayload());
        self::assertTrue($stored->getRoomId()?->equals($roomId));
        self::assertTrue($scheduler->isScheduled('t1'));

        $this->timers()->advance(599);
        self::assertSame([], $this->handler->handled);

        $this->timers()->advance(1);
        self::assertSame(['t1'], $this->handler->handled);
        self::assertNull($this->repository()->findOneByKey('t1'), 'A fired timer is deleted.');
        self::assertFalse($scheduler->isScheduled('t1'));
    }

    public function testSchedulingTheSameKeyMovesTheTimer(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(600), 'test_timer');

        $scheduler->schedule('t1', $this->in(1200), 'test_timer', null, ['again' => true]);

        self::assertCount(1, $this->repository()->findBy(['key' => 't1']));
        self::assertSame(['again' => true], $this->repository()->findOneByKey('t1')?->getPayload());
        $this->timers()->advance(700);
        self::assertSame([], $this->handler->handled, 'The old instant is forgotten.');
        $this->timers()->advance(500);
        self::assertSame(['t1'], $this->handler->handled);
    }

    public function testSchedulingTheSameTimerAgainChangesNothing(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $due = $this->in(600);
        $scheduler->schedule('t1', $due, 'test_timer');
        $pending = $this->timers()->pendingCount();

        $scheduler->schedule('t1', $due, 'test_timer');

        self::assertSame($pending, $this->timers()->pendingCount());
        self::assertCount(1, $this->repository()->findBy(['key' => 't1']));
    }

    public function testChangingTheTypeOfAKeyReplacesTheTimer(): void
    {
        $other = new RecordingTimerHandler('other_timer');
        $scheduler = $this->scheduler($this->handler, $other);
        $scheduler->schedule('t1', $this->in(600), 'test_timer');

        $scheduler->schedule('t1', $this->in(600), 'other_timer');

        self::assertSame('other_timer', $this->repository()->findOneByKey('t1')?->getType());
        $this->timers()->advance(600);
        self::assertSame([], $this->handler->handled);
        self::assertSame(['t1'], $other->handled);
    }

    public function testChangingTheRoomOfAKeyReplacesTheTimer(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(600), 'test_timer', new RoomId(Uuid::v7()->toRfc4122()));
        $other = Uuid::v7();

        $scheduler->schedule('t1', $this->in(600), 'test_timer', new RoomId($other->toRfc4122()));

        self::assertTrue($this->repository()->findOneByKey('t1')?->getRoomId()?->equals($other));
    }

    public function testCancelRemovesThePersistedAndTheArmedTimer(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(600), 'test_timer');

        $scheduler->cancel('t1');
        $scheduler->cancel('unknown');

        self::assertNull($this->repository()->findOneByKey('t1'));
        self::assertFalse($scheduler->isScheduled('t1'));
        $this->timers()->advance(1200);
        self::assertSame([], $this->handler->handled);
    }

    public function testRestoreRearmsFutureTimersAndFiresPastDueOnesOldestFirst(): void
    {
        $this->repository()->save(new Timer('late', 'test_timer', $this->in(-60)));
        $this->repository()->save(new Timer('later', 'test_timer', $this->in(-30)));
        $this->repository()->save(new Timer('future', 'test_timer', $this->in(300)));
        $scheduler = $this->scheduler($this->handler);

        $found = $scheduler->restore();

        self::assertSame(3, $found);
        self::assertSame(['late', 'later'], $this->handler->handled);
        self::assertNull($this->repository()->findOneByKey('late'));
        self::assertTrue($scheduler->isScheduled('future'));
        $this->timers()->advance(300);
        self::assertSame(['late', 'later', 'future'], $this->handler->handled);
    }

    public function testAHandlerMayRescheduleItsOwnTimer(): void
    {
        $handler = new RecordingTimerHandler('test_timer', function (Timer $timer): void {
            if ($timer->getDueAt() < new \DateTimeImmutable('2026-10-26 07:30:00 UTC')) {
                $this->scheduler?->schedule($timer->getKey(), $timer->getDueAt()->modify('+20 minutes'), 'test_timer');
            }
        });
        $this->scheduler = $this->scheduler($handler);
        $this->scheduler->schedule('daily', $this->in(600), 'test_timer');

        $this->timers()->advance(600);
        self::assertSame(['daily'], $handler->handled);
        self::assertInstanceOf(Timer::class, $this->repository()->findOneByKey('daily'), 'Rescheduled by the handler, not deleted.');

        $this->timers()->advance(1200);
        self::assertSame(['daily', 'daily'], $handler->handled);
        self::assertNull($this->repository()->findOneByKey('daily'));
    }

    public function testAFailingHandlerIsLoggedAndItsTimerDeleted(): void
    {
        $handler = new RecordingTimerHandler('test_timer', static function (): void {
            throw new \RuntimeException('boom');
        });
        $scheduler = $this->scheduler($handler);
        $scheduler->schedule('t1', $this->in(60), 'test_timer');

        $this->timers()->advance(60);

        self::assertSame(['t1'], $handler->handled);
        self::assertNull($this->repository()->findOneByKey('t1'));
    }

    public function testATimerOfAnUnknownTypeIsDroppedWithoutHandling(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(60), 'mystery');

        $this->timers()->advance(60);

        self::assertSame([], $this->handler->handled);
        self::assertNull($this->repository()->findOneByKey('t1'));
    }

    public function testADriverWakingUpEarlyRearmsForTheRemainder(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(600), 'test_timer');
        // The driver fires 100 seconds too soon (clock skew): the scheduler must not act yet.
        $this->clock()->sleep(500);
        self::assertTrue($this->driver()->fireEarly());
        self::assertSame([], $this->handler->handled);

        $this->timers()->advance(100);
        self::assertSame(['t1'], $this->handler->handled);
    }

    public function testDisarmAllKeepsThePersistedTimersForTheNextStart(): void
    {
        $scheduler = $this->scheduler($this->handler);
        $scheduler->schedule('t1', $this->in(600), 'test_timer');

        $scheduler->disarmAll();

        self::assertFalse($scheduler->isScheduled('t1'));
        self::assertSame(0, $this->timers()->pendingCount());
        self::assertInstanceOf(Timer::class, $this->repository()->findOneByKey('t1'));
        $this->timers()->advance(1200);
        self::assertSame([], $this->handler->handled);
    }

    public function testTwoHandlersForTheSameTypeAreRefused(): void
    {
        $scheduler = $this->scheduler($this->handler, new RecordingTimerHandler());
        $scheduler->schedule('t1', $this->in(60), 'test_timer');

        $this->timers()->advance(60);

        self::assertSame([], $this->handler->handled, 'The configuration error is logged, the timer dropped.');
    }

    private function scheduler(RecordingTimerHandler ...$handlers): TimerScheduler
    {
        return new TimerScheduler($this->repository(), $this->driver(), $this->clock(), $handlers, new NullLogger());
    }

    private function repository(): TimerRepository
    {
        return $this->service(TimerRepository::class);
    }

    private function driver(): ManualTimerDriver
    {
        return $this->timers();
    }

    private function in(int $seconds): \DateTimeImmutable
    {
        return $this->clock()->now()->modify(\sprintf('%+d seconds', $seconds));
    }
}
