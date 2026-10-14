<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;

class FxRatePublishRequest extends Request
{
    protected function prepareForValidation(): void
    {
        // Pranon "98,15" dhe "98.15".
        $rows = collect((array) $this->input('rows', []))
            ->map(fn ($row) => collect((array) $row)
                ->map(fn ($v) => is_string($v) ? str_replace([' ', ','], ['', '.'], trim($v)) : $v)
                ->all())
            ->all();

        $this->merge(['rows' => $rows, 'override' => $this->boolean('override')]);
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array'],
            'rows.*.buy' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'rows.*.sell' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'override' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.*.buy.regex' => trans('plugins/emiron-fx::fx.errors.format'),
            'rows.*.sell.regex' => trans('plugins/emiron-fx::fx.errors.format'),
        ];
    }
}
