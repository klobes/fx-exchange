<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;

/** Burim JSON i konfigurueshëm i kurseve (shih migrimin 2026_10_12_000009). */
class FxRateSource extends BaseModel
{
    protected $table = 'fx_rate_sources';

    protected $fillable = ['code', 'name', 'role', 'driver', 'url', 'fallback_url', 'config', 'enabled', 'attribution', 'sort_order'];

    /** `secret` = token i enkriptuar (FxCrypto); s'serializohet kurrë dhe s'caktohet me mass-assignment. */
    protected $hidden = ['secret'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'bool',
        'sort_order' => 'int',
    ];
}
