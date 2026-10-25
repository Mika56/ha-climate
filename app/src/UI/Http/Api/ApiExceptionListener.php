<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\Exception\ApiException;
use App\Application\Api\Exception\ValidationException;
use App\Infrastructure\Engine\EngineRejectedException;
use App\Infrastructure\Engine\EngineUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns every failure of an `/api` request into the contract's JSON: `{error, message}` (404 `not_found`,
 * 409 with the code of the conflict, 503 `engine_unavailable`...) or, for an invalid request,
 * `{error: "validation_failed", violations: [{path, message}]}`. Never a stack trace.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $event->setResponse($this->respond($event->getThrowable()));
    }

    private function respond(\Throwable $e): Response
    {
        if ($e instanceof ValidationException) {
            return self::validationFailed($e->violations);
        }
        if ($e instanceof ApiException) {
            return self::error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        if ($e instanceof EngineUnavailableException) {
            $this->logger->warning('The engine does not answer: {message}', ['message' => $e->getMessage()]);

            return self::error(503, 'engine_unavailable', 'The engine does not answer, try again in a moment.');
        }
        if ($e instanceof EngineRejectedException) {
            return $this->engineRejected($e);
        }
        if ($e instanceof HttpExceptionInterface) {
            return $this->http($e);
        }

        $this->logger->error('Unexpected failure of an API request: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

        return self::error(500, 'internal_error', 'Something went wrong.');
    }

    private function http(HttpExceptionInterface $e): Response
    {
        $previous = $e->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = ['path' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }

            return self::validationFailed($violations);
        }

        $status = $e->getStatusCode();
        if (400 === $status) {
            // Malformed JSON, or a query string the serializer cannot read.
            return self::validationFailed([['path' => '', 'message' => $this->translator->trans('This value should be valid JSON.', [], 'validators')]]);
        }

        $code = match ($status) {
            404 => 'not_found',
            405 => 'method_not_allowed',
            415 => 'unsupported_media_type',
            default => 'http_error',
        };
        $response = self::error($status, $code, '' === $e->getMessage() ? 'The request cannot be served.' : $e->getMessage());
        $response->headers->add($e->getHeaders());

        return $response;
    }

    private function engineRejected(EngineRejectedException $e): Response
    {
        if (400 === $e->statusCode) {
            return self::validationFailed([['path' => '', 'message' => $e->getMessage()]]);
        }

        $code = 'room_not_found' === $e->error ? 'not_found' : $e->error;

        return self::error($e->statusCode, $code, $e->getMessage());
    }

    /**
     * @param list<array{path: string, message: string}> $violations
     */
    private static function validationFailed(array $violations): Response
    {
        return new ApiJsonResponse(['error' => 'validation_failed', 'violations' => $violations], 422);
    }

    private static function error(int $status, string $code, string $message): Response
    {
        return new ApiJsonResponse(['error' => $code, 'message' => $message], $status);
    }
}
