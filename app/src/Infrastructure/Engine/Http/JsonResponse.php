<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;

final class JsonResponse
{
    private function __construct()
    {
    }

    public static function create(mixed $data, int $status = HttpStatus::OK): Response
    {
        return new Response(
            $status,
            ['content-type' => 'application/json; charset=utf-8', 'cache-control' => 'no-store'],
            json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    public static function error(string $error, int $status): Response
    {
        return self::create(['error' => $error], $status);
    }
}
