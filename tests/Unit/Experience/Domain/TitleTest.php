<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Domain;

use App\Experience\Domain\Title;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    #[Test]
    public function it_trims_whitespace(): void
    {
        self::assertSame('Kayak at dawn', Title::fromString('  Kayak at dawn  ')->value);
    }

    #[Test]
    public function it_rejects_blank(): void
    {
        $this->expectException(InvalidValue::class);

        Title::fromString('   ');
    }

    #[Test]
    public function it_rejects_over_150_chars(): void
    {
        $this->expectException(InvalidValue::class);

        Title::fromString(str_repeat('a', 151));
    }
}
