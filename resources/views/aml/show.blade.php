@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h4 class="card-title">#{{ $case->id }} · {{ trans('plugins/emiron-fx::fx.aml.status_' . $case->status) }}</h4></div>
                <table class="table card-table table-sm mb-0">
                    <tr><td>{{ trans('plugins/emiron-fx::fx.aml.customer') }}</td><td>
                        @if ($case->customer)
                            <a href="{{ route('fx.customers.edit', $case->customer->id) }}">{{ $case->customer->displayName() }}</a>
                            @if ($case->customer->is_blocked)<span class="badge text-bg-danger">{{ trans('plugins/emiron-fx::fx.customers.blocked') }}</span>@endif
                        @else — @endif
                    </td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.aml.transaction') }}</td><td>
                        @if ($case->transaction)<a href="{{ route('fx.counter.receipt', $case->transaction->id) }}" target="_blank">{{ $case->transaction->number }}</a>@else — @endif
                    </td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.aml.opened_by') }}</td><td>{{ $case->opener?->name }} · {{ BaseHelper::formatDateTime($case->created_at) }}</td></tr>
                    @if ($case->reported_at)
                        <tr><td>{{ trans('plugins/emiron-fx::fx.aml.reported_at') }}</td><td>{{ BaseHelper::formatDateTime($case->reported_at) }} · {{ $case->fiu_reference }}</td></tr>
                    @endif
                </table>
                <div class="card-body">
                    <h5>{{ trans('plugins/emiron-fx::fx.aml.description') }}</h5>
                    <p style="white-space: pre-wrap">{{ $case->description }}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <form method="POST" action="{{ route('fx.aml.update', $case->id) }}" class="card">
                @csrf
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.aml.decision') }}</h4></div>
                <div class="card-body">
                    <label class="form-label">{{ trans('plugins/emiron-fx::fx.aml.status') }}</label>
                    <select name="status" class="form-select mb-3">
                        @foreach (['open', 'reported', 'closed'] as $s)
                            <option value="{{ $s }}" @selected($case->status === $s)>{{ trans('plugins/emiron-fx::fx.aml.status_' . $s) }}</option>
                        @endforeach
                    </select>
                    <label class="form-label">{{ trans('plugins/emiron-fx::fx.aml.fiu_reference') }}</label>
                    <input type="text" name="fiu_reference" class="form-control mb-3" maxlength="120" value="{{ old('fiu_reference', $case->fiu_reference) }}">
                    <label class="form-label">{{ trans('plugins/emiron-fx::fx.aml.decision_text') }}</label>
                    <textarea name="decision" rows="4" class="form-control mb-3" maxlength="2000">{{ old('decision', $case->decision) }}</textarea>
                    @if ($case->customer?->is_blocked)
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input" name="unblock" value="1">
                            <span class="form-check-label">{{ trans('plugins/emiron-fx::fx.aml.unblock') }}</span>
                        </label>
                    @endif
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.aml.save') }}</button></div>
            </form>
        </div>
    </div>
@endsection
