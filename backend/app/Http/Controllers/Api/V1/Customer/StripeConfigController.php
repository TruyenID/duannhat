<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class StripeConfigController extends Controller
{
    /**
     * Return the Stripe publishable key + currency from server config.
     *
     * Frontend fetches this on checkout mount instead of reading
     * `NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY` directly. Keeping the publishable
     * key beside the secret key on the server eliminates the class of bugs
     * where a stale env var on the client points at a different Stripe
     * account than the backend — that mismatch produces "No such
     * payment_intent" errors when confirming an intent created in the
     * other account.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'publishable_key' => (string) config('services.stripe.key'),
                // #815 — the global `currency` field was removed. The charge currency
                // is per-branch (shop_order_settings.currency_code), surfaced via the
                // branch payload / order.charge_currency, never a single global value.
            ],
        ]);
    }
}
