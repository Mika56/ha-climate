<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks JSON against the OpenAPI 3.1 contract (`api/openapi.yaml`, whose schemas are JSON Schema 2020-12).
 *
 * The whole document is registered as one schema resource, so the `#/components/schemas/...` references of the
 * operations resolve against it. Operations are found by `operationId`.
 */
final class OpenApiContract
{
    private const string BASE = 'https://ha-climate.test/openapi';

    private static ?self $instance = null;

    private readonly Validator $validator;

    /** @var array<string, array{path: string, method: string}> */
    private array $operations = [];

    private function __construct(private readonly \stdClass $document)
    {
        $this->validator = new Validator();
        $this->validator->resolver()?->registerRaw($document, self::BASE);

        foreach (self::properties($document->paths ?? null) as $path => $item) {
            foreach (self::properties($item) as $method => $operation) {
                $id = self::walk($operation, 'operationId');
                if (\is_string($id)) {
                    $this->operations[$id] = ['path' => $path, 'method' => $method];
                }
            }
        }
    }

    public static function load(): self
    {
        if (null === self::$instance) {
            $document = Yaml::parseFile(\dirname(__DIR__, 3).'/api/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
            if (!$document instanceof \stdClass) {
                throw new \LogicException('The contract is not a YAML mapping.');
            }
            self::$instance = new self($document);
        }

        return self::$instance;
    }

    /**
     * @return array<string, array{path: string, method: string}> every operation of the contract by operationId
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Whether the status is documented for the operation.
     */
    public function documents(string $operationId, int $status): bool
    {
        return null !== $this->response($operationId, $status);
    }

    /**
     * Whether the response of that status documents a body of the given media type.
     */
    public function documentsMediaType(string $operationId, int $status, string $mediaType): bool
    {
        return null !== self::walk($this->response($operationId, $status), 'content', $mediaType);
    }

    /**
     * Validates a decoded JSON body (objects as \stdClass) against the JSON schema the contract gives for the
     * operation's response of this status.
     *
     * @return list<string> the problems found (empty when the body matches)
     */
    public function validateJson(string $operationId, int $status, mixed $body): array
    {
        $response = $this->response($operationId, $status);
        if (null === $response) {
            return [\sprintf('Status %d is not documented for %s.', $status, $operationId)];
        }

        $schema = self::walk($response, 'content', 'application/json', 'schema');
        if (!$schema instanceof \stdClass) {
            return [\sprintf('%s %d documents no JSON body.', $operationId, $status)];
        }

        return $this->problems($body, $schema);
    }

    /**
     * Validates a request body against the schema the contract documents for the operation.
     *
     * @return list<string>
     */
    public function validateRequest(string $operationId, mixed $body): array
    {
        $schema = self::walk($this->operation($operationId), 'requestBody', 'content', 'application/json', 'schema');
        if (!$schema instanceof \stdClass) {
            return [\sprintf('%s documents no JSON request body.', $operationId)];
        }

        return $this->problems($body, $schema);
    }

    /**
     * @return list<string>
     */
    private function problems(mixed $body, \stdClass $schema): array
    {
        $absolute = self::absolute($schema);
        if (!$absolute instanceof \stdClass) {
            throw new \LogicException('A schema stays an object.');
        }
        $result = $this->validator->validate($body, $absolute);
        $error = $result->error();
        if (null === $error) {
            return [];
        }

        $problems = [];
        foreach ((new ErrorFormatter())->format($error, true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $problems[] = \sprintf('%s: %s', '' === $pointer ? '/' : $pointer, \is_string($message) ? $message : 'invalid');
            }
        }

        return [] === $problems ? ['The body does not match the schema.'] : $problems;
    }

    private function response(string $operationId, int $status): ?\stdClass
    {
        $response = self::walk($this->operation($operationId), 'responses', (string) $status);
        $reference = self::walk($response, '$ref');
        if (\is_string($reference)) {
            $response = $this->pointer($reference);
        }

        return $response instanceof \stdClass ? $response : null;
    }

    private function operation(string $operationId): \stdClass
    {
        $location = $this->operations[$operationId] ?? throw new \InvalidArgumentException(\sprintf('The contract has no operation "%s".', $operationId));
        $operation = self::walk($this->document, 'paths', $location['path'], $location['method']);

        return $operation instanceof \stdClass ? $operation : throw new \LogicException('Operation lost.');
    }

    private function pointer(string $reference): mixed
    {
        $node = $this->document;
        foreach (explode('/', ltrim($reference, '#/')) as $segment) {
            $node = self::walk($node, str_replace(['~1', '~0'], ['/', '~'], $segment));
        }

        return $node;
    }

    /**
     * The property reached by following the given names from a node; null as soon as one is missing.
     */
    private static function walk(mixed $node, string ...$names): mixed
    {
        foreach ($names as $name) {
            if (!$node instanceof \stdClass) {
                return null;
            }
            $properties = get_object_vars($node);
            $node = $properties[$name] ?? null;
        }

        return $node;
    }

    /**
     * Copy of a schema whose local references point at the registered document.
     */
    private static function absolute(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($node) as $key => $value) {
                $copy->{$key} = '$ref' === $key && \is_string($value) && str_starts_with($value, '#') ? self::BASE.$value : self::absolute($value);
            }

            return $copy;
        }

        return \is_array($node) ? array_map(self::absolute(...), $node) : $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(mixed $node): array
    {
        if (!$node instanceof \stdClass) {
            return [];
        }

        $properties = [];
        foreach (get_object_vars($node) as $name => $value) {
            $properties[(string) $name] = $value;
        }

        return $properties;
    }
}
