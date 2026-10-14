<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;

/** Një përpjekje sinkronizimi e një burimi (e pandryshueshme). */
class FxSourceRun extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_source_runs';

    protected $fillable = [
        'source_code', 'triggered_by', 'user_id', 'dry_run', 'status', 'data_date', 'rows_read', 'rows_saved',
        'rows_unchanged', 'rows_skipped', 'error', 'summary', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'dry_run' => 'bool',
        'data_date' => 'date',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
