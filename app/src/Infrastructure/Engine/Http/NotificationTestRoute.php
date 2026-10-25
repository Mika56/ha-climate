<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Notification\NotificationService;

/**
 * `POST /internal/notifications/test`: sends a test notification to every enabled destination, whatever the
 * enabled notification types. 202 with the number of destinations reached and failed, 409 when no destination
 * is enabled.
 */
final readonly class NotificationTestRoute implements InternalRouteInterface
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return '/internal/notifications/test';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        $result = $this->notifications->sendTest();
        if (null === $result) {
            return JsonResponse::create(['error' => 'no_enabled_target', 'message' => 'No notification destination is enabled.'], HttpStatus::CONFLICT);
        }

        return JsonResponse::create($result, HttpStatus::ACCEPTED);
    }
}
