<?php

declare(strict_types=1);

namespace App\Tests\Functional\Experience;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExperienceApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_registers_an_experience(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', [
            'title' => 'Kayak at dawn',
            'description' => 'Two hours paddling along the coast.',
            'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Kayak at dawn', $body['title']);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ac', $body['providerId']);
        $id = $body['id'];
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/experiences/' . $id);

        $this->client->request('GET', '/api/experiences/' . $id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($body, $this->json());
    }

    #[Test]
    public function it_validates_payload(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => '', 'description' => 'x', 'providerId' => 'nope']);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = $this->json();
        self::assertSame('/problems/validation-failed', $body['type']);
        $errors = $body['errors'];
        self::assertIsArray($errors);
        $fields = array_column($errors, 'field');
        self::assertContains('title', $fields);
        self::assertContains('providerId', $fields);
    }

    #[Test]
    public function it_returns_404_for_unknown_experience(): void
    {
        $this->client->request('GET', '/api/experiences/0192b3a4-1234-7abc-8def-0123456789ff');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/experience-not-found', $this->json()['type']);
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
