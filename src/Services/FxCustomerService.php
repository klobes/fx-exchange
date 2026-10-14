<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCustomer;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Klientët e identifikuar (Faza 2b). Çdo kërkim, shikim dhe ndryshim
 * regjistrohet te fx_access_logs.
 */
class FxCustomerService
{
    public function findByDocument(string $docType, string $docNumber, bool $log = true): ?FxCustomer
    {
        $customer = FxCustomer::query()->where('doc_hash', FxCustomer::docHash($docType, $docNumber))->first();

        if ($log) {
            FxAccessLog::record('search', 'customer', $customer?->id, $customer ? null : 'pa rezultat');
        }

        return $customer;
    }

    /**
     * Gjen klientin nga dokumenti ose e krijon. Nëse ekziston, s'mbishkruan
     * të dhënat (ndryshimi bëhet te kartela e klientit, me gjurmë).
     */
    public function findOrCreate(array $data, ?int $userId): FxCustomer
    {
        $data = $this->validate($data);

        if ($existing = $this->findByDocument($data['doc_type'], $data['doc_number'], false)) {
            return $existing;
        }

        $customer = FxCustomer::query()->create($data + [
            'doc_hash' => FxCustomer::docHash($data['doc_type'], $data['doc_number']),
            'created_by' => $userId,
        ]);

        FxAccessLog::record('create', 'customer', $customer->id);

        return $customer;
    }

    public function update(FxCustomer $customer, array $data): FxCustomer
    {
        $data = $this->validate($data, $customer);
        $hash = FxCustomer::docHash($data['doc_type'], $data['doc_number']);

        if ($hash !== $customer->doc_hash && FxCustomer::query()->where('doc_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['doc_number' => trans('plugins/emiron-fx::fx.customers.errors.doc_exists')]);
        }

        $customer->fill($data + ['doc_hash' => $hash])->save();
        FxAccessLog::record('update', 'customer', $customer->id);

        return $customer;
    }

    public function setBlocked(FxCustomer $customer, bool $blocked, string $note): void
    {
        $customer->forceFill(['is_blocked' => $blocked])->save();
        FxAccessLog::record($blocked ? 'block' : 'unblock', 'customer', $customer->id, $note);
    }

    /** Validimi i biznesit (përveç FormRequest-it): fushat e detyrueshme sipas llojit. */
    private function validate(array $data, ?FxCustomer $current = null): array
    {
        $type = ($data['type'] ?? 'person') === 'company' ? 'company' : 'person';
        $categories = (array) config('plugins.emiron-fx.general.client_categories', []);
        $docTypes = (array) config('plugins.emiron-fx.general.id_document_types', []);

        $clean = [
            'type' => $type,
            'client_category' => in_array($data['client_category'] ?? null, $categories, true)
                ? $data['client_category']
                : ($type === 'company' ? 'Biznes' : 'Individë'),
            'first_name' => trim((string) ($data['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
            'legal_name' => trim((string) ($data['legal_name'] ?? '')) ?: null,
            'nipt' => strtoupper(trim((string) ($data['nipt'] ?? ''))) ?: null,
            'doc_type' => in_array($data['doc_type'] ?? null, $docTypes, true) ? $data['doc_type'] : null,
            'doc_number' => strtoupper(preg_replace('/\s+/', '', (string) ($data['doc_number'] ?? ''))),
            'doc_country' => strtoupper(trim((string) ($data['doc_country'] ?? ''))) ?: null,
            'doc_expiry' => ($data['doc_expiry'] ?? null) ?: null,
            'nationality' => strtoupper(trim((string) ($data['nationality'] ?? ''))) ?: null,
            'birth_date' => ($data['birth_date'] ?? null) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'is_pep' => (bool) ($data['is_pep'] ?? $current?->is_pep ?? false),
            'risk_level' => in_array($data['risk_level'] ?? null, ['low', 'medium', 'high'], true) ? $data['risk_level'] : ($current?->risk_level ?? 'low'),
        ];

        $errors = [];
        $prefix = Arr::get($data, '_prefix', '');

        if (! $clean['doc_type']) {
            $errors[$prefix . 'doc_type'][] = trans('plugins/emiron-fx::fx.customers.errors.doc_type');
        }

        if ($clean['doc_number'] === '' || strlen($clean['doc_number']) < 4) {
            $errors[$prefix . 'doc_number'][] = trans('plugins/emiron-fx::fx.customers.errors.doc_number');
        }

        if ($type === 'person' && (! $clean['first_name'] || ! $clean['last_name'])) {
            $errors[$prefix . 'first_name'][] = trans('plugins/emiron-fx::fx.customers.errors.name');
        }

        if ($type === 'company' && (! $clean['legal_name'] || ! $clean['nipt'])) {
            $errors[$prefix . 'legal_name'][] = trans('plugins/emiron-fx::fx.customers.errors.company');
        }

        if ($clean['doc_expiry'] && strtotime((string) $clean['doc_expiry']) < strtotime('today')) {
            $errors[$prefix . 'doc_expiry'][] = trans('plugins/emiron-fx::fx.customers.errors.expired');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
