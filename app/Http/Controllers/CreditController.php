<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Resources\CreditTransactionResource;
use App\Services\Billing\CreditLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /**
     * The user's balance, the packs they can buy, and every change to their credits.
     */
    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,prompt')
            ->latest()
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('credits/Index', [
            'packsUsd' => config()->array('billing.credit_packs_usd'),
            'transactions' => CreditTransactionResource::collection($transactions),
        ]);
    }

    /**
     * Add a credit pack to the user's balance. No payment is taken: purchases
     * are simulated, as the brief asks.
     */
    public function store(PurchaseCreditsRequest $request, CreditLedger $creditLedger): RedirectResponse
    {
        $creditLedger->purchase($request->user(), $request->amountNanodollars());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Added $:amount in credits.', ['amount' => $request->integer('amount_usd')]),
        ]);

        return back();
    }
}
