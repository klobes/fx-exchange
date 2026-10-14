@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @foreach ($partners as $partner)
        <div class="card mb-3">
            <div class="card-header">
                <form method="POST" action="{{ route('fx.partners.store') }}" class="row g-2 align-items-center w-100">
                    @csrf
                    <input type="hidden" name="id" value="{{ $partner->id }}">
                    <div class="col-md-3"><input name="name" class="form-control" value="{{ $partner->name }}" required></div>
                    <div class="col-md-2"><input name="code" class="form-control text-uppercase" value="{{ $partner->code }}" required></div>
                    <div class="col-md-3"><div class="input-group"><span class="input-group-text small">{{ trans('plugins/emiron-fx::fx.partners.low_alert') }}</span><input name="prepaid_low_alert" class="form-control text-end" value="{{ (float) $partner->prepaid_low_alert }}"></div></div>
                    <div class="col-md-2"><label class="form-check mb-0"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($partner->is_active)> <span class="form-check-label">{{ trans('plugins/emiron-fx::fx.currencies.is_active') }}</span></label></div>
                    <div class="col-md-2 text-end"><button class="btn btn-sm btn-outline-primary">{{ trans('plugins/emiron-fx::fx.partners.save_partner') }}</button></div>
                </form>
            </div>
            <div class="card-body pb-0">
                <div class="small mb-2">
                    <strong>{{ trans('plugins/emiron-fx::fx.partners.balances') }}:</strong>
                    @forelse ($balances[$partner->id] as $code => $b)
                        <span class="me-3 font-monospace">{{ $code }} · {{ trans('plugins/emiron-fx::fx.partners.prepaid') }} {{ number_format((float) $b['prepaid'], 2, '.', ' ') }} · {{ trans('plugins/emiron-fx::fx.partners.receivable') }} {{ number_format((float) $b['receivable'], 2, '.', ' ') }}</span>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </div>
            </div>
            <div class="table-responsive">
                <table class="table card-table table-sm table-vcenter">
                    <thead><tr><th>{{ trans('plugins/emiron-fx::fx.partners.service') }}</th><th>IN/OUT</th><th>{{ trans('plugins/emiron-fx::fx.partners.settlement') }}</th><th>{{ trans('plugins/emiron-fx::fx.partners.currencies') }}</th><th>{{ trans('plugins/emiron-fx::fx.currencies.sort_order') }}</th><th></th><th></th></tr></thead>
                    @foreach ($partner->services->concat([null]) as $svc)
                        @php $fid = 'svc-' . $partner->id . '-' . ($svc?->id ?? 'new'); @endphp
                        <tr>
                            <td><input form="{{ $fid }}" name="name" class="form-control form-control-sm" value="{{ $svc?->name }}" placeholder="{{ trans('plugins/emiron-fx::fx.partners.new_service') }}" required></td>
                            <td><select form="{{ $fid }}" name="direction" class="form-select form-select-sm"><option value="in" @selected($svc?->direction === 'in')>IN</option><option value="out" @selected($svc?->direction === 'out')>OUT</option></select></td>
                            <td><select form="{{ $fid }}" name="settlement" class="form-select form-select-sm">
                                <option value="prefunded" @selected($svc?->settlement === 'prefunded')>{{ trans('plugins/emiron-fx::fx.partners.settlement_prefunded') }}</option>
                                <option value="account" @selected($svc?->settlement === 'account')>{{ trans('plugins/emiron-fx::fx.partners.settlement_account') }}</option>
                            </select></td>
                            <td><input form="{{ $fid }}" name="currencies" class="form-control form-control-sm text-uppercase" value="{{ $svc ? implode(',', (array) $svc->currencies) : 'ALL' }}"></td>
                            <td style="width:80px"><input form="{{ $fid }}" name="sort_order" class="form-control form-control-sm" value="{{ $svc?->sort_order ?? 99 }}"></td>
                            <td><input form="{{ $fid }}" type="hidden" name="is_active" value="0"><input form="{{ $fid }}" type="checkbox" class="form-check-input" name="is_active" value="1" @checked($svc?->is_active ?? true)></td>
                            <td class="text-end"><button form="{{ $fid }}" class="btn btn-sm btn-outline-primary">{{ $svc ? trans('plugins/emiron-fx::fx.partners.save_partner') : trans('plugins/emiron-fx::fx.partners.add') }}</button></td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @foreach ($partner->services->concat([null]) as $svc)
                <form id="svc-{{ $partner->id }}-{{ $svc?->id ?? 'new' }}" method="POST" action="{{ route('fx.partners.services.store') }}" hidden>
                    @csrf
                    <input type="hidden" name="id" value="{{ $svc?->id }}">
                    <input type="hidden" name="partner_id" value="{{ $partner->id }}">
                </form>
            @endforeach
            <div class="card-footer small text-muted">{{ trans('plugins/emiron-fx::fx.partners.settlement_help') }}</div>
        </div>
    @endforeach

    <form method="POST" action="{{ route('fx.partners.store') }}" class="card">
        @csrf
        <div class="card-body row g-2 align-items-center">
            <div class="col-md-4"><input name="name" class="form-control" placeholder="{{ trans('plugins/emiron-fx::fx.partners.new_partner') }}" required></div>
            <div class="col-md-2"><input name="code" class="form-control text-uppercase" placeholder="KOD" required></div>
            <div class="col-md-3"><input name="prepaid_low_alert" class="form-control" placeholder="{{ trans('plugins/emiron-fx::fx.partners.low_alert') }}"></div>
            <div class="col-md-3 text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.partners.add') }}</button></div>
        </div>
    </form>
@endsection
