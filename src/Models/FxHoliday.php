<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;

class FxHoliday extends BaseModel
{
    protected $table = 'fx_holidays';

    protected $fillable = ['holiday_date', 'name', 'created_by'];

    protected $casts = ['holiday_date' => 'date'];
}
