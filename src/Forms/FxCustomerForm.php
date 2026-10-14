<?php

namespace Botble\EmironFx\Forms;

use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\EmironFx\Http\Requests\FxCustomerRequest;
use Botble\EmironFx\Models\FxCustomer;

class FxCustomerForm extends FormAbstract
{
    public function setup(): void
    {
        $t = fn (string $k) => trans('plugins/emiron-fx::fx.customers.' . $k);
        $categories = (array) config('plugins.emiron-fx.general.client_categories', []);
        $docTypes = collect((array) config('plugins.emiron-fx.general.id_document_types', []))
            ->mapWithKeys(fn ($d) => [$d => trans('plugins/emiron-fx::fx.doc_types.' . $d)])->all();

        $this
            ->model(FxCustomer::class)
            ->setValidatorClass(FxCustomerRequest::class)
            ->add('type', SelectField::class, SelectFieldOption::make()->label($t('type'))
                ->choices(['person' => $t('type_person'), 'company' => $t('type_company')]))
            ->add('client_category', SelectField::class, SelectFieldOption::make()->label($t('category'))
                ->choices(array_combine($categories, $categories)))
            ->add('first_name', TextField::class, TextFieldOption::make()->label($t('first_name')))
            ->add('last_name', TextField::class, TextFieldOption::make()->label($t('last_name')))
            ->add('legal_name', TextField::class, TextFieldOption::make()->label($t('legal_name')))
            ->add('nipt', TextField::class, TextFieldOption::make()->label('NIPT'))
            ->add('doc_type', SelectField::class, SelectFieldOption::make()->label($t('doc_type'))->choices($docTypes))
            ->add('doc_number', TextField::class, TextFieldOption::make()->label($t('doc_number'))->required())
            ->add('doc_country', TextField::class, TextFieldOption::make()->label($t('doc_country'))->placeholder('ALB'))
            ->add('doc_expiry', TextField::class, TextFieldOption::make()->label($t('doc_expiry'))->attributes(['type' => 'date']))
            ->add('nationality', TextField::class, TextFieldOption::make()->label($t('nationality'))->placeholder('ALB'))
            ->add('birth_date', TextField::class, TextFieldOption::make()->label($t('birth_date'))->attributes(['type' => 'date']))
            ->add('address', TextField::class, TextFieldOption::make()->label($t('address')))
            ->add('phone', TextField::class, TextFieldOption::make()->label($t('phone')))
            ->add('risk_level', SelectField::class, SelectFieldOption::make()->label($t('risk'))
                ->choices(['low' => $t('risk_low'), 'medium' => $t('risk_medium'), 'high' => $t('risk_high')]))
            ->add('is_pep', OnOffField::class, OnOffFieldOption::make()->label($t('is_pep'))->helperText($t('pep_help')));

        if ($this->getModel() instanceof FxCustomer && $this->getModel()->exists) {
            $this->add('documents_link', HtmlField::class, HtmlFieldOption::make()->content(
                '<a class="btn btn-outline-primary" href="' . e(route('fx.customers.documents', $this->getModel()->id)) . '">'
                . e(trans('plugins/emiron-fx::fx.documents.manage')) . '</a>'
            ));
        }
    }
}
