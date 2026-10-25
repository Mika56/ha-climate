<?php

declare(strict_types=1);

namespace App\Application\Api;

/**
 * Instants of the API: RFC 3339 with offset, in the Home Assistant time zone.
 */
final readonly class ApiTime
{
    public function __construct(private HaTimeZone $timeZone)
    {
    }

    public function format(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone($this->timeZone->get())->format(\DATE_ATOM);
    }

    public function zone(): \DateTimeZone
    {
        return $this->timeZone->get();
    }
}
