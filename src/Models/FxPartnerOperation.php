<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FxPartnerOperation extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_partner_operations';

    protected $fillable = [
        'office_id', 'year', 'seq', 'number', 'till_id', 'till_session_id', 'user_id', 'business_date', 'occurred_at',
        'partner_id', 'service_id', 'direction', 'settlement', 'currency_code', 'amount', 'reference',
        'voids_operation_id', 'void_reason', 'prev_hash', 'hash',
    ];

    protected $casts = ['business_date' => 'date', 'occurred_at' => 'datetime'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(FxPartner::class, 'partner_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FxPartnerService::class, 'service_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(FxTill::class, 'till_id');
    }

    public function voidedBy(): HasOne
    {
        return $this->hasOne(self::class, 'voids_operation_id');
    }

    public function isReversal(): bool
    {
        return $this->voids_operation_id !== null;
    }
}
