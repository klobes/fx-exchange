<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;

/** Numëruesi pa boshllëqe + hash-i i fundit i zinxhirit, për zyrë dhe vit. */
class FxSequence extends BaseModel
{
    protected $table = 'fx_sequences';

    protected $fillable = ['office_id', 'year', 'series', 'last_seq', 'last_hash'];
}
