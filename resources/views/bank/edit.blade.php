@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('fx.bank.accounts.update', $account->id) }}" class="card" style="max-width: 760px">
        @csrf
        <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.bank.edit_account') }} — {{ $account->label() }}</h4></div>
        <div class="card-body">
            @include('plugins/emiron-fx::bank._account_fields', ['account' => $account])

            <hr>
            <div class="row g-2">
                <div class="col-4">
                    <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.currencies.code') }}</label>
                    <input name="currency_code" class="form-control text-uppercase" maxlength="3" value="{{ old('currency_code', $account->currency_code) }}" @disabled($hasEntries) @required(! $hasEntries)>
                </div>
                <div class="col-4">
                    <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.opening_balance') }}</label>
                    <input name="opening_balance" class="form-control text-end" value="{{ old('opening_balance', (float) $account->opening_balance) }}" @disabled($hasEntries) @required(! $hasEntries)>
                </div>
                <div class="col-4">
                    <label class="form-label small mb-1">{{ trans('plugins/emiron-fx::fx.bank.opening_date') }}</label>
                    <input type="date" name="opening_date" class="form-control" value="{{ old('opening_date', $account->opening_date?->toDateString()) }}" @disabled($hasEntries) @required(! $hasEntries)>
                </div>
            </div>
            @if ($hasEntries)
                <div class="form-text">{{ trans('plugins/emiron-fx::fx.bank.locked_help') }}</div>
            @endif

            <div class="form-check mt-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" class="form-check-input" name="is_active" id="fx-acc-active" value="1" @checked(old('is_active', $account->is_active))>
                <label class="form-check-label" for="fx-acc-active">{{ trans('plugins/emiron-fx::fx.bank.active') }}</label>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="{{ route('fx.bank.index') }}" class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.board.back') }}</a>
            <button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.bank.save_account') }}</button>
        </div>
    </form>
@endsection
