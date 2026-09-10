<?php

declare(strict_types=1);

namespace App\Tests\Functional\Experience;

use DateTimeImmutable;
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

    #[Test]
    public function it_updates_an_experience_without_bookings(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $id = $this->json()['id'];
        self::assertIsString($id);

        $this->client->jsonRequest('PUT', "/api/experiences/{$id}", ['title' => 'Kayak at sunset', 'description' => 'Evening paddle']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Kayak at sunset', $this->json()['title']);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ac', $this->json()['providerId']);
    }

    #[Test]
    public function it_refuses_update_once_a_booking_is_confirmed(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $id = $this->json()['id'];
        self::assertIsString($id);
        $this->client->jsonRequest('POST', "/api/experiences/{$id}/sessions", [
            'startsAt' => (new DateTimeImmutable('+3 days'))->format(\DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        $sessionId = $this->json()['id'];
        self::assertIsString($sessionId);
        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 1]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('PUT', "/api/experiences/{$id}", ['title' => 'Renamed', 'description' => 'Desc']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/experience-has-bookings', $this->json()['type']);
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
