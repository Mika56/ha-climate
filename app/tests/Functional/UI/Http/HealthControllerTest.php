<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\UI\Http\Api\RequestLocaleListener;
use App\UI\Http\HealthController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(HealthController::class)]
#[UsesClass(RequestLocaleListener::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Infrastructure')]
final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
