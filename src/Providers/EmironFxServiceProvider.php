<?php

namespace Botble\EmironFx\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Facades\EmailHandler;
use Botble\EmironFx\Commands\ImportOfficialRatesCommand;
use Botble\EmironFx\Commands\ImportReferenceRatesCommand;
use Botble\EmironFx\Commands\PurgeTestDataCommand;
use Botble\EmironFx\Commands\ReportRemindersCommand;
use Botble\EmironFx\Commands\RotateKeyCommand;
use Botble\EmironFx\Commands\VerifyLedgerCommand;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\EmironFx\Services\FxRateService;
use Botble\Shortcode\Compilers\Shortcode as ShortcodeCompiler;
use Botble\Shortcode\Forms\ShortcodeForm;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

/**
 * Emiron FX — plugin i pavarur nga RadiusManager (s'e importon, s'varet prej tij).
 * RULE_123: boot() s'ka `return` të hershëm.
 */
class EmironFxServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->app->singleton(FxRateService::class);
        $this->app->singleton(\Botble\EmironFx\Services\SourceRegistry::class);
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/emiron-fx')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['general', 'permissions', 'email'])
            ->loadAndPublishTranslations()
            ->loadRoutes(['web'])
            ->loadAndPublishViews()
            ->loadMigrations()
            ->publishAssets();

        $this->registerMenu();

        if ($this->app->runningInConsole()) {
            $this->commands([ImportOfficialRatesCommand::class, ImportReferenceRatesCommand::class, VerifyLedgerCommand::class, ReportRemindersCommand::class, RotateKeyCommand::class, PurgeTestDataCommand::class]);
        }

        $this->registerSchedule();

        $this->app->booted(function (): void {
            $this->registerShortcode();

            // Shablloni i njoftimit te Settings → Email (motori kanonik i Botble-s).
            if (class_exists(EmailHandler::class)) {
                EmailHandler::addTemplateSettings('emiron-fx', config('plugins.emiron-fx.email', []));
            }
        });
    }

    /**
     * Importi i kursit zyrtar të BSH-së, ditë pune, ora e Tiranës.
     * Plugin-i është i pavarur nga RadiusManager, ndaj orari deklarohet këtu,
     * i dukshëm dhe i fikshëm me FX_BOA_IMPORT_ENABLED=false (s'është cron i fshehur).
     * Kërkon që `php artisan schedule:run` të xhirojë çdo minutë (si për RadiusManager).
     */
    private function registerSchedule(): void
    {
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $tz = config('app.timezone', 'Europe/Tirane');

            // Faza 2c/2d: kujtesat e raporteve dhe verifikimi i natës i regjistrit (gjithmonë aktive).
            $schedule->command('fx:report-reminders')
                ->weekdays()->at((string) config('plugins.emiron-fx.general.report_reminder_time', '09:30'))->timezone($tz)
                ->withoutOverlapping(10)->name('emiron-fx:report-reminders');

            $schedule->command('fx:verify-ledger --notify')
                ->dailyAt('02:30')->timezone($tz)
                ->withoutOverlapping(30)->name('emiron-fx:verify-ledger');

            // Referenca e tregut (jo zyrtare) — para kontrollit të fundit dhe pas BQE-së (~16:00 CET).
            // 0.8.1: komanda vendos vetë cilat burime janë aktive (env + burimet JSON nga paneli); gjithmonë e planifikuar.
            foreach ((array) config('plugins.emiron-fx.general.reference_import_times', []) as $time) {
                $schedule->command('fx:import-reference')
                    ->weekdays()->at($time)->timezone($tz)
                    ->withoutOverlapping(10)
                    ->name('emiron-fx:import-reference@' . $time);
            }

            // Importi i kursit zyrtar të BSH-së (i fikshëm me FX_BOA_IMPORT_ENABLED=false).
            if (! config('plugins.emiron-fx.general.boa_import_enabled', true)) {
                return;
            }

            foreach ((array) config('plugins.emiron-fx.general.boa_import_times', []) as $time) {
                $schedule->command('fx:import-official')
                    ->weekdays()->at($time)->timezone($tz)
                    ->withoutOverlapping(10)
                    ->name('emiron-fx:import-official@' . $time);
            }

            $final = (string) config('plugins.emiron-fx.general.boa_import_final_time', '13:30');

            $schedule->command('fx:import-official --final')
                ->weekdays()->at($final)->timezone($tz)
                ->withoutOverlapping(10)
                ->name('emiron-fx:import-official-final@' . $final);
        });
    }

    /**
     * RULE_043: pa zë menyje veçoria s'ekziston.
     *
     * Katër grupe sipas rolit (menyja e Botble-s: grup → zëra, dy nivele):
     *   Sporteli    — puna e përditshme e arkëtarit dhe supervizorit
     *   Kurset      — publikimi, kursi i BSH-së, referenca, monedhat
     *   Compliance  — klientët, AML, sinjalizimet AIF, raportet
     *   Konfigurimi — zyrat dhe arkat
     * Një grup shfaqet vetëm nëse përdoruesi ka të paktën një leje brenda tij.
     */
    private function registerMenu(): void
    {
        DashboardMenu::beforeRetrieving(function (): void {
            $groups = [
                'counter' => [
                    'priority' => 4, 'icon' => 'ti ti-cash-register', 'name' => 'plugins/emiron-fx::fx.menu.group_counter',
                    'items' => [
                        ['my', 'fx.my', [], 'plugins/emiron-fx::fx.my.title', 'ti ti-user-circle', ['fx.counter.use']],
                        ['counter', 'fx.counter.index', [], 'plugins/emiron-fx::fx.counter.title', 'ti ti-cash-register', ['fx.counter.use']],
                        ['live', 'fx.sessions.live', [], 'plugins/emiron-fx::fx.sessions.live_title', 'ti ti-cash', ['fx.sessions.index']],
                        ['sessions', 'fx.sessions.index', [], 'plugins/emiron-fx::fx.sessions.title', 'ti ti-clipboard-check', ['fx.sessions.index']],
                        ['transactions', 'fx.transactions.index', [], 'plugins/emiron-fx::fx.transactions.title', 'ti ti-list-details', ['fx.transactions.index']],
                    ],
                ],
                'rates' => [
                    'priority' => 5, 'icon' => 'ti ti-currency-euro', 'name' => 'plugins/emiron-fx::fx.menu.group_rates',
                    'items' => [
                        ['publish', 'fx.rates.create', [], 'plugins/emiron-fx::fx.rates.publish', 'ti ti-arrows-exchange', ['fx.rates.publish']],
                        ['history', 'fx.rates.index', [], 'plugins/emiron-fx::fx.rates.history', 'ti ti-history', ['fx.rates.index']],
                        ['official', 'fx.official-rates.index', [], 'plugins/emiron-fx::fx.official.title', 'ti ti-building-bank', ['fx.official-rates.index']],
                        ['sources', 'fx.sources.index', [], 'plugins/emiron-fx::fx.sources.title', 'ti ti-plug-connected', ['fx.sources.index']],
                        ['reference', 'fx.reference.index', [], 'plugins/emiron-fx::fx.reference.title', 'ti ti-chart-line', ['fx.reference.index']],
                        ['currencies', 'fx.currencies.index', [], 'plugins/emiron-fx::fx.currencies.title', 'ti ti-coins', ['fx.currencies.index']],
                    ],
                ],
                'finance' => [
                    'priority' => 6, 'icon' => 'ti ti-building-bank', 'name' => 'plugins/emiron-fx::fx.menu.group_finance',
                    'items' => [
                        ['dashboard', 'fx.dashboard', [], 'plugins/emiron-fx::fx.dashboard.title', 'ti ti-layout-dashboard', ['fx.dashboard.index']],
                        ['recon', 'fx.recon.index', [], 'plugins/emiron-fx::fx.recon.title', 'ti ti-circle-check', ['fx.bank.index']],
                        ['bank', 'fx.bank.index', [], 'plugins/emiron-fx::fx.bank.title', 'ti ti-building-bank', ['fx.bank.index']],
                        ['partner_report', 'fx.partners.report', [], 'plugins/emiron-fx::fx.partner_report.title', 'ti ti-report-money', ['fx.reports.index']],
                    ],
                ],
                'compliance' => [
                    'priority' => 7, 'icon' => 'ti ti-shield-check', 'name' => 'plugins/emiron-fx::fx.menu.group_compliance',
                    'items' => [
                        ['reports', 'fx.reports.index', [], 'plugins/emiron-fx::fx.reports.title', 'ti ti-file-spreadsheet', ['fx.reports.index']],
                        ['aif', 'fx.transactions.aif', [], 'plugins/emiron-fx::fx.transactions.aif_title', 'ti ti-alert-triangle', ['fx.transactions.index']],
                        ['customers', 'fx.customers.index', [], 'plugins/emiron-fx::fx.customers.title', 'ti ti-id', ['fx.customers.index']],
                        ['aml', 'fx.aml.index', [], 'plugins/emiron-fx::fx.aml.title', 'ti ti-shield-exclamation', ['fx.aml.manage']],
                    ],
                ],
                'settings' => [
                    'priority' => 8, 'icon' => 'ti ti-settings', 'name' => 'plugins/emiron-fx::fx.menu.group_settings',
                    'items' => [
                        ['offices', 'fx.offices.index', [], 'plugins/emiron-fx::fx.offices.title', 'ti ti-building', ['fx.offices.manage']],
                        ['tills', 'fx.tills.index', [], 'plugins/emiron-fx::fx.tills.title', 'ti ti-building-store', ['fx.offices.manage']],
                        ['holidays', 'fx.holidays.index', [], 'plugins/emiron-fx::fx.holidays.title', 'ti ti-calendar-event', ['fx.holidays.manage']],
                        ['partners', 'fx.partners.index', [], 'plugins/emiron-fx::fx.partners.title', 'ti ti-briefcase', ['fx.partners.manage']],
                    ],
                ],
            ];

            $menu = DashboardMenu::make();

            foreach ($groups as $groupKey => $group) {
                $groupId = 'cms-plugins-emiron-fx-' . $groupKey;

                $menu->registerItem([
                    'id' => $groupId,
                    'priority' => $group['priority'],
                    'name' => $group['name'],
                    'icon' => $group['icon'],
                    'url' => null,
                    // Grupi shfaqet nëse përdoruesi ka qoftë një nga lejet e zërave.
                    'permissions' => array_values(array_unique(array_merge(...array_map(fn ($i) => $i[5], $group['items'])))),
                ]);

                foreach ($group['items'] as $position => [$key, $route, $params, $name, $icon, $permissions]) {
                    $menu->registerItem([
                        'id' => $groupId . '-' . $key,
                        'priority' => $position + 1,
                        'parent_id' => $groupId,
                        'name' => $name,
                        'icon' => $icon,
                        'url' => fn () => route($route, $params),
                        'permissions' => $permissions,
                    ]);
                }
            }
        });
    }

    /** Tabela publike e kurseve + konvertuesi: [fx-rates][/fx-rates] */
    private function registerShortcode(): void
    {
        if (! function_exists('add_shortcode') || ! function_exists('shortcode')) {
            return;
        }

        add_shortcode('fx-rates', trans('plugins/emiron-fx::fx.shortcode.name'), trans('plugins/emiron-fx::fx.shortcode.description'), function (ShortcodeCompiler $shortcode) {
            $board = app(FxRateService::class)->publicBoard();

            if (! $board['rows']) {
                return '<!-- fx-rates: s\'ka kurse të publikuara -->';
            }

            return view('plugins/emiron-fx::shortcodes.rates', [
                'shortcode' => $shortcode,
                'board' => $board,
                'showOfficial' => $shortcode->show_official !== 'no',
                'showConverter' => $shortcode->show_converter !== 'no',
            ])->render();
        });

        shortcode()->setAdminConfig('fx-rates', function (array $attributes) {
            $yesNo = ['yes' => trans('core/base::base.yes'), 'no' => trans('core/base::base.no')];

            return ShortcodeForm::createFromArray($attributes)
                ->withLazyLoading()
                ->add('title', TextField::class, TextFieldOption::make()->label(trans('plugins/emiron-fx::fx.shortcode.title')))
                ->add('subtitle', TextField::class, TextFieldOption::make()->label(trans('plugins/emiron-fx::fx.shortcode.subtitle')))
                ->add('show_official', SelectField::class, SelectFieldOption::make()
                    ->label(trans('plugins/emiron-fx::fx.shortcode.show_official'))
                    ->choices($yesNo)
                    ->selected(Arr::get($attributes, 'show_official', 'yes')))
                ->add('show_converter', SelectField::class, SelectFieldOption::make()
                    ->label(trans('plugins/emiron-fx::fx.shortcode.show_converter'))
                    ->choices($yesNo)
                    ->selected(Arr::get($attributes, 'show_converter', 'yes')));
        });
    }
}
