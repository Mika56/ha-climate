<?php

declare(strict_types=1);

namespace App\Application\Api\Exception;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collects the violations found while checking a request against the current data, then raises them all at
 * once as a {@see ValidationException}. Messages are translated (domain `validators`) in the request locale.
 */
final class Violations
{
    /** @var list<array{path: string, message: string}> */
    private array $violations = [];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function add(string $path, string $messageId, array $parameters = []): void
    {
        $this->violations[] = ['path' => $path, 'message' => $this->translator->trans($messageId, $parameters, 'validators')];
    }

    /**
     * @throws ValidationException when something was added
     */
    public function throwIfAny(): void
    {
        if ([] !== $this->violations) {
            throw new ValidationException($this->violations);
        }
    }
}
