<?php

namespace Botble\EmironFx\Http\Requests;

use Botble\Support\Http\Requests\Request;

class FxVoidRequest extends Request
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
