<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxTillSession extends BaseModel
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_APPROVED = 'approved';

    protected $table = 'fx_till_sessions';

    protected $fillable = [
        'till_id', 'user_id', 'business_date', 'status', 'opening_counts', 'closing_counts',
        'closing_expected', 'opening_note', 'closing_note', 'opened_at', 'closed_at', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_counts' => 'array',
        'closing_counts' => 'array',
        'closing_expected' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function till(): BelongsTo
    {
        return $this->belongsTo(FxTill::class, 'till_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FxTransaction::class, 'till_session_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FxTillMovement::class, 'till_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
