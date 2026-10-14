<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;

class FxOfficialRateRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $rates = collect((array) $this->input('rates', []))
            ->map(fn ($v) => is_string($v) ? str_replace([' ', ','], ['', '.'], trim($v)) : $v)
            ->all();

        $this->merge(['rates' => $rates]);
    }

    public function rules(): array
    {
        return [
            'rate_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rates' => ['required', 'array'],
            'rates.*' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['rates.*.regex' => trans('plugins/emiron-fx::fx.errors.format')];
    }
}
