<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxTillMovement extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_till_movements';

    protected $fillable = ['till_id', 'till_session_id', 'currency_code', 'amount', 'type', 'transaction_id', 'note', 'created_by'];

    protected $casts = ['created_at' => 'datetime'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FxTransaction::class, 'transaction_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
