<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxAccessLog extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_access_logs';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'ip', 'note'];

    public static function record(string $action, string $subjectType, ?int $subjectId, ?string $note = null): void
    {
        static::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => request()?->ip(),
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
