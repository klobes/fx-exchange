<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxBankAccount;
use Botble\EmironFx\Models\FxBankEntry;
use Botble\EmironFx\Models\FxPartnerEntry;
use Botble\EmironFx\Models\FxTillMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Banka (0.7.0): lëvizjet futen me dorë dhe konfirmohen me dorë kundrejt ekstraktit.
 *
 *  deposit_cash          + derdhje arke → llogari   (lidhet me lëvizjen cash_out të arkës)
 *  withdraw_cash         − tërheqje nga llogaria → arkë (lidhet me cash_in të arkës)
 *  partner_prefund       − mbushje e gjendjes së parapaguar te partneri (prepaid +)
 *  partner_reimbursement + partneri rimburson (p.sh. tërheqjet MG) (detyrimi i partnerit −)
 *  partner_payment       − ne paguajmë partnerin (detyrimi ynë −, pra receivable +)
 *  other_in / other_out  ± të tjera (me shënim)
 *  reversal              ∓ kundërveprim i një gabimi (s'fshihet asgjë)
 */
class FxBankService
{
    public const SIGNS = [
        'deposit_cash' => 1, 'withdraw_cash' => -1, 'partner_prefund' => -1, 'partner_reimbursement' => 1,
        'partner_payment' => -1, 'other_in' => 1, 'other_out' => -1,
    ];

    public function balance(FxBankAccount $account, bool $confirmedOnly = false): string
    {
        $sum = FxBankEntry::query()->where('bank_account_id', $account->id)
            ->when($confirmedOnly, fn ($q) => $q->where('status', 'confirmed'))
            ->sum('amount');

        return FxMoney::clean(FxMoney::add((string) $account->opening_balance, (string) $sum));
    }

    public function record(array $data, int $userId): FxBankEntry
    {
        return DB::transaction(function () use ($data, $userId) {
            $account = FxBankAccount::query()->where('is_active', true)->findOrFail((int) $data['bank_account_id']);
            $type = (string) $data['type'];

            if (! isset(self::SIGNS[$type])) {
                throw ValidationException::withMessages(['type' => trans('plugins/emiron-fx::fx.bank.errors.type')]);
            }

            $amount = FxMoney::round(FxMoney::normalize($data['amount'] ?? '0'), 2);

            if (FxMoney::cmp($amount, '0') <= 0) {
                throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $account->currency_code])]);
            }

            $movementId = null;

            if (in_array($type, ['deposit_cash', 'withdraw_cash'], true)) {
                $movement = FxTillMovement::query()->lockForUpdate()->find((int) ($data['till_movement_id'] ?? 0));
                $expectedType = $type === 'deposit_cash' ? 'cash_out' : 'cash_in';

                if (! $movement || $movement->type !== $expectedType) {
                    throw ValidationException::withMessages(['till_movement_id' => trans('plugins/emiron-fx::fx.bank.errors.movement')]);
                }

                if ($movement->currency_code !== $account->currency_code || FxMoney::cmp(FxMoney::clean(ltrim((string) $movement->amount, '-')), $amount) !== 0) {
                    throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.bank.errors.mismatch', [
                        'amount' => FxMoney::clean(ltrim((string) $movement->amount, '-')), 'code' => $movement->currency_code,
                    ])]);
                }

                if (FxBankEntry::query()->where('till_movement_id', $movement->id)->exists()) {
                    throw ValidationException::withMessages(['till_movement_id' => trans('plugins/emiron-fx::fx.bank.errors.linked')]);
                }

                $movementId = $movement->id;
            }

            $partnerId = str_starts_with($type, 'partner_') ? (int) ($data['partner_id'] ?? 0) : null;

            if ($partnerId === 0) {
                throw ValidationException::withMessages(['partner_id' => trans('plugins/emiron-fx::fx.bank.errors.partner')]);
            }

            if (in_array($type, ['other_in', 'other_out'], true) && trim((string) ($data['note'] ?? '')) === '') {
                throw ValidationException::withMessages(['note' => trans('plugins/emiron-fx::fx.bank.errors.note')]);
            }

            $entry = FxBankEntry::query()->create([
                'bank_account_id' => $account->id,
                'entry_date' => $data['entry_date'],
                'type' => $type,
                'amount' => FxMoney::clean(FxMoney::mul($amount, (string) self::SIGNS[$type])),
                'till_movement_id' => $movementId,
                'partner_id' => $partnerId,
                'reference' => mb_substr(trim((string) ($data['reference'] ?? '')), 0, 120) ?: null,
                'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 500) ?: null,
                'status' => 'pending',
                'created_by' => $userId,
            ]);

            if ($partnerId) {
                [$prepaid, $receivable] = match ($type) {
                    'partner_prefund' => [$amount, '0'],
                    'partner_reimbursement' => ['0', FxMoney::neg($amount)],
                    'partner_payment' => ['0', $amount],
                };

                FxPartnerEntry::query()->create([
                    'partner_id' => $partnerId,
                    'entry_date' => $data['entry_date'],
                    'type' => match ($type) { 'partner_prefund' => 'prefund', 'partner_reimbursement' => 'reimbursement', default => 'payment' },
                    'currency_code' => $account->currency_code,
                    'prepaid_delta' => FxMoney::clean($prepaid),
                    'receivable_delta' => FxMoney::clean($receivable),
                    'bank_entry_id' => $entry->id,
                    'note' => $entry->reference,
                    'created_by' => $userId,
                ]);
            }

            return $entry;
        });
    }

    public function confirm(FxBankEntry $entry, ?string $statementRef, int $userId): FxBankEntry
    {
        $entry->forceFill([
            'status' => 'confirmed',
            'statement_ref' => mb_substr(trim((string) $statementRef), 0, 120) ?: null,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ])->save();

        return $entry;
    }

    /** Kundërveprimi: shumë e kundërt, kthen edhe efektin te partneri; lidhja me arkën lirohet. */
    public function reverse(FxBankEntry $entry, string $reason, int $userId): FxBankEntry
    {
        return DB::transaction(function () use ($entry, $reason, $userId) {
            $entry = FxBankEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->type === 'reversal' || FxBankEntry::query()->where('reverses_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.bank.errors.reversed')]);
            }

            if (mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_reason')]);
            }

            $reversal = FxBankEntry::query()->create([
                'bank_account_id' => $entry->bank_account_id,
                'entry_date' => now()->toDateString(),
                'type' => 'reversal',
                'amount' => FxMoney::clean(FxMoney::neg((string) $entry->amount)),
                'partner_id' => $entry->partner_id,
                'reverses_entry_id' => $entry->id,
                'reference' => $entry->reference,
                'note' => mb_substr(trim($reason), 0, 500),
                'status' => 'pending',
                'created_by' => $userId,
            ]);

            foreach (FxPartnerEntry::query()->where('bank_entry_id', $entry->id)->get() as $pe) {
                FxPartnerEntry::query()->create([
                    'partner_id' => $pe->partner_id,
                    'entry_date' => now()->toDateString(),
                    'type' => 'adjustment',
                    'currency_code' => $pe->currency_code,
                    'prepaid_delta' => FxMoney::clean(FxMoney::neg((string) $pe->prepaid_delta)),
                    'receivable_delta' => FxMoney::clean(FxMoney::neg((string) $pe->receivable_delta)),
                    'bank_entry_id' => $reversal->id,
                    'note' => 'Kundërveprim i bankës #' . $entry->id,
                    'created_by' => $userId,
                ]);
            }

            return $reversal;
        });
    }
}
