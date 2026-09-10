<?php

declare(strict_types=1);

namespace App\Tests\Functional\Booking;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingApiTest extends WebTestCase
{
    private const string USER = '0192b3a4-1234-7abc-8def-0123456789ad';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_books_fetches_and_cancels(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 5);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 2]);
        self::assertResponseStatusCodeSame(201);
        $booking = $this->json();
        $reference = $booking['reference'];
        self::assertIsString($reference);
        self::assertMatchesRegularExpression('/^BK-[0-9A-HJKMNP-TV-Z]{8}$/', $reference);
        self::assertSame('confirmed', $booking['status']);
        self::assertSame(['amount' => 3000, 'currency' => 'EUR'], $booking['total']);
        self::assertResponseHeaderSame('Location', '/api/bookings/' . $reference);

        $this->client->request('GET', '/api/sessions/' . $sessionId);
        self::assertSame(3, $this->json()['availableSeats']);

        $this->client->request('GET', '/api/bookings/' . $reference);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($booking, $this->json());

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");
        self::assertResponseStatusCodeSame(200);
        self::assertSame('cancelled', $this->json()['status']);
        self::assertNotNull($this->json()['cancelledAt']);

        $this->client->request('GET', '/api/sessions/' . $sessionId);
        self::assertSame(5, $this->json()['availableSeats']);

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/booking-already-cancelled', $this->json()['type']);
    }

    #[Test]
    public function it_refuses_overbooking(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 2);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 3]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/not-enough-seats-available', $this->json()['type']);
    }

    #[Test]
    public function it_refuses_cancellation_within_24_hours(): void
    {
        $sessionId = $this->aSession('+2 hours', capacity: 2);
        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 1]);
        $reference = $this->json()['reference'];
        self::assertIsString($reference);

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/cancellation-window-closed', $this->json()['type']);
    }

    #[Test]
    public function it_validates_payload_and_reference_format(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 2);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => 'x', 'seats' => 0]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/bookings/NOPE');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/bookings/BK-ZZZZZZZZ');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/booking-not-found', $this->json()['type']);
    }

    private function aSession(string $when, int $capacity): string
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $experienceId = $this->json()['id'];
        self::assertIsString($experienceId);
        $this->client->jsonRequest('POST', "/api/experiences/{$experienceId}/sessions", [
            'startsAt' => (new DateTimeImmutable($when))->format(\DATE_ATOM),
            'capacity' => $capacity,
            'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $id = $this->json()['id'];
        self::assertIsString($id);

        return $id;
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
