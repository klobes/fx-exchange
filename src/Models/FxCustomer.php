<?php

namespace Botble\EmironFx\Models;

use Botble\EmironFx\Casts\FxEncrypted;
use Botble\EmironFx\Support\FxCrypto;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Klient i identifikuar. Të dhënat personale janë të enkriptuara me FX_DATA_KEY (FxCrypto);
 * kërkimi me dokument bëhet përmes `doc_hash` (HMAC), pa dekriptim.
 */
class FxCustomer extends BaseModel
{
    protected $table = 'fx_customers';

    protected $fillable = [
        'type', 'client_category', 'first_name', 'last_name', 'legal_name', 'nipt', 'doc_type', 'doc_number',
        'doc_hash', 'doc_country', 'doc_expiry', 'nationality', 'birth_date', 'address', 'phone', 'is_pep',
        'risk_level', 'is_blocked', 'created_by',
    ];

    protected $casts = [
        'first_name' => FxEncrypted::class,
        'last_name' => FxEncrypted::class,
        'doc_number' => FxEncrypted::class,
        'birth_date' => FxEncrypted::class,
        'address' => FxEncrypted::class,
        'phone' => FxEncrypted::class,
        'is_pep' => 'bool',
        'is_blocked' => 'bool',
    ];

    protected $hidden = ['doc_hash'];

    public static function normalizedDoc(string $docType, string $docNumber): string
    {
        return strtoupper($docType) . '|' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $docNumber));
    }

    /** HMAC me çelësin e Emiron FX (FX_DATA_KEY; pa të, APP_KEY si në 0.5.0). */
    public static function docHash(string $docType, string $docNumber, ?string $key = null): string
    {
        return FxCrypto::blindIndex(self::normalizedDoc($docType, $docNumber), $key);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FxTransaction::class, 'customer_id');
    }

    public function displayName(): string
    {
        return $this->type === 'company'
            ? (string) ($this->legal_name ?: '—')
            : trim((string) $this->first_name . ' ' . (string) $this->last_name);
    }

    /** Numri i dokumentit i maskuar për ekranet e sportelit: AB••••••56 */
    public function maskedDoc(): string
    {
        $doc = (string) $this->doc_number;

        return strlen($doc) <= 4 ? str_repeat('•', strlen($doc)) : substr($doc, 0, 2) . str_repeat('•', max(2, strlen($doc) - 4)) . substr($doc, -2);
    }

    /** doc_expiry mbahet si string Y-m-d (pa cast date), që forma HTML `type=date` ta shfaqë drejt. */
    public function docExpired(): bool
    {
        return filled($this->doc_expiry) && substr((string) $this->doc_expiry, 0, 10) < now()->toDateString();
    }
}
