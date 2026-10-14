<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Libri i partnerit (i pandryshueshëm): prepaid_delta, receivable_delta. */
class FxPartnerEntry extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_partner_entries';

    protected $fillable = [
        'partner_id', 'entry_date', 'type', 'currency_code', 'prepaid_delta', 'receivable_delta',
        'operation_id', 'bank_entry_id', 'note', 'created_by',
    ];

    protected $casts = ['entry_date' => 'date'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(FxPartner::class, 'partner_id');
    }
}
