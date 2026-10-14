<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTill;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTillSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arka: hapja, furnizimi/derdhja, mbyllja me rakordim, miratimi.
 * Gjendja e arkës është e vazhdueshme (shuma e lëvizjeve); numërimi në
 * hapje/mbyllje që s'përputhet krijon lëvizje diference me arsye.
 */
class FxTillService
{
    public function __construct(private readonly FxHoldingService $holding)
    {
    }

    public function tillFor(int $userId): ?FxTill
    {
        return FxTill::query()->where('user_id', $userId)->where('is_active', true)->with('office')->first();
    }

    public function openSession(FxTill $till): ?FxTillSession
    {
        return FxTillSession::query()->where('till_id', $till->id)->where('status', FxTillSession::STATUS_OPEN)->latest('id')->first();
    }

    /** Monedhat e arkës: ALL + monedhat aktive + çdo monedhë me gjendje ≠ 0. */
    public function currencyCodes(FxTill $till): array
    {
        $codes = ['ALL'];

        foreach (FxCurrency::query()->active()->ordered()->pluck('code') as $code) {
            $codes[] = $code;
        }

        foreach ($this->holding->balances($till->id) as $code => $amount) {
            if (! FxMoney::isZero($amount) && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function open(FxTill $till, int $userId, array $counts, ?string $note): FxTillSession
    {
        return DB::transaction(function () use ($till, $userId, $counts, $note) {
            FxTill::query()->whereKey($till->id)->lockForUpdate()->first();

            if ($this->openSession($till)) {
                throw ValidationException::withMessages(['counts' => trans('plugins/emiron-fx::fx.counter.errors.already_open')]);
            }

            if (FxTillSession::query()->where('till_id', $till->id)->where('status', FxTillSession::STATUS_CLOSED)->exists()) {
                throw ValidationException::withMessages(['counts' => trans('plugins/emiron-fx::fx.counter.errors.previous_not_approved')]);
            }

            $expected = $this->holding->balances($till->id);
            $isFirst = ! FxTillMovement::query()->where('till_id', $till->id)->exists();
            $counts = $this->normalizeCounts($counts, $till);
            $diffs = $this->diffs($counts, $expected);

            if ($diffs && ! $isFirst && trim((string) $note) === '') {
                throw ValidationException::withMessages(['opening_note' => trans('plugins/emiron-fx::fx.counter.errors.variance_note')]);
            }

            if ($isFirst || $diffs) {
                $check = $this->holding->check($isFirst ? $counts : $diffs);

                if ($check['exceeds'] && FxMoney::cmp($check['after'], $check['before']) > 0) {
                    throw ValidationException::withMessages(['counts' => trans('plugins/emiron-fx::fx.counter.errors.holding_limit', $check)]);
                }
            }

            $session = FxTillSession::query()->create([
                'till_id' => $till->id,
                'user_id' => $userId,
                'business_date' => now()->toDateString(),
                'status' => FxTillSession::STATUS_OPEN,
                'opening_counts' => $counts,
                'opening_note' => $note,
                'opened_at' => now(),
            ]);

            foreach ($isFirst ? $counts : $diffs as $code => $amount) {
                if (FxMoney::isZero($amount)) {
                    continue;
                }

                FxTillMovement::query()->create([
                    'till_id' => $till->id,
                    'till_session_id' => $session->id,
                    'currency_code' => $code,
                    'amount' => FxMoney::clean($amount),
                    'type' => $isFirst ? 'initial' : 'opening_variance',
                    'note' => $note,
                    'created_by' => $userId,
                ]);
            }

            return $session;
        });
    }

    /** Furnizim (cash_in) ose derdhje (cash_out) — p.sh. nga/në bankë. */
    public function movement(FxTillSession $session, string $type, string $code, string $amount, string $note, int $userId): FxTillMovement
    {
        return DB::transaction(function () use ($session, $type, $code, $amount, $note, $userId) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.counter.errors.session_closed')]);
            }

            $amount = FxMoney::clean(FxMoney::normalize($amount));

            if (FxMoney::cmp($amount, '0') <= 0) {
                throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $code])]);
            }

