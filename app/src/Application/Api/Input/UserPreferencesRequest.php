<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Infrastructure\Persistence\Enum\ThemePreference;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserPreferencesRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['en', 'fr'])]
        public ?string $locale,
        public ThemePreference $theme,
    ) {
    }
}
