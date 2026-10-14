<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;

/** Numërimi i arkës në hapje ose mbyllje. */
class FxTillCountRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'counts' => collect((array) $this->input('counts', []))
                ->map(fn ($v) => is_string($v) ? str_replace([' ', ','], ['', '.'], trim($v)) : $v)
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['counts.*.regex' => trans('plugins/emiron-fx::fx.counter.errors.amount_format')];
    }
}
