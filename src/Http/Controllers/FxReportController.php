<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Http\Requests\FxReportRequest;
use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxReport;
use Botble\EmironFx\Services\FxExportService;
use Botble\EmironFx\Services\FxReportService;
use Botble\EmironFx\Support\FxCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Faza 2c — raportet e BSH-së dhe lista AIF: gjenerimi, shkarkimi, shënimi i dorëzimit. */
class FxReportController extends BaseController
{
    public function __construct(private readonly FxReportService $reports)
    {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.reports.title'), route('fx.reports.index'));
    }

    public function index(Request $request)
    {
        abort_unless(fx_can('fx.reports.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.reports.title'));

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now()->subDay();
        $preview = null;

        if (fx_can('fx.reports.generate')) {
            $boa = $this->reports->boaData($date);
            $preview = [
                'date' => $date,
                'boa' => $boa,
                'aif' => $this->reports->aifData($date),
                'deadline' => $this->reports->deadline($date),
            ];
        }

        return view('plugins/emiron-fx::reports.index', [
            'preview' => $preview,
            'reports' => FxReport::query()->with(['generator', 'submitter'])->orderByDesc('report_date')->orderByDesc('id')->limit(60)->get(),
            'canGenerate' => fx_can('fx.reports.generate'),
            'canSubmit' => fx_can('fx.reports.submit'),
        ]);
    }

    public function generate(FxReportRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.reports.generate'), 403);

        $report = $this->reports->generate((string) $request->input('type'), Carbon::parse($request->input('report_date')), (int) auth()->id());

        return $response->setNextUrl(route('fx.reports.index', ['date' => $report->report_date->toDateString()]))
            ->setMessage(trans('plugins/emiron-fx::fx.reports.generated', ['version' => $report->version]));
    }

    public function download(FxReport $fxReport)
    {
        abort_unless(fx_can('fx.reports.index'), 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($fxReport->file_path), 404);

        // Integriteti: skedari duhet të jetë ai që u gjenerua.
        abort_unless(hash_file('sha256', $disk->path($fxReport->file_path)) === $fxReport->checksum, 409, trans('plugins/emiron-fx::fx.reports.errors.checksum'));

        FxAccessLog::record('download', 'report', $fxReport->id);

        // Skedarët e enkriptuar (lista AIF) dekriptohen vetëm në momentin e shkarkimit.
        if (str_ends_with($fxReport->file_path, '.enc')) {
            $content = (string) FxCrypto::decrypt($disk->get($fxReport->file_path));

            return response()->streamDownload(fn () => print($content), basename($fxReport->file_path, '.enc'), [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->download($disk->path($fxReport->file_path), basename($fxReport->file_path));
    }

    /** Eksporti mujor për kontabilistin (CSV, s'ruhet në server). */
    public function export(Request $request, FxExportService $export)
    {
        abort_unless(fx_can('fx.reports.index'), 403);

        $month = Carbon::createFromFormat('Y-m', (string) ($request->query('month') ?: now()->subMonth()->format('Y-m')))->startOfMonth();
        FxAccessLog::record('export', 'month', null, $month->format('Y-m'));
        $content = $export->monthCsv($month);

        return response()->streamDownload(fn () => print($content), 'Kembimi_' . $month->format('Y-m') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function submit(FxReport $fxReport, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.reports.submit'), 403);

        $request->validate(['submission_ref' => ['nullable', 'string', 'max:120']]);
        $this->reports->markSubmitted($fxReport, (int) auth()->id(), $request->input('submission_ref'));

        return $response->setNextUrl(route('fx.reports.index', ['date' => $fxReport->report_date->toDateString()]))
            ->setMessage(trans('plugins/emiron-fx::fx.reports.submitted'));
    }
}
