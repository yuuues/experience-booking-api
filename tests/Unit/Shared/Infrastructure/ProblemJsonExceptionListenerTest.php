<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use App\Shared\Infrastructure\Symfony\ProblemJsonExceptionListener;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
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

        yield 'invalid value' => [new InvalidValue('bad'), 400, 'invalid-value'];

        yield 'business rule' => [new class ('No seats.') extends DomainException {
            public function errorCode(): string
            {
                return 'not-enough-seats-available';
            }
        }, 422, 'not-enough-seats-available'];

        yield 'lock timeout' => [
            new LockWaitTimeoutException(new class ('timeout') extends Exception implements DriverException {
                public function getSQLState(): ?string // @phpstan-ignore return.unusedType (signature required by DriverException)
                {
                    return '55P03';
                }
            }, null),
            503,
            'lock-timeout',
        ];
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

        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $body */
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
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $body */
        self::assertSame('/problems/validation-failed', $body['type']);
        self::assertSame([['field' => 'title', 'message' => 'This value should not be blank.']], $body['errors']);
    }

    #[Test]
    public function it_ignores_non_api_paths(): void
    {
        $event = $this->dispatch(new InvalidValue('bad'), '/not-api');

        self::assertNull($event->getResponse());
    }

    private function dispatch(Throwable $exception, string $path = '/api/x'): ExceptionEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ProblemJsonExceptionListener(debug: false))($event);

        return $event;
    }
}
