<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    #[Test]
    public function it_generates_a_valid_uuid(): void
    {
        $id = TestId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id->value);
    }

    #[Test]
    public function it_normalizes_to_lowercase(): void
    {
        $id = TestId::fromString('0192B3A4-1234-7ABC-8DEF-0123456789AB');

        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ab', $id->value);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ab', (string) $id);
    }

    #[Test]
    public function it_rejects_invalid_uuid(): void
    {
        $this->expectException(InvalidValue::class);

        TestId::fromString('not-a-uuid');
    }

    #[Test]
    public function equality_requires_same_class_and_value(): void
    {
        $a = TestId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');
        $b = TestId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');
        $c = OtherId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

final class TestId extends Uuid {}

final class OtherId extends Uuid {}
