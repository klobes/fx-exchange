<?php

namespace Botble\EmironFx\Models;

use Botble\EmironFx\Casts\FxEncrypted;
use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

/**
 * Veprim këmbimi — i pandryshueshëm. Anulimi është veprim tjetër me
 * `voids_transaction_id`; origjinali mbetet ashtu siç u regjistrua.
 */
class FxTransaction extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    public const BUY = 'buy';   // zyra blen valutë (klienti shet)
    public const SELL = 'sell'; // zyra shet valutë (klienti blen)

    protected $table = 'fx_transactions';

    protected $fillable = [
        'office_id', 'year', 'seq', 'number', 'till_id', 'till_session_id', 'user_id', 'business_date', 'occurred_at',
        'direction', 'currency_id', 'currency_code', 'unit', 'foreign_amount', 'rate', 'lek_amount', 'rate_id',
        'official_rate', 'official_rate_date', 'eur_equivalent', 'client_category', 'customer_id', 'payment_method', 'flags',
        'cdd_reference', 'voids_transaction_id', 'void_reason', 'prev_hash', 'hash',
        // 0.8.0 — kryqëzimi valutë↔valutë (kolona opsionale, shih migrimin 2026_10_09_000008)
        'leg', 'pair_transaction_id', 'change_amount', 'change_currency',
    ];

    protected $casts = [
        'business_date' => 'date',
        'occurred_at' => 'datetime',
        'official_rate_date' => 'date',
        'flags' => 'array',
        // Të dhëna personale (emri, dokumenti): të enkriptuara me APP_KEY. S'hyjnë në hash.
        'cdd_reference' => FxEncrypted::class,
    ];

    public function office(): BelongsTo
    {
        return $this->belongsTo(FxOffice::class, 'office_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(FxCustomer::class, 'customer_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FxCurrency::class, 'currency_id');
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(FxTill::class, 'till_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FxTillSession::class, 'till_session_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function voids(): BelongsTo
    {
        return $this->belongsTo(self::class, 'voids_transaction_id');
    }

    public function voidedBy(): HasOne
    {
        return $this->hasOne(self::class, 'voids_transaction_id');
    }

    public function isReversal(): bool
    {
        return $this->voids_transaction_id !== null;
    }

    /** A ekzistojnë kolonat e kryqëzimit (migrimi 0.8.0)? Kontrollohet një herë; mbron rrugët e vjetra para migrimit. */
    public static function supportsCross(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('fx_transactions', 'leg');
    }

    /** Pa këmbisën 2 të kryqëzimeve (një kryqëzim = një veprim për klientin dhe për numërimin). */
    public function scopeNotSecondLeg($query)
    {
        return self::supportsCross()
            ? $query->where(fn ($q) => $q->whereNull('leg')->orWhere('leg', '!=', 2))
            : $query;
    }

    /** Këmbisa 2 e kryqëzimit (nga këmbisa 1). */
    public function pairLeg(): HasOne
    {
        return $this->hasOne(self::class, 'pair_transaction_id');
    }

    /** Këmbisa 1 e kryqëzimit (nga këmbisa 2). */
    public function firstLeg(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pair_transaction_id');
    }

    public function isCross(): bool
    {
        return self::supportsCross() && $this->leg !== null;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, (array) $this->flags, true);
    }
}
