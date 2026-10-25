<?php

declare(strict_types=1);

namespace App\Application\Api\System;

use App\Application\Api\Exception\ConflictException;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\UserPreferencesRequest;
use App\Infrastructure\Persistence\Entity\UserPreference;
use App\Infrastructure\Persistence\Repository\UserPreferenceRepository;

/**
 * The language and theme the current Home Assistant user chose (null language = automatic).
 */
final readonly class PreferencesService
{
    public function __construct(private UserPreferenceRepository $preferences)
    {
    }

    /**
     * @return array{locale: string|null, theme: string}
     *
     * @throws ConflictException when the request carries no Home Assistant user
     */
    public function update(UserPreferencesRequest $request, ?IngressUser $user): array
    {
        if (null === $user) {
            throw new ConflictException('no_user', 'There is no Home Assistant user on this request: the choice cannot be stored.');
        }

        $preference = $this->preferences->findOneByHaUserId($user->id) ?? new UserPreference($user->id);
        $preference->choose($request->locale, $request->theme);
        $this->preferences->save($preference);

        return ['locale' => $preference->getLocale(), 'theme' => $preference->getTheme()->value];
    }
}
