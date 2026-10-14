@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <form method="POST" action="{{ route('fx.counter.open') }}">
        @csrf
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.open_title', ['till' => $till->name, 'office' => $till->office?->name]) }}</h4>
            </div>
            <div class="card-body">
                @include('plugins/emiron-fx::counter._errors')
                <p class="text-muted">{{ trans('plugins/emiron-fx::fx.counter.open_help') }}</p>
                @include('plugins/emiron-fx::counter._counts', ['showExpected' => true])
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.counter.open_button') }}</button>
            </div>
        </div>
    </form>
@endsection
