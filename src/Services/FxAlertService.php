<?php

namespace Botble\EmironFx\Services;

use Botble\Base\Facades\EmailHandler;
use Botble\EmironFx\Models\FxAlert;
use Botble\EmironFx\Models\FxOfficialRate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Njoftimet e zyrës së këmbimit. Tri raste:
 *
 *  deviation      — pas kursit zyrtar të ditës, kurset e zyrës dalin mbi
 *                   pragun e shmangies, OSE kursi zyrtar lëvizi më shumë se
 *                   pragu i lëvizjes ditore. Email-i jep edhe sugjerimin që
 *                   ruan marzhet e publikimit të fundit.
 *  import_failed  — importi nga BSH dështoi në përpjekjen e fundit të ditës.
 *  not_published  — faqja e BSH-së s'ka kurs për sot (festë ose vonesë).
 *
 * Asgjë s'publikohet automatikisht: njoftimi i thotë njeriut të veprojë.
 * Çdo njoftim (lloj + ditë + gjurmë) dërgohet vetëm një herë (fx_alerts).
 */
class FxAlertService
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly ReferenceRateService $references,
    ) {
    }

    /** "ExchangeRate-API 92.30 (+0.36%) · BQE 80.70 (-0.05%)" kundrejt kursit zyrtar. */
    private function referenceText(int $currencyId, ?string $official, bool &$diverges = false): string
    {
        $limit = (float) config('plugins.emiron-fx.general.reference_divergence_alert_percent', 1.5);
        $parts = [];

        foreach ($this->references->latest() as $source => $rows) {
            $ref = $rows->get($currencyId);

            if (! $ref) {
                continue;
            }

            $text = $this->references->label($source) . ' ' . fx_rate($ref->rate);

            if ($official !== null) {
                $div = (float) $this->rates->deviationPercent((string) $ref->rate, $official);
                $text .= sprintf(' (%+.2f%%)', $div);
                $diverges = $diverges || abs($div) > $limit;
            }

            $parts[] = $text;
        }

        return implode(' · ', $parts);
    }

    /** @return array<int,string> rreshtat e njoftimit (bosh = s'ka nevojë për veprim) */
    public function checkDeviation(): array
    {
        $today = now()->startOfDay();
        $threshold = (float) config('plugins.emiron-fx.general.deviation_threshold_percent', 3);
        $moveThreshold = (float) config('plugins.emiron-fx.general.official_move_alert_percent', 1);

        $official = $this->rates->officialRates($today)
            ->filter(fn (FxOfficialRate $r) => $r->rate_date->isSameDay($today));

        if ($official->isEmpty()) {
            return [];
        }

        $current = $this->rates->currentRates();
        $lines = [];
        $fingerprint = [];

        foreach ($this->rates->activeCurrencies() as $currency) {
            $off = $official->get($currency->id);

            if (! $off) {
                continue;
            }

            $offRate = (string) $off->rate;
            $cur = $current->get($currency->id);
            $reasons = [];

            if ($cur) {
                $buyDev = (float) $this->rates->deviationPercent((string) $cur->buy_rate, $offRate);
                $sellDev = (float) $this->rates->deviationPercent((string) $cur->sell_rate, $offRate);

                if (abs($buyDev) > $threshold || abs($sellDev) > $threshold) {
                    $reasons[] = sprintf('shmangia %+.2f%% / %+.2f%% (prag %s%%)', $buyDev, $sellDev, $threshold);
                }
            }

            $previous = FxOfficialRate::query()
                ->where('currency_id', $currency->id)
                ->whereDate('rate_date', '<', $today)
                ->orderByDesc('rate_date')
                ->orderByDesc('id')
                ->first();

            if ($previous) {
                $move = (float) $this->rates->deviationPercent($offRate, (string) $previous->rate);

                if (abs($move) > $moveThreshold) {
                    $reasons[] = sprintf('BSH lëvizi %+.2f%% nga %s (%s)', $move, fx_rate($previous->rate), $previous->rate_date->format('d.m'));
                }
            }

            $diverges = false;
            $refText = $this->referenceText($currency->id, $offRate, $diverges);

            if ($diverges) {
                $reasons[] = sprintf('BSH larg referencës së tregut > %s%%', config('plugins.emiron-fx.general.reference_divergence_alert_percent', 1.5));
            }

            if (! $reasons) {
                continue;
            }

            $line = sprintf('%-4s BSH %s', $currency->code, fx_rate($offRate));

            if ($cur) {
                $line .= sprintf(' | zyra: blerje %s / shitje %s', fx_rate($cur->buy_rate), fx_rate($cur->sell_rate));

                if ($suggest = $this->rates->suggestion($cur, $offRate)) {
                    $line .= sprintf(' | sugjerim: %s / %s', $suggest['buy'], $suggest['sell']);
                }
            } else {
                $line .= ' | zyra: pa kurs të publikuar';
            }

            if ($refText !== '') {
                $line .= "\n      tregu: " . $refText;
            }

            $lines[] = $line . "\n      " . implode('; ', $reasons);
            $fingerprint[] = $currency->code . ':' . $offRate . ':' . ($cur?->batch_uuid ?? '-');
        }

        if ($lines) {
            $this->send(
                'deviation',
                implode('|', $fingerprint),
                trans('plugins/emiron-fx::fx.alerts.deviation_title', ['date' => $today->format('d.m.Y')]),
                trans('plugins/emiron-fx::fx.alerts.deviation_intro'),
                $lines,
                route('fx.rates.create')
            );
        }

        return $lines;
    }

    /** Kursi i tregut (mesatarja e rekomanduar) ka lëvizur nga kursi i publikuar mbi prag (0.8.2). @param array<int,string> $lines */
    public function marketMoved(array $lines, string $fingerprintSource): void
    {
        $this->send(
            'market_moved',
            $fingerprintSource,
            trans('plugins/emiron-fx::fx.alerts.market_moved_title', ['date' => now()->format('d.m.Y')]),
            trans('plugins/emiron-fx::fx.alerts.market_moved_intro'),
            $lines,
            route('fx.rates.create')
        );
    }

    /** Shitje nën koston e stokut (0.8.4). */
    public function belowCost(string $note, string $number): void
    {
        $this->send(
            'below_cost',
            $number,
            trans('plugins/emiron-fx::fx.alerts.below_cost_title', ['number' => $number]),
            trans('plugins/emiron-fx::fx.alerts.below_cost_intro'),
            [$note],
            route('fx.transactions.index')
        );
    }

    public function importFailed(string $error): void
    {
        $this->send(
            'import_failed',
            sha1($error),
            trans('plugins/emiron-fx::fx.alerts.import_failed_title', ['date' => now()->format('d.m.Y')]),
            trans('plugins/emiron-fx::fx.alerts.import_failed_intro'),
            [$error],
            route('fx.official-rates.create')
        );
    }

    public function notPublished(?string $lastDate): void
    {
        $lines = [];

        foreach ($this->rates->activeCurrencies() as $currency) {
            $refText = $this->referenceText($currency->id, null);

            if ($refText !== '') {
                $lines[] = sprintf('%-4s tregu: %s', $currency->code, $refText);
            }
        }

        $this->send(
            'not_published',
            (string) $lastDate,
            trans('plugins/emiron-fx::fx.alerts.not_published_title', ['date' => now()->format('d.m.Y')]),
            trans('plugins/emiron-fx::fx.alerts.not_published_intro', ['last' => $lastDate ?? '—']),
            $lines,
            route('fx.official-rates.create')
        );
    }

    /** Kujtesë: raporti i BSH-së / lista AIF e ditës së mëparshme s'është shënuar i dorëzuar. */
    public function reportReminder(string $dateLabel, array $lines, string $deadline): void
    {
        $this->send(
            'report_reminder',
            $dateLabel . '|' . implode('|', $lines),
            trans('plugins/emiron-fx::fx.alerts.report_reminder_title', ['date' => $dateLabel]),
            trans('plugins/emiron-fx::fx.alerts.report_reminder_intro', ['deadline' => $deadline]),
            $lines,
            route('fx.reports.index')
        );
    }

    /** Verifikimi i natës i regjistrit dështoi (numërim ose zinxhir hash). */
    public function ledgerFailed(array $errors): void
    {
        $this->send(
            'ledger_failed',
            sha1(implode('|', $errors)),
            trans('plugins/emiron-fx::fx.alerts.ledger_failed_title', ['date' => now()->format('d.m.Y')]),
            trans('plugins/emiron-fx::fx.alerts.ledger_failed_intro'),
            array_slice($errors, 0, 50),
            route('fx.transactions.index')
        );
    }

    /** Gjendja e parapaguar te partneri ra nën pragun (një herë në ditë për partner). */
    public function prepaidLow(\Botble\EmironFx\Models\FxPartner $partner, string $balance): void
    {
        $this->send(
            'prepaid_low',
            $partner->id . '|' . now()->toDateString(),
            trans('plugins/emiron-fx::fx.alerts.prepaid_low_title', ['partner' => $partner->name]),
            trans('plugins/emiron-fx::fx.alerts.prepaid_low_intro', ['partner' => $partner->name, 'balance' => $balance, 'limit' => (string) $partner->prepaid_low_alert]),
            [],
            route('fx.bank.index')
        );
    }

    /** Detyrime të partnerëve (p.sh. tërheqjet MG) të parimbursuara pas afatit. */
    public function receivablesOverdue(array $lines): void
    {
        $this->send(
            'receivable_overdue',
            now()->toDateString() . '|' . implode('|', $lines),
            trans('plugins/emiron-fx::fx.alerts.receivable_overdue_title', ['date' => now()->format('d.m.Y')]),
            trans('plugins/emiron-fx::fx.alerts.receivable_overdue_intro'),
            $lines,
            route('fx.recon.index')
        );
    }

    /** @param array<int,string> $lines */
    private function send(string $type, string $fingerprintSource, string $title, string $intro, array $lines, string $url): void
    {
        $recipients = (array) config('plugins.emiron-fx.general.alert_emails', []);

        try {
            $alert = FxAlert::query()->create([
                'type' => $type,
                'alert_date' => now()->toDateString(),
                'fingerprint' => sha1($fingerprintSource),
                'payload' => ['title' => $title, 'intro' => $intro, 'lines' => $lines],
                'recipients' => implode(',', $recipients),
            ]);
        } catch (QueryException) {
            return; // i njëjti njoftim është dërguar tashmë sot
        }

        if (! $recipients) {
            $alert->update(['error' => 'FX_ALERT_EMAILS bosh — s\'u dërgua email.']);
            Log::warning('[emiron-fx] ' . $title . ' — s\'ka marrës (FX_ALERT_EMAILS).', ['lines' => $lines]);

            return;
        }

        try {
            $mailer = EmailHandler::setModule('emiron-fx')->setVariableValues([
                'fx_alert_title' => $title,
                'fx_alert_intro' => $intro,
                'fx_alert_lines' => implode("\n", $lines),
                'fx_action_url' => $url,
            ]);

            foreach ($recipients as $to) {
                $mailer->sendUsingTemplate('fx-rate-alert', $to);
            }

            $alert->update(['sent_at' => now()]);
        } catch (Throwable $exception) {
            $alert->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);
            report($exception);
        }
    }
}
