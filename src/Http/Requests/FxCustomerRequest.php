<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxCustomerRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge(['is_pep' => $this->boolean('is_pep')]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['person', 'company'])],
            'client_category' => ['required', Rule::in((array) config('plugins.emiron-fx.general.client_categories', []))],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'nipt' => ['nullable', 'string', 'max:20'],
            'doc_type' => ['required', Rule::in((array) config('plugins.emiron-fx.general.id_document_types', []))],
            'doc_number' => ['required', 'string', 'min:4', 'max:40'],
            'doc_country' => ['nullable', 'string', 'max:3'],
            'doc_expiry' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:3'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_pep' => ['boolean'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high'])],
        ];
    }
}
