<?php

namespace Botble\EmironFx\Forms;

use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\EmironFx\Http\Requests\FxOfficeRequest;
use Botble\EmironFx\Models\FxOffice;

class FxOfficeForm extends FormAbstract
{
    public function setup(): void
    {
        $t = fn (string $k) => trans('plugins/emiron-fx::fx.offices.' . $k);

        $this
            ->model(FxOffice::class)
            ->setValidatorClass(FxOfficeRequest::class)
            ->add('name', TextField::class, TextFieldOption::make()->label($t('name'))->required())
            ->add('legal_name', TextField::class, TextFieldOption::make()->label($t('legal_name')))
            ->add('nipt', TextField::class, TextFieldOption::make()->label('NIPT'))
            ->add('license_no', TextField::class, TextFieldOption::make()->label($t('license_no'))->helperText($t('license_help')))
            ->add('address', TextField::class, TextFieldOption::make()->label($t('address')))
            ->add('phone', TextField::class, TextFieldOption::make()->label($t('phone')))
            ->add('code', TextField::class, TextFieldOption::make()->label($t('code'))->helperText($t('code_help'))->required())
            ->add('is_active', OnOffField::class, OnOffFieldOption::make()->label(trans('plugins/emiron-fx::fx.currencies.is_active'))->defaultValue(true));
    }
}
