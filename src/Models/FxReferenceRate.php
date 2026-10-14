<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxReferenceRate extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_reference_rates';

    protected $fillable = ['currency_id', 'source', 'rate_date', 'rate', 'basis'];

    protected $casts = ['rate_date' => 'date'];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FxCurrency::class, 'currency_id');
    }
}
