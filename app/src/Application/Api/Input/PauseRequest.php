<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

/**
 * `PUT /api/pause`: pauses (true) or resumes (false) HA Climate.
 */
final readonly class PauseRequest
{
    public function __construct(public bool $paused)
    {
    }
}
