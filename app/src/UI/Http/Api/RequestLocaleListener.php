<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\RequestLocale;
use App\UI\Http\Api\Identity\IngressUserFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the locale of API requests (user preference, settings, Accept-Language, English) before the translator
 * is aligned on it, so validation messages and audit messages come in the right language.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final readonly class RequestLocaleListener
{
    public function __construct(
        private IngressUserFactory $users,
        private RequestLocale $locales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        // The health check must not depend on the database.
        if (!$event->isMainRequest() || !str_starts_with($path, '/api') || '/api/health' === $path) {
            return;
        }

        $request->setLocale($this->locales->resolve(
            $this->users->fromRequest($request),
            $request->getPreferredLanguage(RequestLocale::SUPPORTED),
        ));
    }
}
