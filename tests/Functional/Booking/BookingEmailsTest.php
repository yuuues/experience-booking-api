<?php

declare(strict_types=1);

namespace App\Tests\Functional\Booking;

use App\Booking\Domain\Notification\BookingEmail;
use App\Tests\Doubles\Booking\InMemoryMailer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingEmailsTest extends WebTestCase
{
    private KernelBrowser $client;
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // KernelBrowser reboots the kernel (and its container) between requests by default, which
        // would swap out the InMemoryMailer instance mid-test; disable it so the same in-memory
        // double accumulates every email sent across the requests below.
        $this->client->disableReboot();
        $this->mailer = self::getContainer()->get(InMemoryMailer::class);
    }

    #[Test]
    public function booking_and_cancelling_send_one_email_each(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $experienceId = $this->json()['id'];
        self::assertIsString($experienceId);
        $this->client->jsonRequest('POST', "/api/experiences/{$experienceId}/sessions", [
            'startsAt' => (new DateTimeImmutable('+3 days'))->format(\DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        $sessionId = $this->json()['id'];
        self::assertIsString($sessionId);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 2]);
        $reference = $this->json()['reference'];
        self::assertIsString($reference);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame(BookingEmail::TYPE_CONFIRMATION, $sent[0]->type);
        self::assertSame($reference, $sent[0]->reference);
        self::assertSame('user-0192b3a4-1234-7abc-8def-0123456789ad@example.test', $sent[0]->to);

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        self::assertSame(BookingEmail::TYPE_CANCELLATION, $sent[1]->type);
    }

    #[Test]
    public function a_failed_booking_sends_nothing(): void
    {
        $this->client->jsonRequest('POST', '/api/sessions/0192b3a4-1234-7abc-8def-0123456789ff/bookings', ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 1]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->mailer->sent());
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
