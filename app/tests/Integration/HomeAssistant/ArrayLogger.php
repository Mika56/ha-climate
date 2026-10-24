<?php

declare(strict_types=1);

namespace App\Tests\Integration\HomeAssistant;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger keeping the records in memory, for assertions.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        $this->records[] = ['level' => \is_string($level) ? $level : get_debug_type($level), 'message' => strtr((string) $message, $replacements)];
    }

    public function has(string $level): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                return true;
            }
        }

        return false;
    }

    public function hasMessageContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
