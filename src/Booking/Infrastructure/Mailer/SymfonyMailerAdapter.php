<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Mailer;

use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsAlias(id: MailerPort::class)]
final readonly class SymfonyMailerAdapter implements MailerPort
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.mailer_from')]
        private string $from,
    ) {}

    public function send(BookingEmail $email): void
    {
        $this->mailer->send((new Email())
            ->from($this->from)
            ->to($email->to)
            ->subject($this->subject($email))
            ->text($this->body($email)));
    }

    private function subject(BookingEmail $email): string
    {
        return match ($email->type) {
            BookingEmail::TYPE_CONFIRMATION => \sprintf('Booking %s confirmed', $email->reference),
            BookingEmail::TYPE_CANCELLATION => \sprintf('Booking %s cancelled', $email->reference),
            default => \sprintf('Booking %s', $email->reference),
        };
    }

    private function body(BookingEmail $email): string
    {
        $amount = number_format($email->totalAmount / 100, 2, '.', '');

        return match ($email->type) {
            BookingEmail::TYPE_CONFIRMATION => \sprintf(
                "Your booking %s is confirmed.\nSeats: %d\nTotal: %s %s\nSession starts at: %s\n",
                $email->reference,
                $email->seats,
                $amount,
                $email->totalCurrency,
                $email->sessionStartsAt,
            ),
            default => \sprintf(
                "Your booking %s has been cancelled.\nSeats released: %d\nSession was starting at: %s\n",
                $email->reference,
                $email->seats,
                $email->sessionStartsAt,
            ),
        };
    }
}
