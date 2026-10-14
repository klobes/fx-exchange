<?php

namespace Botble\EmironFx\Models;

use Botble\EmironFx\Casts\FxEncrypted;
use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxAmlCase extends BaseModel
{
    protected $table = 'fx_aml_cases';

    protected $fillable = [
        'customer_id', 'transaction_id', 'status', 'description', 'decision', 'fiu_reference',
        'reported_at', 'opened_by', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'description' => FxEncrypted::class,
        'decision' => FxEncrypted::class,
        'reported_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(FxCustomer::class, 'customer_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FxTransaction::class, 'transaction_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
