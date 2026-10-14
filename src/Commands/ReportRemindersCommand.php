<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Models\FxReport;
use Botble\EmironFx\Services\FxAlertService;
use Botble\EmironFx\Services\FxCalendar;
use Botble\EmironFx\Services\FxReportService;
use Illuminate\Console\Command;

/**
 * php artisan fx:report-reminders [--date=Y-m-d]
 *
 * Kontrollon ditët nga dita e fundit e punës deri dje (të hënën: e premte,
 * e shtunë, e diel). Për çdo ditë me veprime: raporti i BSH-së, dhe lista AIF
 * kur ka klientë mbi prag, duhet të jenë shënuar të dorëzuara — përndryshe email.
 */
class ReportRemindersCommand extends Command
{
    protected $signature = 'fx:report-reminders {--date= : Kontrollo vetëm këtë ditë (Y-m-d)}';

    protected $description = 'Emiron FX: kujton raportin e BSH-së dhe listën AIF të pa-dorëzuara';

    public function handle(FxReportService $reports, FxAlertService $alerts): int
    {
        $dates = [];

        if ($this->option('date')) {
            $dates[] = now()->parse($this->option('date'))->startOfDay();
        } else {
            // Nga dita e fundit e punës deri dje (përfshin fundjavat dhe festat e ndërmjetme).
            $calendar = app(FxCalendar::class);

            if (! $calendar->isBusinessDay(now())) {
                $this->info('Sot s\'është ditë pune — kujtesat nisen ditën e ardhshme të punës.');

                return self::SUCCESS;
            }

            $day = $calendar->previousBusinessDay(now());

            while ($day->lt(now()->startOfDay())) {
                $dates[] = $day->copy();
                $day->addDay();
            }
        }

        $any = false;

        foreach ($dates as $date) {
            $lines = [];
            $txCount = $reports->validTransactions($date)->count();

            if ($txCount > 0 && ! $this->submitted(FxReportService::BOA, $date)) {
                $lines[] = trans('plugins/emiron-fx::fx.alerts.boa_pending', ['date' => $date->format('d.m.Y'), 'count' => $txCount]);
            }

            $aif = $reports->aifData($date);

            if ($aif && ! $this->submitted(FxReportService::AIF, $date)) {
                $lines[] = trans('plugins/emiron-fx::fx.alerts.aif_pending', ['date' => $date->format('d.m.Y'), 'count' => count($aif)]);
            }

            if ($lines) {
                $any = true;
                $alerts->reportReminder($date->format('d.m.Y'), $lines, $reports->deadline($date)->format('d.m.Y H:i'));
                $this->warn(implode("\n", $lines));
            }
        }

        // 0.7.0: detyrimet e partnerëve të parimbursuara pas afatit (dita e ardhshme e punës).
        $overdue = [];

        foreach (\Botble\EmironFx\Models\FxPartner::query()->where('is_active', true)->get() as $partner) {
            foreach (app(\Botble\EmironFx\Services\FxReconService::class)->receivableAging($partner->id)->where('overdue', true) as $item) {
                $overdue[] = sprintf('%s: %s %s nga %s', $partner->name, $item['open'], $item['currency'], $item['date']->format('d.m.Y'));
            }
        }

        if ($overdue) {
            $any = true;
            $alerts->receivablesOverdue($overdue);
            $this->warn(implode("\n", $overdue));
        }

        if (! $any) {
            $this->info('Asgjë në pritje.');
        }

        return self::SUCCESS;
    }

    private function submitted(string $type, $date): bool
    {
        return FxReport::query()->where('type', $type)->whereDate('report_date', $date)->whereNotNull('submitted_at')->exists();
    }
}
