<?php

namespace Botble\EmironFx\Forms;

use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\EmironFx\Http\Requests\FxCurrencyRequest;
use Botble\EmironFx\Models\FxCurrency;

class FxCurrencyForm extends FormAbstract
{
    public function setup(): void
    {
        $locked = $this->getModel() instanceof FxCurrency
            && $this->getModel()->exists
            && ($this->getModel()->rates()->exists() || $this->getModel()->officialRates()->exists());

        $this
            ->model(FxCurrency::class)
            ->setValidatorClass(FxCurrencyRequest::class)
            ->add('code', TextField::class, TextFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.code'))
                ->helperText(trans($locked ? 'plugins/emiron-fx::fx.currencies.code_locked' : 'plugins/emiron-fx::fx.currencies.code_help'))
                ->required()
                ->maxLength(3)
                ->attributes($locked ? ['readonly' => 'readonly'] : []))
            ->add('name', TextField::class, TextFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.name'))
                ->required()
                ->maxLength(80))
            ->add('symbol', TextField::class, TextFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.symbol'))
                ->maxLength(10))
            ->add('unit', SelectField::class, SelectFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.unit'))
                ->helperText(trans('plugins/emiron-fx::fx.currencies.unit_help'))
                ->choices([1 => '1', 10 => '10', 100 => '100', 1000 => '1000']))
            ->add('decimals', NumberField::class, NumberFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.decimals'))
                ->defaultValue(2)
                ->attributes(['min' => 0, 'max' => 4]))
            ->add('sort_order', NumberField::class, NumberFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.sort_order'))
                ->defaultValue(0))
            ->add('is_active', OnOffField::class, OnOffFieldOption::make()
                ->label(trans('plugins/emiron-fx::fx.currencies.is_active'))
                ->defaultValue(false));
        // Kodi/njësia pas kurseve: FxCurrencyService::update() i refuzon ndryshimet.
    }
}
