@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h4 class="card-title">{{ $customer->displayName() }} · {{ trans('plugins/emiron-fx::fx.doc_types.' . $customer->doc_type) }} {{ $customer->maskedDoc() }}</h4>
                    <a href="{{ route('fx.customers.edit', $customer->id) }}" class="btn btn-sm btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.documents.back') }}</a>
                </div>
                <table class="table card-table table-sm table-vcenter">
                    @forelse ($documents as $doc)
                        <tr>
                            <td>{{ trans('plugins/emiron-fx::fx.documents.side_' . $doc->side) }}</td>
                            <td class="small text-muted">{{ BaseHelper::formatDateTime($doc->created_at) }} · {{ $doc->uploader?->name }} · {{ number_format($doc->size / 1024, 0) }} KB</td>
                            <td class="text-end"><a href="{{ route('fx.documents.show', $doc->id) }}" target="_blank" class="btn btn-sm btn-outline-primary">{{ trans('plugins/emiron-fx::fx.documents.view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">{{ trans('plugins/emiron-fx::fx.documents.none') }}</td></tr>
                    @endforelse
                </table>
                <div class="card-footer small text-muted">{{ trans('plugins/emiron-fx::fx.documents.note') }}</div>
            </div>
        </div>
        @if ($canUpload)
            <div class="col-lg-5">
                <form method="POST" action="{{ route('fx.customers.documents.store', $customer->id) }}" enctype="multipart/form-data" class="card">
                    @csrf
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.documents.upload') }}</h4></div>
                    <div class="card-body">
                        <select name="side" class="form-select mb-2">
                            @foreach (['front', 'back', 'other'] as $side)
                                <option value="{{ $side }}">{{ trans('plugins/emiron-fx::fx.documents.side_' . $side) }}</option>
                            @endforeach
                        </select>
                        <input type="file" name="file" class="form-control" accept="image/jpeg,image/png,application/pdf" capture="environment" required>
                        <div class="small text-muted mt-1">{{ trans('plugins/emiron-fx::fx.documents.help') }}</div>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.documents.upload') }}</button></div>
                </form>
            </div>
        @endif
    </div>
@endsection
