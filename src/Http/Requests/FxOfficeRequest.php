<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxOfficeRequest extends Request
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'nipt' => ['nullable', 'string', 'max:20'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'code' => ['required', 'regex:/^[A-Z0-9]{1,10}$/', Rule::unique('fx_offices', 'code')->ignore($this->route('fxOffice')?->id)],
            'is_active' => ['boolean'],
        ];
    }
}
