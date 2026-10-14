<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class FxAmlUpdateRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge(['unblock' => $this->boolean('unblock')]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'reported', 'closed'])],
            'decision' => ['nullable', 'string', 'max:2000'],
            'fiu_reference' => ['nullable', 'string', 'max:120', 'required_if:status,reported'],
            'unblock' => ['boolean'],
        ];
    }
}
