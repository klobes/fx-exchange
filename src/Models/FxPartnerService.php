<?php

namespace Botble\EmironFx\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shërbim i partnerit. direction: in (cash hyn në arkë) | out (cash del).
 * settlement:
 *   prefunded — kundrejt gjendjes së parapaguar te partneri: IN e zbret, OUT e shton
 *               (p.sh. pagesat utilitare, që mbushen me transfertë nga banka);
 *   account   — llogari rrjedhëse me partnerin: OUT → partneri na detyrohet (rimbursim),
 *               IN → ne i detyrohemi (pagesë), p.sh. tërheqjet MoneyGram.
 */
class FxPartnerService extends BaseModel
{
    protected $table = 'fx_partner_services';

    protected $fillable = ['partner_id', 'name', 'direction', 'settlement', 'currencies', 'is_active', 'sort_order'];

    protected $casts = ['currencies' => 'array', 'is_active' => 'bool'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(FxPartner::class, 'partner_id');
    }
}
