<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Keeps the OpenAPI documentation honest against the *actual* router, not a hardcoded guess: a
 * new `api_*` route added with no documentation, or an existing one whose method changes, fails
 * this suite instead of rotting silently. Nelmio's own routes (`app.swagger`, `app.swagger_ui`)
 * and Symfony's dev-only `_preview_error` are excluded by construction — none of them are named
 * `api_*`.
 */
final class OpenApiDocumentationTest extends WebTestCase
{
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
    public function the_spec_documents_exactly_the_routers_api_routes_and_their_methods(): void
    {
        $expectedRoutes = $this->apiRoutesFromRouter();
        $documentedPaths = $this->documentedPaths();

        $expectedPaths = array_keys($expectedRoutes);
        sort($expectedPaths);
        $actualPaths = array_keys($documentedPaths);
        sort($actualPaths);
        self::assertSame($expectedPaths, $actualPaths, 'The documented paths must match exactly the api_* routes the router serves (a route was added or removed without updating the docs).');

        foreach ($expectedRoutes as $path => $methods) {
            $operations = $documentedPaths[$path];
            self::assertIsArray($operations);
            $documentedMethods = array_keys($operations);
            sort($methods);
            sort($documentedMethods);
            self::assertSame($methods, $documentedMethods, "Methods documented for {$path} do not match the route (declared: " . implode(',', $methods) . ').');
        }
    }

    #[Test]
    public function every_operation_documents_its_summary_and_responses(): void
    {
        $expectedRoutes = $this->apiRoutesFromRouter();
        $documentedPaths = $this->documentedPaths();

        foreach ($expectedRoutes as $path => $methods) {
            $operations = $documentedPaths[$path];
            self::assertIsArray($operations);
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $operations, "Missing method \"{$method}\" for path: {$path}");
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
        $paths = $this->documentedPaths();

        self::assertArrayNotHasKey('/api/doc', $paths);
        self::assertArrayNotHasKey('/api/doc.json', $paths);
    }

    /**
     * The api_* routes actually registered in the router, keyed by path with their declared
     * (lowercased) HTTP methods — the source of truth the spec is checked against.
     *
     * @return array<string, list<string>>
     */
    private function apiRoutesFromRouter(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'api_')) {
                // Excludes Nelmio's app.swagger / app.swagger_ui and Symfony's _preview_error:
                // none of the application's own endpoints are named anything but api_*.
                continue;
            }

            $methods = array_map(strtolower(...), $route->getMethods());
            self::assertNotEmpty($methods, "Route \"{$name}\" must declare explicit HTTP methods to be documentable.");

            $path = $route->getPath();
            $routes[$path] = array_values(array_unique([...($routes[$path] ?? []), ...$methods]));
        }

        self::assertNotEmpty($routes, 'Expected at least one api_* route to check the documentation against.');

        return $routes;
    }

    /** @return array<mixed> */
    private function documentedPaths(): array
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
