<?php

namespace Botble\EmironFx\Forms;

use Botble\ACL\Models\User;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\EmironFx\Http\Requests\FxTillRequest;
use Botble\EmironFx\Models\FxOffice;
use Botble\EmironFx\Models\FxTill;

class FxTillForm extends FormAbstract
{
    public function setup(): void
    {
        $t = fn (string $k) => trans('plugins/emiron-fx::fx.tills.' . $k);

        $users = ['' => $t('no_user')] + User::query()->get()
            ->mapWithKeys(fn (User $u) => [$u->id => trim(($u->name ?? '') ?: ($u->username ?? $u->email))])
            ->all();

        $this
            ->model(FxTill::class)
            ->setValidatorClass(FxTillRequest::class)
            ->add('office_id', SelectField::class, SelectFieldOption::make()->label($t('office'))
                ->choices(FxOffice::query()->where('is_active', true)->pluck('name', 'id')->all())->required())
            ->add('name', TextField::class, TextFieldOption::make()->label($t('name'))->required())
            ->add('user_id', SelectField::class, SelectFieldOption::make()->label($t('cashier'))
                ->helperText($t('cashier_help'))->choices($users))
            ->add('is_active', OnOffField::class, OnOffFieldOption::make()->label(trans('plugins/emiron-fx::fx.currencies.is_active'))->defaultValue(true));
    }
}
