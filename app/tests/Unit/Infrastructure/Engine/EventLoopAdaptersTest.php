<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Engine;

use App\Infrastructure\Engine\RevoltTimerDriver;
use App\Infrastructure\Engine\ShutdownSignals;
use App\Tests\Double\CallRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\delay;

#[CoversClass(RevoltTimerDriver::class)]
#[CoversClass(ShutdownSignals::class)]
final class EventLoopAdaptersTest extends TestCase
{
    public function testTheDriverCallsBackAfterTheDelay(): void
    {
        $driver = new RevoltTimerDriver();
        $calls = new CallRecorder();

        $handle = $driver->delay(0.01, static function () use ($calls): void {
            $calls->record();
        });
        self::assertNotSame('', $handle);

        delay(0.05);
        self::assertCount(1, $calls->calls);
    }

    public function testANegativeDelayRunsAsSoonAsPossible(): void
    {
        $calls = new CallRecorder();

        (new RevoltTimerDriver())->delay(-5.0, static function () use ($calls): void {
            $calls->record();
        });
        delay(0.01);

        self::assertCount(1, $calls->calls);
    }

    public function testACancelledDelayNeverFires(): void
    {
        $driver = new RevoltTimerDriver();
        $calls = new CallRecorder();
        $handle = $driver->delay(0.01, static function () use ($calls): void {
            $calls->record();
        });

        $driver->cancel($handle);
        delay(0.05);

        self::assertSame([], $calls->calls);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testShutdownSignalsReturnOnSigtermAndSigint(): void
    {
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            $before = \count(EventLoop::getIdentifiers());
            EventLoop::delay(0.01, static function () use ($signal): void {
                posix_kill(posix_getpid(), $signal);
            });

            (new ShutdownSignals())->waitForShutdown();

            self::assertCount($before, EventLoop::getIdentifiers(), 'The signal handlers are removed afterwards.');
        }
    }
}
