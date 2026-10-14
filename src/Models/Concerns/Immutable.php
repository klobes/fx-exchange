<?php

namespace Botble\EmironFx\Models\Concerns;

use LogicException;

/**
 * Regjistër i pandryshueshëm (parimi i RULE_051): rreshtat shtohen, s'ndryshohen,
 * s'fshihen. Zbatohet në kod, jo me marrëveshje.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function (): void {
            throw new LogicException(static::class . ' është i pandryshueshëm: korrigjimi bëhet me rresht të ri.');
        });

        static::deleting(function (): void {
            throw new LogicException(static::class . ' është i pandryshueshëm: rreshtat nuk fshihen.');
        });
    }
}
