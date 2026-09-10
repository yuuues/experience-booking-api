<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\SessionDay;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/** @extends StringValueObjectType<SessionDay> */
final class SessionDayType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return SessionDay::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }
}
