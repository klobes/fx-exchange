<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxOfficialRate extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_official_rates';

    protected $fillable = ['currency_id', 'rate_date', 'rate', 'source', 'note', 'created_by'];

    protected $casts = [
        'rate_date' => 'date',
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
