<?php

declare(strict_types=1);

namespace App\Tests\Integration\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\SentNotificationRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalSentNotificationRegistryTest extends KernelTestCase
{
    private SentNotificationRegistry $registry;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry = self::getContainer()->get(SentNotificationRegistry::class);
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    #[Test]
    public function was_sent_is_false_for_an_unknown_pair(): void
    {
        $reference = BookingReference::fromString('BK-7F3A2C9K');

        self::assertFalse($this->registry->wasSent($reference, BookingEmail::TYPE_CONFIRMATION));
    }

    #[Test]
    public function mark_sent_makes_was_sent_true_only_for_that_exact_type(): void
    {
        $reference = BookingReference::fromString('BK-WTDEARWA');

        $this->registry->markSent($reference, BookingEmail::TYPE_CONFIRMATION);

        // The primary key is (booking_reference, type): a confirmation being marked sent must
        // not suppress the cancellation email for the same booking.
        self::assertTrue($this->registry->wasSent($reference, BookingEmail::TYPE_CONFIRMATION));
        self::assertFalse($this->registry->wasSent($reference, BookingEmail::TYPE_CANCELLATION));
    }

    #[Test]
    public function mark_sent_tolerates_a_concurrent_duplicate_for_the_same_pair(): void
    {
        $reference = BookingReference::fromString('BK-VTCTKFYG');

        $this->registry->markSent($reference, BookingEmail::TYPE_CANCELLATION);

        // A redelivered message calling markSent() again for the same (reference, type) hits the
        // unique constraint; DbalSentNotificationRegistry must swallow it, not throw. That's true
        // in production (each statement autocommits, so a failed one doesn't affect the next),
        // but under the test suite's ambient DAMA transaction a real unique violation aborts the
        // whole transaction at the Postgres level even though the PHP exception was caught -
        // wrapping the duplicate attempt in its own savepoint isolates that, exactly as a real
        // caller nesting this inside a larger transaction would need to.
        $this->connection->createSavepoint('duplicate_mark_sent');
        $this->registry->markSent($reference, BookingEmail::TYPE_CANCELLATION);
        $this->connection->rollbackSavepoint('duplicate_mark_sent');

        self::assertTrue($this->registry->wasSent($reference, BookingEmail::TYPE_CANCELLATION));
    }
}
