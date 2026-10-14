<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRate extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_rates';

    protected $fillable = [
        'currency_id', 'buy_rate', 'sell_rate', 'official_rate', 'official_rate_date',
        'buy_deviation_pct', 'sell_deviation_pct', 'is_override', 'effective_at',
        'batch_uuid', 'note', 'created_by',
    ];

    protected $casts = [
        'is_override' => 'bool',
        'effective_at' => 'datetime',
        'official_rate_date' => 'date',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FxCurrency::class, 'currency_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
