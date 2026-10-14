<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxSequence;
use Botble\EmironFx\Models\FxTransaction;

/**
 * Numërimi pa boshllëqe dhe zinxhiri hash i veprimeve, për zyrë dhe vit.
 * Thirret VETËM brenda DB::transaction (kyç rreshtin e numëruesit).
 */
class FxLedgerService
{
    /** @return array{seq: int, number: string, prev_hash: ?string, sequence: FxSequence} */
    public function reserve(int $officeId, string $officeCode, int $year, string $series = 'FX'): array
    {
        FxSequence::query()->firstOrCreate(['office_id' => $officeId, 'year' => $year, 'series' => $series], ['last_seq' => 0]);

        /** @var FxSequence $sequence */
        $sequence = FxSequence::query()
            ->where('office_id', $officeId)
            ->where('year', $year)
            ->where('series', $series)
            ->lockForUpdate()
            ->firstOrFail();

        $seq = (int) $sequence->last_seq + 1;

        return [
            'seq' => $seq,
            // Këmbimi: Z1-2026-000001 · Partnerët: Z1-P-2026-000001
            'number' => $series === 'FX'
                ? sprintf('%s-%d-%06d', $officeCode, $year, $seq)
                : sprintf('%s-%s-%d-%06d', $officeCode, $series, $year, $seq),
            'prev_hash' => $sequence->last_hash,
            'sequence' => $sequence,
        ];
    }

    public function commit(FxSequence $sequence, int $seq, string $hash): void
    {
        $sequence->forceFill(['last_seq' => $seq, 'last_hash' => $hash])->save();
    }

    /**
     * Hash-i i një veprimi: SHA-256 mbi hash-in e mëparshëm + fushat thelbësore
     * në rend të fiksuar. Çdo ndryshim në DB (shumë, kurs, datë, rend) prish zinxhirin.
     */
    public function hash(array $data, ?string $prevHash): string
    {
        $fields = [
            'office_id', 'year', 'seq', 'number', 'till_id', 'till_session_id', 'user_id', 'business_date',
            'occurred_at', 'direction', 'currency_code', 'unit', 'foreign_amount', 'rate', 'lek_amount',
            'official_rate', 'eur_equivalent', 'client_category', 'payment_method', 'voids_transaction_id',
        ];

        $canonical = [];

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            $canonical[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
        }

        // 0.5.0: klienti hyn në hash kur ekziston (veprimet e 0.4.0 pa klient mbeten të verifikueshme).
        if (! empty($data['customer_id'])) {
            $canonical['customer_id'] = (string) $data['customer_id'];
        }

        // 0.8.0: fushat e kryqëzimit hyjnë në hash vetëm kur ekzistojnë (veprimet e vjetra mbeten të verifikueshme).
        foreach (['leg', 'pair_transaction_id', 'change_amount', 'change_currency'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $canonical[$field] = (string) $data[$field];
            }
        }

        return hash('sha256', ($prevHash ?? '') . '|' . json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /** Hash-i i veprimit me partner (seria "P"), i njëjti parim si te këmbimi. */
    public function hashPartner(array $data, ?string $prevHash): string
    {
        $fields = [
            'office_id', 'year', 'seq', 'number', 'till_id', 'till_session_id', 'user_id', 'business_date', 'occurred_at',
            'partner_id', 'service_id', 'direction', 'settlement', 'currency_code', 'amount', 'reference', 'voids_operation_id',
        ];
        $canonical = [];

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            $canonical[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
        }

        return hash('sha256', ($prevHash ?? '') . '|P|' . json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int,string> */
    public function verifyPartner(int $officeId, int $year): array
    {
        $errors = [];
        $expected = 1;
        $prev = null;

        \Botble\EmironFx\Models\FxPartnerOperation::query()->where('office_id', $officeId)->where('year', $year)->orderBy('seq')
            ->chunk(1000, function ($rows) use (&$errors, &$expected, &$prev): void {
                foreach ($rows as $op) {
                    if ((int) $op->seq !== $expected) {
                        $errors[] = "Boshllëk/renditje: pritej {$expected}, u gjet {$op->seq} ({$op->number})";
                        $expected = (int) $op->seq;
                    }

                    if ($op->prev_hash !== $prev) {
                        $errors[] = "Zinxhiri i këputur te {$op->number}";
                    }

                    $data = $op->getAttributes();
                    $data['business_date'] = $op->business_date?->format('Y-m-d');
                    $data['occurred_at'] = $op->occurred_at?->format('Y-m-d H:i:s');

                    if ($this->hashPartner($data, $op->prev_hash) !== $op->hash) {
                        $errors[] = "Hash i pavlefshëm te {$op->number}";
                    }

                    $prev = $op->hash;
                    $expected++;
                }
            });

        return $errors;
    }

    /**
     * Verifikon zinxhirin e një zyre/viti: numrat pa boshllëqe dhe hash-et.
     *
     * @return array<int,string> gabimet (bosh = në rregull)
     */
    public function verify(int $officeId, int $year): array
    {
        $errors = [];
        $expectedSeq = 1;
        $prev = null;

        FxTransaction::query()
            ->where('office_id', $officeId)
            ->where('year', $year)
            ->orderBy('seq')
            ->chunk(1000, function ($rows) use (&$errors, &$expectedSeq, &$prev): void {
                foreach ($rows as $tx) {
                    if ((int) $tx->seq !== $expectedSeq) {
                        $errors[] = "Boshllëk/renditje: pritej {$expectedSeq}, u gjet {$tx->seq} ({$tx->number})";
                        $expectedSeq = (int) $tx->seq;
                    }

                    if ($tx->prev_hash !== $prev) {
                        $errors[] = "Zinxhiri i këputur te {$tx->number}: prev_hash s'përputhet";
                    }

                    $data = $tx->getAttributes();
                    $data['business_date'] = $tx->business_date?->format('Y-m-d');
                    $data['occurred_at'] = $tx->occurred_at?->format('Y-m-d H:i:s');

                    if ($this->hash($data, $tx->prev_hash) !== $tx->hash) {
                        $errors[] = "Hash i pavlefshëm te {$tx->number}: të dhënat janë ndryshuar pas regjistrimit";
                    }

                    $prev = $tx->hash;
                    $expectedSeq++;
                }
            });

        return $errors;
    }
}
