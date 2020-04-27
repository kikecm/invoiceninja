<?php

namespace App\Http\Controllers\ClientPortal;

use App\Events\Quote\QuoteWasApproved;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ProcessQuotesInBulkRequest;
use App\Http\Requests\ClientPortal\ShowQuoteRequest;
use App\Models\Company;
use App\Models\Quote;
use App\Utils\TempFile;
use App\Utils\Traits\MakesHash;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

class QuoteController extends Controller
{
    use MakesHash;

    /**
     * Display a listing of the quotes.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index()
    {
        return $this->render('quotes.index');
    }


    /**
     * Display the specified resource.
     *
     * @param ShowQuoteRequest $request
     * @param Quote $quote
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(ShowQuoteRequest $request, Quote $quote)
    {
        return $this->render('quotes.show', [
            'quote' => $quote,
        ]);
    }

    public function bulk(ProcessQuotesInBulkRequest $request)
    {
        $transformed_ids = $this->transformKeys($request->quotes);

        if ($request->action == 'download') {
            return $this->downloadQuotePdf((array)$transformed_ids);
        }

        if ($request->action = 'approve') {
            return $this->approve((array)$transformed_ids, $request->has('process'));
        }

        return back();
    }

    protected function downloadQuotePdf(array $ids)
    {
        $quotes = Quote::whereIn('id', $ids)
            ->whereClientId(auth()->user()->client->id)
            ->get();

        if (!$quotes || $quotes->count() == 0) {
            return;
        }

        if ($quotes->count() == 1) {
            return response()->download(TempFile::path($invoices->first()->pdf_file_path()), basename($quotes->first()->pdf_file_path()));
        }

        # enable output of HTTP headers
        $options = new Archive();
        $options->setSendHttpHeaders(true);

        # create a new zipstream object
        $zip = new ZipStream(date('Y-m-d') . '_' . str_replace(' ', '_', trans('texts.invoices')) . ".zip", $options);

        foreach ($quotes as $quote) {
            $zip->addFileFromPath(basename($quote->pdf_file_path()), TempFile::path($quote->pdf_file_path()));
        }

        # finish the zip stream
        $zip->finish();
    }

    protected function approve(array $ids, $process = false)
    {
        $quotes = Quote::whereIn('id', $ids)
            ->whereClientId(auth()->user()->client->id)
            ->get();

        if (!$quotes || $quotes->count() == 0) {
            return redirect()->route('client.quotes.index');
        }

        if ($process) {
            foreach ($quotes as $quote) {
                $quote->service()->approve()->save();
                event(new QuoteWasApproved($quote));
            }

            return route('client.quotes.index')->withSuccess('Quote(s) approved successfully.');
        }

        return $this->render('quotes.approve', [
            'quotes' => $quotes,
        ]);
    }
}
