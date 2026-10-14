<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Models\FxOffice;
use Botble\EmironFx\Models\FxSequence;
use Botble\EmironFx\Services\FxAlertService;
use Botble\EmironFx\Services\FxLedgerService;
use Illuminate\Console\Command;

/** php artisan fx:verify-ledger [--year=] — kontrollon numrat pa boshllëqe dhe zinxhirin hash. */
class VerifyLedgerCommand extends Command
{
    protected $signature = 'fx:verify-ledger {--year= : Viti (bosh = të gjithë)} {--notify : Dërgo email nëse gjendet problem}';

    protected $description = 'Emiron FX: verifikon integritetin e regjistrit të veprimeve (numërimi + zinxhiri hash)';

    public function handle(FxLedgerService $ledger, FxAlertService $alerts): int
    {
        $failed = false;
        $all = [];

        $sequences = FxSequence::query()
            ->when($this->option('year'), fn ($q, $y) => $q->where('year', (int) $y))
            ->orderBy('office_id')->orderBy('year')->get();

        if ($sequences->isEmpty()) {
            $this->info('S\'ka veprime të regjistruara.');

            return self::SUCCESS;
        }

        foreach ($sequences as $sequence) {
            $office = FxOffice::query()->find($sequence->office_id);
            $series = $sequence->series ?? 'FX';
            $errors = $series === 'P'
                ? $ledger->verifyPartner($sequence->office_id, $sequence->year)
                : $ledger->verify($sequence->office_id, $sequence->year);
            $label = sprintf('%s %s %d (%d veprime)', $office?->code ?? $sequence->office_id, $series, $sequence->year, $sequence->last_seq);

            if ($errors) {
                $failed = true;
                $this->error("✗ {$label}");

                foreach ($errors as $error) {
                    $this->line("   - {$error}");
                    $all[] = "{$label}: {$error}";
                }
            } else {
                $this->info("✓ {$label}: numërimi dhe zinxhiri hash në rregull.");
            }
        }

        if ($failed && $this->option('notify')) {
            $alerts->ledgerFailed($all);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
