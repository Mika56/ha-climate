<?php

declare(strict_types=1);

namespace App\UI\Http\Api\Identity;

use App\Application\Api\IngressUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the Home Assistant user from the headers the Supervisor adds to ingress requests. Without the user id
 * header (standalone development access) there is no user.
 */
final class IngressUserFactory
{
    public const string ID_HEADER = 'X-Remote-User-Id';
    public const string NAME_HEADER = 'X-Remote-User-Name';
    public const string DISPLAY_NAME_HEADER = 'X-Remote-User-Display-Name';

    public function fromRequest(Request $request): ?IngressUser
    {
        $id = trim((string) $request->headers->get(self::ID_HEADER));
        if ('' === $id) {
            return null;
        }

        $name = trim((string) $request->headers->get(self::NAME_HEADER));
        $displayName = trim((string) $request->headers->get(self::DISPLAY_NAME_HEADER));

        return new IngressUser($id, '' === $name ? $id : $name, '' === $displayName ? ('' === $name ? $id : $name) : $displayName);
    }
}
