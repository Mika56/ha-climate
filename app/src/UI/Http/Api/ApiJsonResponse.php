<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A JSON answer of the API: whole floats keep their fraction (`21.0`, the contract speaks of floats for
 * temperatures) and non-ASCII text is written as is.
 */
final class ApiJsonResponse extends JsonResponse
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        parent::__construct(null, $status, $headers);
        $this->encodingOptions = self::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;
        $this->setData($data);
    }
}
