<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Keeps the OpenAPI documentation honest: a new `api_*` route that is not documented, or an
 * existing one whose method changes without updating its attributes, fails this suite instead
 * of rotting silently.
 */
final class OpenApiDocumentationTest extends WebTestCase
{
    /** @var array<string, list<string>> path => allowed HTTP methods, as declared by the `#[Route]` attributes */
    private const array EXPECTED_ROUTES = [
        '/api/experiences' => ['post'],
        '/api/experiences/{id}' => ['get', 'put'],
        '/api/experiences/{experienceId}/sessions' => ['post'],
        '/api/sessions/{id}' => ['get'],
        '/api/sessions/{sessionId}/bookings' => ['post'],
        '/api/bookings/{reference}' => ['get'],
        '/api/bookings/{reference}/cancellation' => ['post'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function swagger_ui_is_served(): void
    {
        $this->client->request('GET', '/api/doc');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function the_spec_documents_every_api_route_with_its_methods(): void
    {
        $paths = $this->paths();

        foreach (self::EXPECTED_ROUTES as $path => $methods) {
            self::assertArrayHasKey($path, $paths, "Missing documented path: {$path}");
            $operations = $paths[$path];
            self::assertIsArray($operations);
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $operations, "Missing method \"{$method}\" for path: {$path}");
            }
        }

        $documentedMethodCount = array_sum(array_map(static fn(array $methods): int => \count($methods), array_values(self::EXPECTED_ROUTES)));
        self::assertSame(8, $documentedMethodCount, 'The API is expected to expose 8 operations.');
    }

    #[Test]
    public function every_operation_documents_its_summary_and_responses(): void
    {
        $paths = $this->paths();

        foreach (self::EXPECTED_ROUTES as $path => $methods) {
            $operations = $paths[$path];
            self::assertIsArray($operations);
            foreach ($methods as $method) {
                $operation = $operations[$method];
                self::assertIsArray($operation);
                self::assertArrayHasKey('summary', $operation, "Missing summary for {$method} {$path}");
                self::assertArrayHasKey('responses', $operation, "Missing responses for {$method} {$path}");
                $responses = $operation['responses'];
                self::assertIsArray($responses);
                self::assertNotEmpty($responses, "No responses documented for {$method} {$path}");
            }
        }
    }

    #[Test]
    public function the_documentation_routes_are_not_listed_as_api_endpoints(): void
    {
        $paths = $this->paths();

        self::assertArrayNotHasKey('/api/doc', $paths);
        self::assertArrayNotHasKey('/api/doc.json', $paths);
    }

    /** @return array<mixed> */
    private function paths(): array
    {
        $this->client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $paths = $this->json()['paths'];
        self::assertIsArray($paths);

        return $paths;
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
