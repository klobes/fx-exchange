@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">{{ $year }}</h4>
                    <div class="btn-group">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('fx.holidays.index', ['year' => $year - 1]) }}">← {{ $year - 1 }}</a>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('fx.holidays.index', ['year' => $year + 1]) }}">{{ $year + 1 }} →</a>
                    </div>
                </div>
                <table class="table card-table table-sm table-vcenter">
                    @foreach ($days as $date => $name)
                        @php $d = \Illuminate\Support\Carbon::parse($date); @endphp
                        <tr>
                            <td class="font-monospace">{{ $d->format('d.m.Y') }}</td>
                            <td class="small text-muted">{{ $d->translatedFormat('l') }}</td>
                            <td>{{ $name }}</td>
                            <td class="text-end">
                                @if ($custom->has($date))
                                    <form method="POST" action="{{ route('fx.holidays.destroy', $custom[$date]->id) }}" onsubmit="return confirm('?')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger">{{ trans('plugins/emiron-fx::fx.holidays.remove') }}</button>
                                    </form>
                                @else
                                    <span class="badge text-bg-light">{{ trans('plugins/emiron-fx::fx.holidays.auto') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="col-lg-5">
            <form method="POST" action="{{ route('fx.holidays.store') }}" class="card">
                @csrf
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.holidays.add') }}</h4></div>
                <div class="card-body">
                    <input type="date" name="holiday_date" class="form-control mb-2" required>
                    <input type="text" name="name" class="form-control" maxlength="120" placeholder="{{ trans('plugins/emiron-fx::fx.holidays.name_placeholder') }}" required>
                    <div class="small text-muted mt-2">{{ trans('plugins/emiron-fx::fx.holidays.help') }}</div>
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.holidays.add') }}</button></div>
            </form>
        </div>
    </div>
@endsection
