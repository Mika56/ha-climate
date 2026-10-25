<?php

declare(strict_types=1);

namespace App\Application\Api\Exception;

/**
 * A request the API refuses, with the HTTP status and the stable machine code of the contract's error format.
 */
abstract class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
