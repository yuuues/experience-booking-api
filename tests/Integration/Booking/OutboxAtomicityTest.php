<?php

declare(strict_types=1);

namespace App\Tests\Integration\Booking;

use App\Booking\Domain\Event\BookingConfirmed;
use App\Shared\Application\TransactionalRunner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Proves the outbox claim in docs/design/email-notifications.md: the Doctrine transport writes
 * to messenger_messages through the SAME DBAL connection the ORM uses, so a message dispatched
 * inside TransactionalRunner::run() is only durably persisted if that transaction commits.
 *
 * The test suite otherwise runs Messenger on `sync://` (see config/packages/messenger.yaml's
 * when@test block), so no message ever reaches messenger_messages there. Rather than changing
 * that global default, this test builds a real `doctrine://` transport directly from the
 * container's transport factory (aliased public for this test only, in config/services_test.yaml)
 * and drives it explicitly.
 */
final class OutboxAtomicityTest extends KernelTestCase
{
    private DoctrineTransport $transport;
    private TransactionalRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $factory = $container->get(DoctrineTransportFactory::class);
        $this->transport = $factory->createTransport('doctrine://default', [], new PhpSerializer());
        $this->transport->setup();

        $this->runner = $container->get(TransactionalRunner::class);
    }

    #[Test]
    public function a_transaction_that_rolls_back_leaves_no_outbox_row(): void
    {
        // expectException() is the assertion that TransactionalRunner::run() really does
        // propagate the failure; the finally block checks the side effect (no outbox row) right
        // after the rollback but before that expected exception leaves the test method.
        $this->expectException(RuntimeException::class);

        try {
            $this->runner->run(function (): void {
                $this->transport->send(new Envelope($this->anEvent()));

                throw new RuntimeException('simulated failure after publish, before commit');
            });
        } finally {
            self::assertSame(0, $this->transport->getMessageCount());
        }
    }

    #[Test]
    public function a_transaction_that_commits_persists_exactly_one_outbox_row(): void
    {
        $this->runner->run(function (): void {
            $this->transport->send(new Envelope($this->anEvent()));
        });

        self::assertSame(1, $this->transport->getMessageCount());
    }

    private function anEvent(): BookingConfirmed
    {
        return new BookingConfirmed(
            reference: 'BK-7F3A2C9K',
            bookingId: '0192b3a4-1234-7abc-8def-0123456789b1',
            sessionId: '0192b3a4-1234-7abc-8def-0123456789b2',
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: 2,
            totalAmount: 3000,
            totalCurrency: 'EUR',
            occurredOn: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }
}
