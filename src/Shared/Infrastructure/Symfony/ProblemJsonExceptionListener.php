<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony;

use App\Shared\Domain\ConflictException;
use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use Doctrine\DBAL\Exception\DriverException;
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
        ConflictException::class => Response::HTTP_CONFLICT,
        InvalidValue::class => Response::HTTP_BAD_REQUEST,
    ];

    /** PostgreSQL `lock_not_available`: what `SET LOCAL lock_timeout` raises on a contended row. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    /** Nelmio's own routes: documentation, not API responses, so they keep Symfony's default error rendering. */
    private const array EXCLUDED_PATHS = ['/api/doc', '/api/doc.json'];

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/api' !== $path && !str_starts_with($path, '/api/')) {
            return;
        }

        if (\in_array($path, self::EXCLUDED_PATHS, true)) {
            return;
        }

        $exception = $event->getThrowable();
        [$status, $type, $detail, $extra] = $this->describe($exception);

        $body = ['type' => '/problems/' . $type, 'title' => $this->title($type), 'status' => $status, 'detail' => $detail] + $extra;

        $event->setResponse(new JsonResponse($body, $status, $this->headers($status, $exception)));
    }

    /**
     * Our own headers win; whatever else the exception carries (a 405's `Allow`, a 429's `Retry-After`) is kept.
     *
     * @return array<string, mixed>
     */
    private function headers(int $status, Throwable $exception): array
    {
        $headers = ['Content-Type' => 'application/problem+json'];
        if (Response::HTTP_SERVICE_UNAVAILABLE === $status) {
            $headers['Retry-After'] = '1';
        }

        if (!$exception instanceof HttpExceptionInterface) {
            return $headers;
        }

        $reserved = array_map(strtolower(...), array_keys($headers));
        foreach ($exception->getHeaders() as $name => $value) {
            if (!\in_array(strtolower((string) $name), $reserved, true)) {
                $headers[(string) $name] = $value;
            }
        }

        return $headers;
    }

    /** @return array{int, string, string, array<string, mixed>} */
    private function describe(Throwable $exception): array
    {
        if ($exception instanceof DomainException) {
            return [$this->domainStatus($exception), $exception->errorCode(), $exception->getMessage(), []];
        }

        // MySQL and SQLite converters raise LockWaitTimeoutException; PostgreSQL has no case for 55P03
        // and hands back a plain DriverException, so both shapes have to be recognised here.
        if ($exception instanceof LockWaitTimeoutException
            || ($exception instanceof DriverException && self::LOCK_NOT_AVAILABLE === $exception->getSQLState())
        ) {
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
