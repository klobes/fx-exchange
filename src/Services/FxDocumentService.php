<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Models\FxCustomerDocument;
use Botble\EmironFx\Support\FxCrypto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Kopja e dokumentit të identifikimit: ruhet e ENKRIPTUAR me FX_DATA_KEY jashtë
 * dosjes publike (storage/app/fx-documents), s'fshihet (ruajtje 5-vjeçare),
 * çdo ngarkim dhe shikim lë gjurmë.
 */
class FxDocumentService
{
    public const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function store(FxCustomer $customer, UploadedFile $file, string $side, ?int $userId): FxCustomerDocument
    {
        $disk = Storage::disk('local');
        $path = sprintf('fx-documents/%d/%s.enc', $customer->id, Str::uuid());
        $disk->put($path, (string) FxCrypto::encrypt(base64_encode((string) file_get_contents($file->getRealPath()))));

        $doc = FxCustomerDocument::query()->create([
            'customer_id' => $customer->id,
            'side' => in_array($side, ['front', 'back', 'other'], true) ? $side : 'other',
            'file_path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $disk->path($path)),
            'uploaded_by' => $userId,
        ]);

        FxAccessLog::record('document_upload', 'customer', $customer->id, 'doc #' . $doc->id);

        return $doc;
    }

    /** @return array{content: string, mime: string, name: string} */
    public function read(FxCustomerDocument $doc): array
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($doc->file_path), 404);
        abort_unless(hash_file('sha256', $disk->path($doc->file_path)) === $doc->checksum, 409);

        FxAccessLog::record('document_view', 'customer', $doc->customer_id, 'doc #' . $doc->id);

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$doc->mime] ?? 'bin';

        return [
            'content' => (string) base64_decode((string) FxCrypto::decrypt($disk->get($doc->file_path)), true),
            'mime' => $doc->mime,
            'name' => sprintf('dokument_%d_%s_%d.%s', $doc->customer_id, $doc->side, $doc->id, $ext),
        ];
    }
}
