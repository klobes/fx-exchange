<?php

namespace Botble\EmironFx\Casts;

use Botble\EmironFx\Support\FxCrypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/** Enkriptim me çelësin e Emiron FX (FX_DATA_KEY), jo me APP_KEY. */
class FxEncrypted implements CastsAttributes
{
    public function get($model, string $key, mixed $value, array $attributes): ?string
    {
        return FxCrypto::decrypt($value === null ? null : (string) $value);
    }

    public function set($model, string $key, mixed $value, array $attributes): ?string
    {
        return FxCrypto::encrypt($value === null || $value === '' ? null : (string) $value);
    }
}
