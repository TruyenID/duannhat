<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Payment\Policy\Admin\PayPayShopSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop-level PayPay off switch (plan-054 D9 / T5.6).
 *
 *   GET   /api/v1/shops/{shopSlug}/settings/paypay
 *   PATCH /api/v1/shops/{shopSlug}/settings/paypay   { "preference": "inherit"|"disabled" }
 *
 * A dedicated resource rather than the generic
 * `PATCH /shops/{shopSlug}/payment-options/{option}` because that endpoint
 * takes the capability's UUID, and the only way a screen could learn it is
 * `GET /shops/{shopSlug}/payment-configuration` — which lists options assembled
 * from `payment_gateway_connection_options`. For PayPay QR that row is created
 * by `PayPayCustomerWebBootstrap` at the FIRST checkout, so on a shop that has
 * never taken a PayPay payment the generic screen shows nothing to switch off.
 * The off switch has to exist before the first sale to be worth anything.
 */
class ShopPayPaySettingsController extends Controller
{
    public function __construct(
        private readonly PayPayShopSwitchService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json(['data' => $this->service->stateFor($shop)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $validated = $request->validate([
            'preference' => ['required', 'string', 'in:'.implode(',', PayPayShopSwitchService::SHOP_PREFERENCES)],
        ]);

        return response()->json([
            'data' => $this->service->setPreference($shop, $validated['preference']),
        ]);
    }
}
