<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony;

use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/** Turns any exception thrown under /api into an RFC 7807 problem+json response. */
#[AsEventListener(event: 'kernel.exception', priority: 10)]
final readonly class ProblemJsonExceptionListener
{
    /** @var array<class-string<DomainException>, int> checked in order; first `instanceof` wins */
    private const array DOMAIN_STATUS = [
        NotFoundException::class => Response::HTTP_NOT_FOUND,
        InvalidValue::class => Response::HTTP_BAD_REQUEST,
    ];

    /** @var array<string, int> exact-class overrides (conflicts); classes live in later modules, hence plain strings */
    public const array CONFLICTS = [
        'App\Session\Domain\Exception\SessionAlreadyScheduledForDay' => Response::HTTP_CONFLICT,
        'App\Experience\Domain\Exception\ExperienceHasBookings' => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        [$status, $type, $detail, $extra] = $this->describe($exception);

        $body = ['type' => '/problems/' . $type, 'title' => $this->title($type), 'status' => $status, 'detail' => $detail] + $extra;
        $headers = ['Content-Type' => 'application/problem+json'];
        if (Response::HTTP_SERVICE_UNAVAILABLE === $status) {
            $headers['Retry-After'] = '1';
        }

        $event->setResponse(new JsonResponse($body, $status, $headers));
    }

    /** @return array{int, string, string, array<string, mixed>} */
    private function describe(Throwable $exception): array
    {
        if ($exception instanceof DomainException) {
            return [$this->domainStatus($exception), $exception->errorCode(), $exception->getMessage(), []];
        }

        if ($exception instanceof LockWaitTimeoutException) {
            return [Response::HTTP_SERVICE_UNAVAILABLE, 'lock-timeout', 'The resource is busy, please retry.', []];
        }

        if ($exception instanceof HttpExceptionInterface) {
            $previous = $exception->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                $errors = [];
                foreach ($previous->getViolations() as $violation) {
                    $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
                }

                return [$exception->getStatusCode(), 'validation-failed', 'The request payload is invalid.', ['errors' => $errors]];
            }

            return [$exception->getStatusCode(), 'http-' . $exception->getStatusCode(), $exception->getMessage() ?: 'Request could not be processed.', []];
        }

        $detail = $this->debug ? $exception->getMessage() : 'An unexpected error occurred.';

        return [Response::HTTP_INTERNAL_SERVER_ERROR, 'internal-error', $detail, []];
    }

    private function domainStatus(DomainException $exception): int
    {
        if (isset(self::CONFLICTS[$exception::class])) { // @phpstan-ignore isset.offset (CONFLICTS keys name classes from modules added in later tasks)
            return self::CONFLICTS[$exception::class];
        }
        foreach (self::DOMAIN_STATUS as $class => $status) {
            if ($exception instanceof $class) {
                return $status;
            }
        }

        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    private function title(string $type): string
    {
        return ucfirst(str_replace('-', ' ', $type));
    }
}
