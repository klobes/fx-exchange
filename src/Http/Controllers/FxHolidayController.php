<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxHoliday;
use Botble\EmironFx\Services\FxCalendar;
use Illuminate\Http\Request;

/** Festat zyrtare: të fiksuarat + Pashkët llogariten; Bajramet dhe ditët e shpallura shtohen këtu. */
class FxHolidayController extends BaseController
{
    public function index(Request $request, FxCalendar $calendar)
    {
        abort_unless(fx_can('fx.holidays.manage'), 403);

        $year = (int) ($request->query('year') ?: now()->year);
        page_title()->setTitle(trans('plugins/emiron-fx::fx.holidays.title') . ' ' . $year);

        return view('plugins/emiron-fx::holidays.index', [
            'year' => $year,
            'days' => $calendar->holidays($year),
            'custom' => FxHoliday::query()->whereYear('holiday_date', $year)->get()->keyBy(fn ($h) => $h->holiday_date->toDateString()),
        ]);
    }

    public function store(Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.holidays.manage'), 403);

        $data = $request->validate([
            'holiday_date' => ['required', 'date_format:Y-m-d', 'unique:fx_holidays,holiday_date'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        FxHoliday::query()->create($data + ['created_by' => auth()->id()]);

        return $response->setNextUrl(route('fx.holidays.index', ['year' => substr($data['holiday_date'], 0, 4)]))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function destroy(FxHoliday $fxHoliday, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.holidays.manage'), 403);

        $year = $fxHoliday->holiday_date->year;
        $fxHoliday->delete();

        return $response->setNextUrl(route('fx.holidays.index', ['year' => $year]))
            ->setMessage(trans('core/base::notices.delete_success_message'));
    }
}
