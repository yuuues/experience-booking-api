<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Domain\BookingId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BookSeatsController
{
    public function __construct(
        private BookSeatsHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {}

    #[Route('/api/sessions/{sessionId}/bookings', name: 'api_bookings_book', methods: ['POST'], requirements: ['sessionId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $sessionId,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        BookSeatsRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new BookSeatsCommand(
            bookingId: BookingId::generate()->value,
            sessionId: $sessionId,
            userId: $request->userId,
            seats: $request->seats,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_bookings_find', ['reference' => $response->reference]),
        ]);
    }
}
