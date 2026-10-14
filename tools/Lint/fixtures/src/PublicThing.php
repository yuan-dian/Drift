<?php
declare(strict_types=1);

namespace Drift;

/** @api */
final class PublicThing
{
    public static function marker(): string
    {
        return 'public';
    }
}
