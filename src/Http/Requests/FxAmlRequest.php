<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;

class FxAmlRequest extends Request
{
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:fx_customers,id'],
            'transaction_id' => ['nullable', 'integer', 'exists:fx_transactions,id'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
