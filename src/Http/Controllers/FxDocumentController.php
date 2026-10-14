<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Models\FxCustomerDocument;
use Botble\EmironFx\Services\FxDocumentService;
use Illuminate\Http\Request;

class FxDocumentController extends BaseController
{
    public function __construct(private readonly FxDocumentService $documents)
    {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.customers.title'), route('fx.customers.index'));
    }

    public function index(FxCustomer $fxCustomer)
    {
        abort_unless(fx_can('fx.customers.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.documents.title', ['name' => $fxCustomer->displayName()]));

        return view('plugins/emiron-fx::customers.documents', [
            'customer' => $fxCustomer,
            'documents' => FxCustomerDocument::query()->where('customer_id', $fxCustomer->id)->with('uploader')->latest('id')->get(),
            'canUpload' => fx_can('fx.customers.edit') || fx_can('fx.counter.use'),
        ]);
    }

    public function store(FxCustomer $fxCustomer, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.customers.edit') || fx_can('fx.counter.use'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:' . implode(',', FxDocumentService::MIMES)],
            'side' => ['required', 'in:front,back,other'],
        ]);

        $this->documents->store($fxCustomer, $request->file('file'), (string) $request->input('side'), (int) auth()->id());

        return $response->setNextUrl(route('fx.customers.documents', $fxCustomer->id))->setMessage(trans('plugins/emiron-fx::fx.documents.uploaded'));
    }

    public function show(FxCustomerDocument $fxDocument)
    {
        abort_unless(fx_can('fx.customers.index'), 403);

        $file = $this->documents->read($fxDocument);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="' . $file['name'] . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
