<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxCurrency extends BaseModel
{
    protected $table = 'fx_currencies';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'unit', 'is_active', 'sort_order'];

    protected $casts = [
        'decimals' => 'int',
        'unit' => 'int',
        'is_active' => 'bool',
        'sort_order' => 'int',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(FxRate::class, 'currency_id');
    }

    public function officialRates(): HasMany
    {
        return $this->hasMany(FxOfficialRate::class, 'currency_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
