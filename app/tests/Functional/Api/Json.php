<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use PHPUnit\Framework\Assert;

/**
 * A decoded JSON value with typed accessors, so tests can walk an answer (`items.0.message`) without juggling
 * `mixed`. Every accessor fails the test when the value is not of the expected shape.
 */
final readonly class Json
{
    public function __construct(private mixed $value)
    {
    }

    /**
     * The value at a dotted path (`presence.persons.0.name`); an empty path is the value itself.
     */
    public function at(string $path): self
    {
        $value = $this->value;
        foreach ('' === $path ? [] : explode('.', $path) as $segment) {
            Assert::assertIsArray($value, \sprintf('Cannot go through "%s": not a JSON object or list.', $segment));
            Assert::assertArrayHasKey($segment, $value, \sprintf('No "%s" in the JSON (path "%s").', $segment, $path));
            $value = $value[$segment];
        }

        return new self($value);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function text(): string
    {
        Assert::assertIsString($this->value);

        return $this->value;
    }

    /**
     * @return list<mixed>
     */
    public function list(): array
    {
        Assert::assertIsArray($this->value);
        Assert::assertTrue(array_is_list($this->value), 'A JSON list was expected.');

        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(): array
    {
        Assert::assertIsArray($this->value);

        return array_combine(array_map(strval(...), array_keys($this->value)), $this->value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return array_map(static fn (mixed $row): array => (new self($row))->map(), $this->list());
    }

    /**
     * The given key of every row of a list of objects, each a string.
     *
     * @return list<string>
     */
    public function texts(string $key): array
    {
        return array_map(static fn (mixed $value): string => (new self($value))->text(), $this->column($key));
    }

    /**
     * The given key of every row of a list of objects.
     *
     * @return list<mixed>
     */
    public function column(string $key): array
    {
        return array_map(static fn (array $row): mixed => $row[$key] ?? null, $this->rows());
    }
}
