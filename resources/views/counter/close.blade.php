@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <form method="POST" action="{{ route('fx.counter.close') }}">
        @csrf
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.close_title', ['till' => $till->name, 'date' => $session->business_date->format('d.m.Y')]) }}</h4>
            </div>
            <div class="card-body">
                @include('plugins/emiron-fx::counter._errors')
                <p class="text-muted">{{ trans('plugins/emiron-fx::fx.counter.close_help') }}</p>
                @include('plugins/emiron-fx::counter._counts', ['showExpected' => true])
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a href="{{ route('fx.counter.index') }}" class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.counter.back') }}</a>
                <button type="submit" class="btn btn-danger">{{ trans('plugins/emiron-fx::fx.counter.close_button') }}</button>
            </div>
        </div>
    </form>
@endsection
