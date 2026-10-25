<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\OpenApiContract;
use PHPUnit\Framework\Assert;
use Symfony\Component\BrowserKit\Response;

/**
 * An answer of the API with the helpers the tests need: decoded JSON and the contract check.
 */
final readonly class TestResponse
{
    public function __construct(private Response $response)
    {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        return $this->response->getContent();
    }

    public function header(string $name): ?string
    {
        $value = $this->response->getHeader($name);

        return \is_string($value) ? $value : null;
    }

    public function data(): Json
    {
        return new Json(json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        return array_combine(array_map(strval(...), array_keys($decoded)), $decoded);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonList(): array
    {
        $decoded = json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);
        Assert::assertTrue(array_is_list($decoded), 'A JSON array was expected.');

        return array_map(static function (mixed $item): array {
            Assert::assertIsArray($item);

            return array_combine(array_map(strval(...), array_keys($item)), $item);
        }, $decoded);
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), 'Unexpected status, body: '.$this->body());

        return $this;
    }

    /**
     * @return $this
     */
    public function assertError(int $status, string $code): self
    {
        $this->assertStatus($status);
        Assert::assertSame($code, $this->json()['error'] ?? null);

        return $this;
    }

    public function assertViolation(string $path): self
    {
        $this->assertStatus(422);
        $paths = $this->data()->at('violations')->column('path');
        Assert::assertContains($path, $paths, 'No violation on '.$path.', got: '.$this->body());

        return $this;
    }

    /**
     * The body matches the contract for the operation and the status of the answer. Bodies of other media
     * types than JSON (the CSV export, the empty 204/202) are only checked to be documented.
     */
    public function assertMatchesContract(string $operationId): self
    {
        $contract = OpenApiContract::load();
        Assert::assertTrue($contract->documents($operationId, $this->status()), \sprintf('The contract does not document %d for %s.', $this->status(), $operationId));

        if ('' === $this->body() || !str_starts_with((string) $this->header('Content-Type'), 'application/json') || !$contract->documentsMediaType($operationId, $this->status(), 'application/json')) {
            return $this;
        }

        $problems = $contract->validateJson($operationId, $this->status(), json_decode($this->body(), false, 512, \JSON_THROW_ON_ERROR));
        Assert::assertSame([], $problems, \sprintf("The answer to %s does not match the contract:\n%s\n%s", $operationId, implode("\n", $problems), $this->body()));

        return $this;
    }
}
