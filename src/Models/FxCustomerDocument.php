<?php

namespace Botble\EmironFx\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Botble\EmironFx\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kopja e dokumentit — e pandryshueshme (ruajtje 5-vjeçare sipas ligjit 9917). */
class FxCustomerDocument extends BaseModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $table = 'fx_customer_documents';

    protected $fillable = ['customer_id', 'side', 'file_path', 'mime', 'size', 'checksum', 'uploaded_by'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(FxCustomer::class, 'customer_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
