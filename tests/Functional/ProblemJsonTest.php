<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProblemJsonTest extends WebTestCase
{
    #[Test]
    public function unknown_api_route_is_problem_json(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = $this->decode($client->getResponse());
        self::assertSame('/problems/http-404', $body['type']);
    }

    /** @return array<mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
