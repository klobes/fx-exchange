<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxPartner extends BaseModel
{
    protected $table = 'fx_partners';

    protected $fillable = ['name', 'code', 'prepaid_low_alert', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function services(): HasMany
    {
        return $this->hasMany(FxPartnerService::class, 'partner_id')->orderBy('sort_order');
    }
}
