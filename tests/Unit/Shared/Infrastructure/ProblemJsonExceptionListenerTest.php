<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\ConflictException;
use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use App\Shared\Infrastructure\Symfony\ProblemJsonExceptionListener;
use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

final class ProblemJsonExceptionListenerTest extends TestCase
{
    /** @return iterable<string, array{Throwable, int, string}> */
    public static function exceptions(): iterable
    {
        yield 'not found' => [new class ('Experience <x> not found.') extends NotFoundException {
            public function errorCode(): string
            {
                return 'experience-not-found';
            }
        }, 404, 'experience-not-found'];

        yield 'conflict' => [new class ('Already scheduled.') extends ConflictException {
            public function errorCode(): string
            {
                return 'already-scheduled';
            }
        }, 409, 'already-scheduled'];

        yield 'invalid value' => [new InvalidValue('bad'), 400, 'invalid-value'];

        yield 'business rule' => [new class ('No seats.') extends DomainException {
            public function errorCode(): string
            {
                return 'not-enough-seats-available';
            }
        }, 422, 'not-enough-seats-available'];

        // Portable arm: MySQL and SQLite converters turn a lock timeout into this class.
        yield 'lock timeout' => [new LockWaitTimeoutException(self::driverError('55P03'), null), 503, 'lock-timeout'];

        // PostgreSQL arm: its converter has no 55P03 case, so `SET LOCAL lock_timeout` surfaces as a plain DriverException.
        yield 'postgres lock not available' => [new DriverException(self::driverError('55P03'), null), 503, 'lock-timeout'];

        yield 'unrelated driver error' => [new DriverException(self::driverError('42P01'), null), 500, 'internal-error'];

        yield 'driver error without sqlstate' => [new DriverException(self::driverError(null), null), 500, 'internal-error'];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'api root' => ['/api', true];
        yield 'api resource' => ['/api/experiences', true];
        yield 'documentation next to the api' => ['/apidocs/foo', false];
        yield 'unrelated path' => ['/not-api', false];
        yield 'swagger ui' => ['/api/doc', false];
        yield 'openapi spec' => ['/api/doc.json', false];
    }

    #[Test]
    #[DataProvider('exceptions')]
    public function it_maps_exceptions_to_problem_json(Throwable $exception, int $status, string $type): void
    {
        $event = $this->dispatch($exception);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        if (Response::HTTP_SERVICE_UNAVAILABLE === $status) {
            self::assertSame('1', $response->headers->get('Retry-After'));
        }

        $body = $this->decode($response);
        self::assertSame('/problems/' . $type, $body['type']);
        self::assertSame($status, $body['status']);
        self::assertArrayHasKey('detail', $body);
    }

    #[Test]
    public function it_lists_validation_errors(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'title', ''),
        ]);
        $exception = new UnprocessableEntityHttpException('Validation failed', new ValidationFailedException(null, $violations));

        $event = $this->dispatch($exception);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('/problems/validation-failed', $body['type']);
        self::assertSame([['field' => 'title', 'message' => 'This value should not be blank.']], $body['errors']);
    }

    #[Test]
    public function it_keeps_the_headers_carried_by_http_exceptions(): void
    {
        $event = $this->dispatch(new MethodNotAllowedHttpException(['GET', 'HEAD'], 'No POST here.'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[DataProvider('paths')]
    public function it_only_handles_api_paths(string $path, bool $handled): void
    {
        $event = $this->dispatch(new InvalidValue('bad'), $path);

        self::assertSame($handled, null !== $event->getResponse());
    }

    private static function driverError(?string $sqlState): DriverError
    {
        return new class ($sqlState) extends Exception implements DriverError {
            public function __construct(private readonly ?string $sqlState)
            {
                parent::__construct('An error occurred in the driver.');
            }

            public function getSQLState(): ?string
            {
                return $this->sqlState;
            }
        };
    }

    private function dispatch(Throwable $exception, string $path = '/api/x'): ExceptionEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ProblemJsonExceptionListener(debug: false))($event);

        return $event;
    }

    /** @return array<mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
