<?php
declare(strict_types=1);

namespace Drift\Widget;

/** @api */
abstract class Widget
{
    /** @api */
    public function publicHook(): void
    {
    }

    /** @internal */
    public function internalHook(): void
    {
    }

    /** @internal */
    public static function internalStatic(): void
    {
    }
}
