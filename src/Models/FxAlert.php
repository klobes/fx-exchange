<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;

class FxAlert extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'fx_alerts';

    protected $fillable = ['type', 'alert_date', 'fingerprint', 'payload', 'recipients', 'sent_at', 'error'];

    protected $casts = [
        'alert_date' => 'date',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}
