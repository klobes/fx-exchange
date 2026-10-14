@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="card">
        <div class="card-body">
            <h4>{{ trans('plugins/emiron-fx::fx.counter.no_till_title') }}</h4>
            <p class="text-muted mb-0">{{ trans('plugins/emiron-fx::fx.counter.no_till_help') }}</p>
            @if (fx_can('fx.offices.manage'))
                <a href="{{ route('fx.tills.index') }}" class="btn btn-primary mt-3">{{ trans('plugins/emiron-fx::fx.tills.title') }}</a>
            @endif
        </div>
    </div>
@endsection
