<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxOffice extends BaseModel
{
    protected $table = 'fx_offices';

    protected $fillable = ['name', 'legal_name', 'nipt', 'license_no', 'address', 'phone', 'code', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function tills(): HasMany
    {
        return $this->hasMany(FxTill::class, 'office_id');
    }
}
