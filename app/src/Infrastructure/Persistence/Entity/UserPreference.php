<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Enum\ThemePreference;
use App\Infrastructure\Persistence\Repository\UserPreferenceRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserPreferenceRepository::class)]
#[ORM\Table(name: 'user_preference')]
#[ORM\UniqueConstraint(name: 'uniq_user_preference_ha_user', columns: ['ha_user_id'])]
final class UserPreference
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $haUserId;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'string', length: 8, enumType: ThemePreference::class)]
    private ThemePreference $theme = ThemePreference::Auto;

    public function __construct(string $haUserId)
    {
        $this->id = Uuid::v7();
        $this->haUserId = Guard::notBlank($haUserId, 'haUserId');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHaUserId(): string
    {
        return $this->haUserId;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getTheme(): ThemePreference
    {
        return $this->theme;
    }

    /**
     * A null locale means "automatic" (follow the Home Assistant profile).
     */
    public function choose(?string $locale, ThemePreference $theme): void
    {
        $this->locale = Guard::nullableNotBlank($locale, 'locale');
        $this->theme = $theme;
    }
}
