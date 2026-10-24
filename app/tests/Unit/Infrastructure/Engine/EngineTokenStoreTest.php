<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Engine;

use App\Infrastructure\Engine\EngineTokenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineTokenStore::class)]
final class EngineTokenStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/engine-token-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (self::files($this->directory) as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testEnsureCreatesAPrivateRandomTokenFileAndCreatesItsDirectory(): void
    {
        $store = new EngineTokenStore($this->directory.'/engine.token');

        $token = $store->ensure();

        self::assertSame(64, \strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, file_get_contents($this->directory.'/engine.token'));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->directory.'/engine.token')), -4));
        self::assertSame([$this->directory.'/engine.token'], self::files($this->directory), 'No temporary file is left behind.');
    }

    public function testEnsureKeepsAnExistingToken(): void
    {
        $store = new EngineTokenStore($this->directory.'/engine.token');
        $first = $store->ensure();

        self::assertSame($first, $store->ensure());
        self::assertSame($first, $store->read());
    }

    public function testAnEmptyFileCountsAsMissing(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/engine.token', "\n");
        $store = new EngineTokenStore($this->directory.'/engine.token');

        self::assertNull($store->read());
        self::assertSame(64, \strlen($store->ensure()));
    }

    public function testReadReturnsNullWhenThereIsNoFile(): void
    {
        self::assertNull((new EngineTokenStore($this->directory.'/missing.token'))->read());
    }

    public function testMatchesComparesTheCandidateWithTheStoredToken(): void
    {
        $store = new EngineTokenStore($this->directory.'/engine.token');
        self::assertFalse($store->matches('anything'), 'Without a token nothing matches, not even the empty string.');
        self::assertFalse($store->matches(''));

        $token = $store->ensure();

        self::assertTrue($store->matches($token));
        self::assertFalse($store->matches($token.'x'));
        self::assertFalse($store->matches(''));
    }

    public function testEnsureFailsCleanlyWhenTheFileCannotBeWritten(): void
    {
        mkdir($this->directory);
        // The target is a directory: the final rename cannot succeed.
        mkdir($this->directory.'/engine.token');
        $store = new EngineTokenStore($this->directory.'/engine.token');

        try {
            $store->ensure();
            self::fail('A RuntimeException was expected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot write the engine token file', $e->getMessage());
        } finally {
            rmdir($this->directory.'/engine.token');
        }
        self::assertSame([], self::files($this->directory));
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = glob($directory.'/*');

        return false === $files ? [] : $files;
    }
}
