<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $experienceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->jsonRequest('POST', '/api/experiences', [
            'title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac',
        ]);
        $experienceId = $this->json()['id'];
        self::assertIsString($experienceId);
        $this->experienceId = $experienceId;
    }

    #[Test]
    public function it_schedules_a_session(): void
    {
        $startsAt = (new DateTimeImmutable('+3 days'))->setTime(10, 0)->format(\DATE_ATOM);

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => $startsAt, 'capacity' => 12, 'price' => ['amount' => 2500, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame($this->experienceId, $body['experienceId']);
        self::assertSame(12, $body['availableSeats']);
        self::assertSame(['amount' => 2500, 'currency' => 'EUR'], $body['price']);
        $id = $body['id'];
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/sessions/' . $id);

        $this->client->request('GET', '/api/sessions/' . $id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($body, $this->json());
    }

    #[Test]
    public function it_rejects_second_session_same_day(): void
    {
        $day = (new DateTimeImmutable('+3 days'))->setTime(10, 0);
        $payload = static fn(DateTimeImmutable $at): array => ['startsAt' => $at->format(\DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR']];

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", $payload($day));
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", $payload($day->setTime(18, 0)));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/session-already-scheduled-for-day', $this->json()['type']);
    }

    #[Test]
    public function it_rejects_past_sessions(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => '2020-01-01T10:00:00+00:00', 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/session-in-the-past', $this->json()['type']);
    }

    #[Test]
    #[DataProvider('acceptableStartsAt')]
    public function it_accepts_any_iso8601_instant_with_an_explicit_zone(string $startsAt, string $normalised): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => $startsAt, 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame($normalised, $body['startsAt']);
        // Whatever designator came in (`Z` or a numeric offset), the response always normalises
        // to a numeric UTC offset: the API never echoes back a bare `Z`.
        self::assertStringEndsWith('+00:00', $body['startsAt']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function acceptableStartsAt(): iterable
    {
        yield 'numeric offset' => ['2026-09-14T10:00:00+02:00', '2026-09-14T08:00:00+00:00'];
        yield 'Z designator' => ['2026-09-14T10:00:00Z', '2026-09-14T10:00:00+00:00'];
        yield 'Z designator with milliseconds' => ['2026-09-14T10:00:00.000Z', '2026-09-14T10:00:00+00:00'];
        yield 'zero numeric offset' => ['2026-09-14T08:00:00+00:00', '2026-09-14T08:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('invalidStartsAt')]
    public function it_rejects_starts_at_without_an_explicit_zone_or_shape(string $startsAt): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => $startsAt, 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $errors = $this->json()['errors'];
        self::assertIsArray($errors);
        self::assertContains('startsAt', array_column($errors, 'field'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidStartsAt(): iterable
    {
        yield 'plain word' => ['tomorrow'];
        yield 'date only, no time' => ['2026-09-14'];
        yield 'no explicit zone, ambiguous' => ['2026-09-14T10:00:00'];
        yield 'garbage' => ['not-a-date'];
    }

    #[Test]
    public function it_validates_payload(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => 'tomorrow', 'capacity' => 0, 'price' => ['amount' => -1, 'currency' => 'euros'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $errors = $this->json()['errors'];
        self::assertIsArray($errors);
        $fields = array_column($errors, 'field');
        self::assertContains('startsAt', $fields);
        self::assertContains('capacity', $fields);
        self::assertContains('price.amount', $fields);
        self::assertContains('price.currency', $fields);
    }

    #[Test]
    public function it_rejects_a_capacity_above_the_maximum(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => (new DateTimeImmutable('+3 days'))->format(\DATE_ATOM),
            'capacity' => 3_000_000_000,
            'price' => ['amount' => 1000, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $errors = $this->json()['errors'];
        self::assertIsArray($errors);
        self::assertContains('capacity', array_column($errors, 'field'));
    }

    #[Test]
    public function it_rejects_a_price_above_the_maximum(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => (new DateTimeImmutable('+3 days'))->format(\DATE_ATOM),
            'capacity' => 10,
            'price' => ['amount' => \PHP_INT_MAX, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $errors = $this->json()['errors'];
        self::assertIsArray($errors);
        self::assertContains('price.amount', array_column($errors, 'field'));
    }

    #[Test]
    public function it_returns_404_for_unknown_experience(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences/0192b3a4-1234-7abc-8def-0123456789ff/sessions', [
            'startsAt' => (new DateTimeImmutable('+3 days'))->format(\DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
