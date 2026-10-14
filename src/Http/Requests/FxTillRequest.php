<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxTillRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'user_id' => $this->input('user_id') ?: null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:fx_offices,id'],
            'name' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', Rule::unique('fx_tills', 'user_id')->ignore($this->route('fxTill')?->id)],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['user_id.unique' => trans('plugins/emiron-fx::fx.tills.user_taken')];
    }
}
