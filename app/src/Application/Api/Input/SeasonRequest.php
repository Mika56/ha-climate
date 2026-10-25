<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Climate\Season;

final readonly class SeasonRequest
{
    public function __construct(public Season $season)
    {
    }
}
