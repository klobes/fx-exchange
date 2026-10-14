<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxReportRequest extends Request
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['boa_daily', 'aif_ctr'])],
            'report_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