            $signed = $type === 'cash_out' ? FxMoney::neg($amount) : $amount;

            if ($type === 'cash_out') {
                $balance = $this->holding->balances($session->till_id)[$code] ?? '0';

                if (FxMoney::cmp($balance, $amount) < 0) {
                    throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', ['code' => $code, 'balance' => $balance])]);
                }
            } else {
                $check = $this->holding->check([$code => $signed]);

                if ($check['exceeds']) {
                    throw ValidationException::withMessages(['amount' => trans('plugins/emiron-fx::fx.counter.errors.holding_limit', $check)]);
                }
            }

            return FxTillMovement::query()->create([
                'till_id' => $session->till_id,
                'till_session_id' => $session->id,
                'currency_code' => $code,
                'amount' => FxMoney::clean($signed),
                'type' => $type,
                'note' => $note,
                'created_by' => $userId,
            ]);
        });
    }

    /** Mbyllja: numërimi krahasohet me gjendjen e librit; diferenca → lëvizje me arsye. */
    public function close(FxTillSession $session, array $counts, ?string $note, int $userId): FxTillSession
    {
        return DB::transaction(function () use ($session, $counts, $note, $userId) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['counts' => trans('plugins/emiron-fx::fx.counter.errors.session_closed')]);
            }

            $till = $session->till;
            $expected = $this->holding->balances($till->id);
            $counts = $this->normalizeCounts($counts, $till);
            $diffs = $this->diffs($counts, $expected);

            if ($diffs && trim((string) $note) === '') {
                throw ValidationException::withMessages(['closing_note' => trans('plugins/emiron-fx::fx.counter.errors.variance_note')]);
            }

            foreach ($diffs as $code => $amount) {
                FxTillMovement::query()->create([
                    'till_id' => $till->id,
                    'till_session_id' => $session->id,
                    'currency_code' => $code,
                    'amount' => FxMoney::clean($amount),
                    'type' => 'closing_variance',
                    'note' => $note,
                    'created_by' => $userId,
                ]);
            }

            $session->forceFill([
                'status' => FxTillSession::STATUS_CLOSED,
                'closing_counts' => $counts,
                'closing_expected' => array_map(fn ($v) => FxMoney::clean($v), $expected),
                'closing_note' => $note,
                'closed_at' => now(),
            ])->save();

            return $session;
        });
    }

    public function approve(FxTillSession $session, int $userId): FxTillSession
    {
        return DB::transaction(function () use ($session, $userId) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== FxTillSession::STATUS_CLOSED) {
                throw ValidationException::withMessages(['session' => trans('plugins/emiron-fx::fx.counter.errors.not_closed')]);
            }

            $session->forceFill([
                'status' => FxTillSession::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            return $session;
        });
    }

    /** @return array<string,string> */
    private function normalizeCounts(array $counts, FxTill $till): array
    {
        $out = [];

        foreach ($this->currencyCodes($till) as $code) {
            $value = FxMoney::normalize($counts[$code] ?? '0');

            if (FxMoney::cmp($value, '0') < 0) {
                throw ValidationException::withMessages(["counts.$code" => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $code])]);
            }

            $out[$code] = FxMoney::clean($value);
        }

        return $out;
    }

    /** @return array<string,string> vetëm diferencat ≠ 0 (numëruar − libri) */
    private function diffs(array $counts, array $expected): array
    {
        $diffs = [];

        foreach (array_unique(array_merge(array_keys($counts), array_keys($expected))) as $code) {
            $diff = FxMoney::sub($counts[$code] ?? '0', $expected[$code] ?? '0');

            if (! FxMoney::isZero($diff)) {
                $diffs[$code] = FxMoney::clean($diff);
            }
        }

        return $diffs;
    }
}
