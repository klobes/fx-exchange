<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxBankAccount extends BaseModel
{
    protected $table = 'fx_bank_accounts';

    protected $fillable = [
        'bank_name', 'iban', 'account_number', 'swift', 'branch', 'account_holder', 'contact_person', 'contact_phone', 'note',
        'currency_code', 'opening_balance', 'opening_date', 'is_active',
    ];

    protected $casts = ['opening_date' => 'date', 'is_active' => 'bool'];

    public function entries(): HasMany
    {
        return $this->hasMany(FxBankEntry::class, 'bank_account_id');
    }

    public function label(): string
    {
        $tail = $this->iban ?: ($this->account_number ?? '');

        return trim($this->bank_name . ' · ' . $this->currency_code . ($tail ? ' · …' . substr($tail, -4) : ''));
    }
}
