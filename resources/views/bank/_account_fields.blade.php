@php $a = $account ?? null; @endphp
<div class="row g-2">
    <div class="col-12">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.bank_name') }} *</label>
        <input name="bank_name" class="form-control" maxlength="120" required value="{{ old('bank_name', $a?->bank_name) }}">
    </div>
    <div class="col-12">
        <label class="form-label small mb-1">IBAN</label>
        <input name="iban" class="form-control font-monospace" maxlength="40" placeholder="AL00 0000 0000 0000 0000 0000 0000" value="{{ old('iban', $a?->iban) }}">
    </div>
    <div class="col-12">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.account_number') }}</label>
        <input name="account_number" class="form-control font-monospace" maxlength="40" value="{{ old('account_number', $a?->account_number) }}">
    </div>
    <div class="col-6">
        <label class="form-label small mb-1">SWIFT / BIC</label>
        <input name="swift" class="form-control text-uppercase" maxlength="20" value="{{ old('swift', $a?->swift) }}">
    </div>
    <div class="col-6">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.branch') }}</label>
        <input name="branch" class="form-control" maxlength="120" value="{{ old('branch', $a?->branch) }}">
    </div>
    <div class="col-12">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.account_holder') }}</label>
        <input name="account_holder" class="form-control" maxlength="160" value="{{ old('account_holder', $a?->account_holder) }}">
    </div>
    <div class="col-6">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.contact_person') }}</label>
        <input name="contact_person" class="form-control" maxlength="120" value="{{ old('contact_person', $a?->contact_person) }}">
    </div>
    <div class="col-6">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.contact_phone') }}</label>
        <input name="contact_phone" class="form-control" maxlength="40" value="{{ old('contact_phone', $a?->contact_phone) }}">
    </div>
    <div class="col-12">
        <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.rates.note') }}</label>
        <textarea name="note" class="form-control" rows="2" maxlength="500">{{ old('note', $a?->note) }}</textarea>
    </div>
</div>
