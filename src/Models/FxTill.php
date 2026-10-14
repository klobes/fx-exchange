<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxTill extends BaseModel
{
    protected $table = 'fx_tills';

    protected $fillable = ['office_id', 'name', 'user_id', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function office(): BelongsTo
    {
        return $this->belongsTo(FxOffice::class, 'office_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(FxTillSession::class, 'till_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FxTillMovement::class, 'till_id');
    }
}
