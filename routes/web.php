<?php

use Botble\Base\Facades\AdminHelper;
use Botble\EmironFx\Http\Controllers\FxCounterController;
use Botble\EmironFx\Http\Controllers\FxAmlController;
use Botble\EmironFx\Http\Controllers\FxBankController;
use Botble\EmironFx\Http\Controllers\FxCurrencyController;
use Botble\EmironFx\Http\Controllers\FxFinanceController;
use Botble\EmironFx\Http\Controllers\FxPartnerAdminController;
use Botble\EmironFx\Http\Controllers\FxPartnerOpController;
use Botble\EmironFx\Http\Controllers\FxDashboardController;
use Botble\EmironFx\Http\Controllers\FxDocumentController;
use Botble\EmironFx\Http\Controllers\FxHolidayController;
use Botble\EmironFx\Http\Controllers\FxCustomerController;
use Botble\EmironFx\Http\Controllers\FxReportController;
use Botble\EmironFx\Http\Controllers\FxOfficeController;
use Botble\EmironFx\Http\Controllers\FxSessionController;
use Botble\EmironFx\Http\Controllers\FxTransactionController;
use Botble\EmironFx\Http\Controllers\FxOfficialRateController;
use Botble\EmironFx\Http\Controllers\FxRateController;
use Botble\EmironFx\Http\Controllers\FxReferenceRateController;
use Botble\EmironFx\Http\Controllers\FxSourceController;
use Illuminate\Support\Facades\Route;

/*
 * Emiron FX — route-t admin. Çdo route ka `permission` (RULE_026) dhe
 * controller-i e rikontrollon me fx_can() si veprimin e parë (RULE_030).
 * Emrat nën prefiksin unik `fx.` (RULE_042).
 */
