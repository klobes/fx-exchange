<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxTransactionRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'foreign_amount' => str_replace([' ', ','], ['', '.'], trim((string) $this->input('foreign_amount'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['buy', 'sell'])],
            'currency_id' => ['required', 'integer'],
            'foreign_amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            // 0.8.0: kryqëzim valutë↔valutë — monedha "në këmbim të" (bosh = Lek) dhe kusuri (Lek ose monedha e dhënë)
            'pay_currency_id' => ['nullable', 'integer'],
            'change_in' => ['nullable', Rule::in(['lek', 'given'])],
            // 0.8.4: shitje nën kosto — kalon vetëm me lejen fx.counter.below_cost + arsye
            'allow_below_cost' => ['nullable', 'boolean'],
            'below_cost_reason' => ['nullable', 'string', 'max:200'],
            'client_category' => ['required', Rule::in((array) config('plugins.emiron-fx.general.client_categories', []))],
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.type' => ['nullable', Rule::in(['person', 'company'])],
            'customer.client_category' => ['nullable', 'string', 'max:60'],
            'customer.first_name' => ['nullable', 'string', 'max:80'],
            'customer.last_name' => ['nullable', 'string', 'max:80'],
            'customer.legal_name' => ['nullable', 'string', 'max:160'],
            'customer.nipt' => ['nullable', 'string', 'max:20'],
            'customer.doc_type' => ['nullable', 'string', 'max:20'],
            'customer.doc_number' => ['nullable', 'string', 'max:40'],
            'customer.doc_country' => ['nullable', 'string', 'max:3'],
            'customer.doc_expiry' => ['nullable', 'date_format:Y-m-d'],
            'customer.nationality' => ['nullable', 'string', 'max:3'],
            'customer.birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return ['foreign_amount.regex' => trans('plugins/emiron-fx::fx.counter.errors.amount_format')];
    }
}
