<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Lëvizje bankare (futur me dorë). Shuma, data, lloji dhe lidhjet s'ndryshojnë pas
 * regjistrimit; ndryshojnë vetëm fushat e konfirmimit. Gabimi korrigjohet me "reversal".
 */
class FxBankEntry extends BaseModel
{
    public const UPDATED_AT = null;

    private const LOCKED = ['bank_account_id', 'entry_date', 'type', 'amount', 'till_movement_id', 'partner_id', 'reverses_entry_id'];

    protected $table = 'fx_bank_entries';

    protected $fillable = [
        'bank_account_id', 'entry_date', 'type', 'amount', 'till_movement_id', 'partner_id', 'reverses_entry_id',
        'reference', 'note', 'status', 'statement_ref', 'confirmed_by', 'confirmed_at', 'created_by',
    ];

    protected $casts = ['entry_date' => 'date', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            foreach (self::LOCKED as $field) {
                if ($entry->isDirty($field)) {
                    throw new LogicException("fx_bank_entries.{$field} s'ndryshon pas regjistrimit — përdor kundërveprimin.");
                }
            }
        });

        static::deleting(fn () => throw new LogicException('fx_bank_entries s\'fshihen — përdor kundërveprimin.'));
    }

    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FxBankAccount::class, 'bank_account_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(FxPartner::class, 'partner_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