AdminHelper::registerRoutes(function (): void {
    Route::group(['prefix' => 'fx', 'as' => 'fx.'], function (): void {
        Route::group(['prefix' => 'rates', 'as' => 'rates.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxRateController::class . '@index', 'permission' => 'fx.rates.index']);
            Route::get('board', ['as' => 'board', 'uses' => FxRateController::class . '@board', 'permission' => false]);
            Route::get('publish', ['as' => 'create', 'uses' => FxRateController::class . '@create', 'permission' => 'fx.rates.publish']);
            Route::post('publish', ['as' => 'store', 'uses' => FxRateController::class . '@store', 'permission' => 'fx.rates.publish']);
        });

        Route::group(['prefix' => 'official-rates', 'as' => 'official-rates.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxOfficialRateController::class . '@index', 'permission' => 'fx.official-rates.index']);
            Route::get('create', ['as' => 'create', 'uses' => FxOfficialRateController::class . '@create', 'permission' => 'fx.official-rates.create']);
            Route::post('create', ['as' => 'store', 'uses' => FxOfficialRateController::class . '@store', 'permission' => 'fx.official-rates.create']);
            Route::post('import', ['as' => 'import', 'uses' => FxOfficialRateController::class . '@import', 'permission' => 'fx.official-rates.create']);
        });

        // 0.8.1 — Qendra e burimeve të kurseve
        Route::group(['prefix' => 'sources', 'as' => 'sources.'], function (): void {
            Route::get('', ['as' => 'index', 'uses' => FxSourceController::class . '@index', 'permission' => 'fx.sources.index']);
            Route::post('{code}/run', ['as' => 'run', 'uses' => FxSourceController::class . '@run', 'permission' => 'fx.sources.index'])->where('code', '[a-z0-9_]+');
            Route::post('market', ['as' => 'market', 'uses' => FxSourceController::class . '@market', 'permission' => 'fx.sources.manage']);
            Route::post('{code}/secret', ['as' => 'secret', 'uses' => FxSourceController::class . '@secret', 'permission' => 'fx.sources.manage'])->where('code', '[a-z0-9_]+');
            Route::post('{code}/average', ['as' => 'average', 'uses' => FxSourceController::class . '@average', 'permission' => 'fx.sources.manage'])->where('code', '[a-z0-9_]+');
            Route::post('{code}/toggle', ['as' => 'toggle', 'uses' => FxSourceController::class . '@toggle', 'permission' => 'fx.sources.manage'])->where('code', '[a-z0-9_]+');
            Route::get('{code}/history', ['as' => 'history', 'uses' => FxSourceController::class . '@history', 'permission' => 'fx.sources.index'])->where('code', '[a-z0-9_]+');
        });

        Route::group(['prefix' => 'reference', 'as' => 'reference.'], function (): void {
            Route::get('', ['as' => 'index', 'uses' => FxReferenceRateController::class . '@index', 'permission' => 'fx.reference.index']);
            Route::match(['GET', 'POST'], 'history', ['as' => 'history', 'uses' => FxReferenceRateController::class . '@history', 'permission' => 'fx.reference.index']);
            Route::post('import', ['as' => 'import', 'uses' => FxReferenceRateController::class . '@import', 'permission' => 'fx.reference.import']);
        });

        // Faza 2a — sporteli
        Route::group(['prefix' => 'counter', 'as' => 'counter.'], function (): void {
            Route::get('', ['as' => 'index', 'uses' => FxCounterController::class . '@index', 'permission' => 'fx.counter.use']);
            Route::post('open', ['as' => 'open', 'uses' => FxCounterController::class . '@open', 'permission' => 'fx.counter.use']);
            Route::post('transaction', ['as' => 'store', 'uses' => FxCounterController::class . '@store', 'permission' => 'fx.counter.use']);
            Route::get('receipt/{fxTransaction}', ['as' => 'receipt', 'uses' => FxCounterController::class . '@receipt', 'permission' => 'fx.counter.use'])->wherePrimaryKey('fxTransaction');
            Route::post('void/{fxTransaction}', ['as' => 'void', 'uses' => FxCounterController::class . '@void', 'permission' => 'fx.counter.void'])->wherePrimaryKey('fxTransaction');
            Route::post('movement', ['as' => 'movement', 'uses' => FxCounterController::class . '@movement', 'permission' => 'fx.counter.movement']);
            Route::get('close', ['as' => 'close-form', 'uses' => FxCounterController::class . '@closeForm', 'permission' => 'fx.counter.use']);
            Route::post('close', ['as' => 'close', 'uses' => FxCounterController::class . '@close', 'permission' => 'fx.counter.use']);
        });

        Route::group(['prefix' => 'sessions', 'as' => 'sessions.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxSessionController::class . '@index', 'permission' => 'fx.sessions.index']);
            Route::get('open', ['as' => 'live', 'uses' => FxSessionController::class . '@live', 'permission' => 'fx.sessions.index']);
            Route::get('{fxSession}', ['as' => 'show', 'uses' => FxSessionController::class . '@show', 'permission' => 'fx.sessions.index'])->wherePrimaryKey('fxSession');
            Route::post('{fxSession}/approve', ['as' => 'approve', 'uses' => FxSessionController::class . '@approve', 'permission' => 'fx.sessions.approve'])->wherePrimaryKey('fxSession');
        });

        Route::match(['GET', 'POST'], 'transactions/aif', ['as' => 'transactions.aif', 'uses' => FxTransactionController::class . '@aif', 'permission' => 'fx.transactions.index']);
        Route::match(['GET', 'POST'], 'transactions', ['as' => 'transactions.index', 'uses' => FxTransactionController::class . '@index', 'permission' => 'fx.transactions.index']);

        // Faza 2b — klientët dhe AML
        Route::group(['prefix' => 'customers', 'as' => 'customers.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxCustomerController::class . '@index', 'permission' => 'fx.customers.index']);
            Route::get('search', ['as' => 'search', 'uses' => FxCustomerController::class . '@search', 'permission' => false]);
            Route::get('create', ['as' => 'create', 'uses' => FxCustomerController::class . '@create', 'permission' => 'fx.customers.edit']);
            Route::post('create', ['as' => 'store', 'uses' => FxCustomerController::class . '@store', 'permission' => 'fx.customers.edit']);
            Route::get('edit/{fxCustomer}', ['as' => 'edit', 'uses' => FxCustomerController::class . '@edit', 'permission' => 'fx.customers.index'])->wherePrimaryKey('fxCustomer');
            Route::post('edit/{fxCustomer}', ['as' => 'update', 'uses' => FxCustomerController::class . '@update', 'permission' => 'fx.customers.edit'])->wherePrimaryKey('fxCustomer');
        });

        Route::group(['prefix' => 'aml', 'as' => 'aml.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxAmlController::class . '@index', 'permission' => 'fx.aml.manage']);
            Route::post('open', ['as' => 'store', 'uses' => FxAmlController::class . '@store', 'permission' => false]);
            Route::get('{fxAmlCase}', ['as' => 'show', 'uses' => FxAmlController::class . '@show', 'permission' => 'fx.aml.manage'])->wherePrimaryKey('fxAmlCase');
            Route::post('{fxAmlCase}', ['as' => 'update', 'uses' => FxAmlController::class . '@update', 'permission' => 'fx.aml.manage'])->wherePrimaryKey('fxAmlCase');
        });

        // 0.7.0 — partnerët, banka, qarku, paneli personal
        Route::post('counter/partner', ['as' => 'counter.partner', 'uses' => FxPartnerOpController::class . '@store', 'permission' => 'fx.counter.use']);
        Route::post('counter/partner/void/{fxPartnerOp}', ['as' => 'counter.partner.void', 'uses' => FxPartnerOpController::class . '@void', 'permission' => 'fx.counter.void'])->wherePrimaryKey('fxPartnerOp');
        Route::get('my', ['as' => 'my', 'uses' => FxFinanceController::class . '@mine', 'permission' => 'fx.counter.use']);

        Route::get('partners', ['as' => 'partners.index', 'uses' => FxPartnerAdminController::class . '@index', 'permission' => 'fx.partners.manage']);
        Route::post('partners', ['as' => 'partners.store', 'uses' => FxPartnerAdminController::class . '@storePartner', 'permission' => 'fx.partners.manage']);
        Route::post('partners/services', ['as' => 'partners.services.store', 'uses' => FxPartnerAdminController::class . '@storeService', 'permission' => 'fx.partners.manage']);
        Route::get('partners/report', ['as' => 'partners.report', 'uses' => FxFinanceController::class . '@partnerReport', 'permission' => 'fx.reports.index']);

        Route::get('bank', ['as' => 'bank.index', 'uses' => FxBankController::class . '@index', 'permission' => 'fx.bank.index']);
        Route::post('bank/accounts', ['as' => 'bank.accounts.store', 'uses' => FxBankController::class . '@storeAccount', 'permission' => 'fx.bank.manage']);
        Route::get('bank/accounts/{fxBankAccount}/edit', ['as' => 'bank.accounts.edit', 'uses' => FxBankController::class . '@editAccount', 'permission' => 'fx.bank.manage'])->wherePrimaryKey('fxBankAccount');
        Route::post('bank/accounts/{fxBankAccount}', ['as' => 'bank.accounts.update', 'uses' => FxBankController::class . '@updateAccount', 'permission' => 'fx.bank.manage'])->wherePrimaryKey('fxBankAccount');
        Route::post('bank/entries', ['as' => 'bank.entries.store', 'uses' => FxBankController::class . '@storeEntry', 'permission' => 'fx.bank.manage']);
        Route::post('bank/entries/{fxBankEntry}/confirm', ['as' => 'bank.confirm', 'uses' => FxBankController::class . '@confirm', 'permission' => 'fx.bank.confirm'])->wherePrimaryKey('fxBankEntry');
        Route::post('bank/entries/{fxBankEntry}/reverse', ['as' => 'bank.reverse', 'uses' => FxBankController::class . '@reverse', 'permission' => 'fx.bank.manage'])->wherePrimaryKey('fxBankEntry');
        Route::get('recon', ['as' => 'recon.index', 'uses' => FxFinanceController::class . '@recon', 'permission' => 'fx.bank.index']);

        // 0.6.0 — paneli, festat, dokumentet, eksporti
        Route::get('dashboard', ['as' => 'dashboard', 'uses' => FxDashboardController::class . '@index', 'permission' => 'fx.dashboard.index']);

        Route::group(['prefix' => 'holidays', 'as' => 'holidays.'], function (): void {
            Route::get('', ['as' => 'index', 'uses' => FxHolidayController::class . '@index', 'permission' => 'fx.holidays.manage']);
            Route::post('', ['as' => 'store', 'uses' => FxHolidayController::class . '@store', 'permission' => 'fx.holidays.manage']);
            Route::post('delete/{fxHoliday}', ['as' => 'destroy', 'uses' => FxHolidayController::class . '@destroy', 'permission' => 'fx.holidays.manage'])->wherePrimaryKey('fxHoliday');
        });

        Route::get('customers/{fxCustomer}/documents', ['as' => 'customers.documents', 'uses' => FxDocumentController::class . '@index', 'permission' => 'fx.customers.index'])->wherePrimaryKey('fxCustomer');
        Route::post('customers/{fxCustomer}/documents', ['as' => 'customers.documents.store', 'uses' => FxDocumentController::class . '@store', 'permission' => false])->wherePrimaryKey('fxCustomer');
        Route::get('documents/{fxDocument}', ['as' => 'documents.show', 'uses' => FxDocumentController::class . '@show', 'permission' => 'fx.customers.index'])->wherePrimaryKey('fxDocument');

        Route::get('reports/export', ['as' => 'reports.export', 'uses' => FxReportController::class . '@export', 'permission' => 'fx.reports.index']);

        // Faza 2c — raportet
        Route::group(['prefix' => 'reports', 'as' => 'reports.'], function (): void {
            Route::get('', ['as' => 'index', 'uses' => FxReportController::class . '@index', 'permission' => 'fx.reports.index']);
            Route::post('generate', ['as' => 'generate', 'uses' => FxReportController::class . '@generate', 'permission' => 'fx.reports.generate']);
            Route::get('download/{fxReport}', ['as' => 'download', 'uses' => FxReportController::class . '@download', 'permission' => 'fx.reports.index'])->wherePrimaryKey('fxReport');
            Route::post('submit/{fxReport}', ['as' => 'submit', 'uses' => FxReportController::class . '@submit', 'permission' => 'fx.reports.submit'])->wherePrimaryKey('fxReport');
        });

        Route::group(['prefix' => 'offices', 'as' => 'offices.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxOfficeController::class . '@index', 'permission' => 'fx.offices.manage']);
            Route::get('create', ['as' => 'create', 'uses' => FxOfficeController::class . '@create', 'permission' => 'fx.offices.manage']);
            Route::post('create', ['as' => 'store', 'uses' => FxOfficeController::class . '@store', 'permission' => 'fx.offices.manage']);
            Route::get('edit/{fxOffice}', ['as' => 'edit', 'uses' => FxOfficeController::class . '@edit', 'permission' => 'fx.offices.manage'])->wherePrimaryKey('fxOffice');
            Route::post('edit/{fxOffice}', ['as' => 'update', 'uses' => FxOfficeController::class . '@update', 'permission' => 'fx.offices.manage'])->wherePrimaryKey('fxOffice');
        });

        Route::group(['prefix' => 'tills', 'as' => 'tills.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxOfficeController::class . '@tills', 'permission' => 'fx.offices.manage']);
            Route::get('create', ['as' => 'create', 'uses' => FxOfficeController::class . '@createTill', 'permission' => 'fx.offices.manage']);
            Route::post('create', ['as' => 'store', 'uses' => FxOfficeController::class . '@storeTill', 'permission' => 'fx.offices.manage']);
            Route::get('edit/{fxTill}', ['as' => 'edit', 'uses' => FxOfficeController::class . '@editTill', 'permission' => 'fx.offices.manage'])->wherePrimaryKey('fxTill');
            Route::post('edit/{fxTill}', ['as' => 'update', 'uses' => FxOfficeController::class . '@updateTill', 'permission' => 'fx.offices.manage'])->wherePrimaryKey('fxTill');
        });

        Route::group(['prefix' => 'currencies', 'as' => 'currencies.'], function (): void {
            Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => FxCurrencyController::class . '@index', 'permission' => 'fx.currencies.index']);
            Route::get('create', ['as' => 'create', 'uses' => FxCurrencyController::class . '@create', 'permission' => 'fx.currencies.create']);
            Route::post('create', ['as' => 'store', 'uses' => FxCurrencyController::class . '@store', 'permission' => 'fx.currencies.create']);
            Route::get('edit/{fxCurrency}', ['as' => 'edit', 'uses' => FxCurrencyController::class . '@edit', 'permission' => 'fx.currencies.edit'])->wherePrimaryKey('fxCurrency');
            Route::post('edit/{fxCurrency}', ['as' => 'update', 'uses' => FxCurrencyController::class . '@update', 'permission' => 'fx.currencies.edit'])->wherePrimaryKey('fxCurrency');
        });
    });
});
