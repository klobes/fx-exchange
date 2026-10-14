<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxReport extends BaseModel
{
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $table = 'fx_reports';

    protected $fillable = [
        'type', 'report_date', 'version', 'file_path', 'checksum', 'summary', 'generated_by', 'generated_at',
        'submitted_at', 'submitted_by', 'submission_ref',
    ];

    protected $casts = [
        'report_date' => 'date',
        'summary' => 'array',
        'generated_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
