<?php

namespace Botble\EmironFx\Services;

use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Events\UpdatedContentEvent;
use Botble\EmironFx\Models\FxCurrency;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FxCurrencyService
{
    public function create(array $data, Request $request): FxCurrency
    {
        $currency = FxCurrency::query()->create($this->only($data));

        event(new CreatedContentEvent('fx_currencies', $request, $currency));

        return $currency;
    }

    /**
     * Kodi dhe njësia s'ndryshojnë pasi monedha ka kurse: do të ndryshonin
     * kuptimin e çdo kursi të regjistruar më parë.
     */
    public function update(FxCurrency $currency, array $data, Request $request): FxCurrency
    {
        $data = $this->only($data);

        if ($currency->rates()->exists() || $currency->officialRates()->exists()) {
            $locked = array_filter([
                'code' => $data['code'] !== $currency->code,
                'unit' => (int) $data['unit'] !== (int) $currency->unit,
            ]);

            if ($locked) {
                throw ValidationException::withMessages(
                    array_map(fn () => trans('plugins/emiron-fx::fx.errors.locked_after_rates'), $locked)
                );
            }
        }

        $currency->fill($data)->save();

        event(new UpdatedContentEvent('fx_currencies', $request, $currency));

        return $currency;
    }

    private function only(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'decimals' => (int) $data['decimals'],
            'unit' => (int) $data['unit'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
