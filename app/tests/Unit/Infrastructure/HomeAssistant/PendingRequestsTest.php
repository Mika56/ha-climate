<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Infrastructure\HomeAssistant\PendingRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingRequests::class)]
#[UsesClass(CommandFailedException::class)]
final class PendingRequestsTest extends TestCase
{
    public function testIdsAreStrictlyIncreasing(): void
    {
        $requests = new PendingRequests();

        self::assertSame(1, $requests->nextId());
        self::assertSame(2, $requests->nextId());
        self::assertSame(3, $requests->nextId());
    }

    public function testResolveAnswersTheMatchingRequestOnly(): void
    {
        $requests = new PendingRequests();
        $first = $requests->register(1);
        $second = $requests->register(2);

        self::assertTrue($requests->resolve(2, 'two'));

        self::assertSame('two', $second->await());
        self::assertFalse($first->isComplete());
        self::assertSame(1, $requests->count());
    }

    public function testResolveUnknownIdReturnsFalse(): void
    {
        $requests = new PendingRequests();

        self::assertFalse($requests->resolve(42, null));
        self::assertFalse($requests->reject(42, new \RuntimeException()));
    }

    public function testAnswerIsDeliveredOnlyOnce(): void
    {
        $requests = new PendingRequests();
        $requests->register(1);

        self::assertTrue($requests->resolve(1, 'x'));
        self::assertFalse($requests->resolve(1, 'y'));
    }

    public function testRejectFailsTheRequest(): void
    {
        $requests = new PendingRequests();
        $future = $requests->register(1);

        self::assertTrue($requests->reject(1, new CommandFailedException('not_found', 'Nope')));

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessage('not_found: Nope');
        $future->await();
    }

    public function testFailAllRejectsEveryPendingRequest(): void
    {
        $requests = new PendingRequests();
        $first = $requests->register(1);
        $second = $requests->register(2);

        $requests->failAll(new ConnectionLostException('gone'));

        self::assertSame(0, $requests->count());
        foreach ([$first, $second] as $future) {
            try {
                $future->await();
                self::fail('Expected a ConnectionLostException.');
            } catch (ConnectionLostException) {
                // expected
            }
        }
    }

    public function testForgetDropsTheRequest(): void
    {
        $requests = new PendingRequests();
        $requests->register(1);

        $requests->forget(1);

        self::assertSame(0, $requests->count());
        self::assertFalse($requests->resolve(1, null));
    }
}
