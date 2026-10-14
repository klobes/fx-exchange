<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxCurrencyRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $currency = $this->route('fxCurrency');

        return [
            'code' => ['required', 'regex:/^[A-Z]{3}$/', 'not_in:ALL', Rule::unique('fx_currencies', 'code')->ignore($currency?->id)],
            'name' => ['required', 'string', 'max:80'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'unit' => ['required', 'integer', 'in:1,10,100,1000'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'is_active' => ['boolean'],
        ];
    }
}
