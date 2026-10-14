<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxMovementRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => str_replace([' ', ','], ['', '.'], trim((string) $this->input('amount')))]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
