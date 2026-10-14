@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>{{ trans('plugins/emiron-fx::fx.official.auto_title') }}</strong>
                <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.official.auto_help', ['times' => implode(', ', array_merge((array) config('plugins.emiron-fx.general.boa_import_times', []), [config('plugins.emiron-fx.general.boa_import_final_time')]))]) }}</div>
                @if (! config('plugins.emiron-fx.general.alert_emails'))
                    <div class="text-warning small">{{ trans('plugins/emiron-fx::fx.official.no_alert_emails') }}</div>
                @else
                    <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.official.alert_emails', ['emails' => implode(', ', config('plugins.emiron-fx.general.alert_emails'))]) }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('fx.official-rates.import') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.official.import_now') }}</button>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('fx.official-rates.store') }}">
        @csrf

        <div class="card">
            <div class="card-header">
                <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.official.create') }}</h4>
            </div>

            <div class="card-body">
                <p class="text-muted">{{ trans('plugins/emiron-fx::fx.official.help') }}
                    <a href="https://www.bankofalbania.org/Tregjet/Kursi_zyrtar_i_kembimit/" target="_blank" rel="noopener">bankofalbania.org</a>
                </p>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mb-3" style="max-width:220px">
                    <label class="form-label required" for="fx-rate-date">{{ trans('plugins/emiron-fx::fx.official.date') }}</label>
                    <input type="date" id="fx-rate-date" name="rate_date" class="form-control" max="{{ now()->toDateString() }}"
                           value="{{ old('rate_date', now()->toDateString()) }}" required>
                </div>

                <div class="table-responsive">
                    <table class="table table-vcenter">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/emiron-fx::fx.currencies.code') }}</th>
                                <th class="text-end">{{ trans('plugins/emiron-fx::fx.official.latest') }}</th>
                                <th style="width:200px">{{ trans('plugins/emiron-fx::fx.official.rate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($currencies as $currency)
                                @php $last = $latest->get($currency->id); @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $currency->code }}</strong>
                                        @if ($currency->unit > 1)
                                            <small class="text-muted">× {{ $currency->unit }}</small>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($last)
                                            {{ fx_rate($last->rate) }} <span class="small text-muted">{{ $last->rate_date->format('d.m.Y') }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="form-control text-end" name="rates[{{ $currency->id }}]"
                                               value="{{ old('rates.' . $currency->id) }}" autocomplete="off">
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ trans('plugins/emiron-fx::fx.rates.no_active') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <label class="form-label" for="fx-official-note">{{ trans('plugins/emiron-fx::fx.rates.note') }}</label>
                <textarea id="fx-official-note" name="note" rows="2" maxlength="500" class="form-control"
                          placeholder="{{ trans('plugins/emiron-fx::fx.official.note_placeholder') }}">{{ old('note') }}</textarea>
            </div>

            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="text-muted small">{{ trans('plugins/emiron-fx::fx.official.correction_note') }}</span>
                <button type="submit" class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.official.save') }}</button>
            </div>
        </div>
    </form>
@endsection
