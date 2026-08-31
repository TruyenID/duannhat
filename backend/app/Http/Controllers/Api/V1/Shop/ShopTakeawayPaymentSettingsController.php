<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Shop\ShopBranchSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopTakeawayPaymentSettingsController extends Controller
{
    /**
     * Get takeaway payment timeout settings for this shop.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $shop->loadMissing('brand');

        $brandTimeout = $shop->brand?->takeaway_payment_timeout_minutes;
        $shopTimeout = $shop->takeaway_payment_timeout_minutes;
        $effective = $shopTimeout ?? $brandTimeout;

        return response()->json([
            'data' => [
                'takeaway_payment_timeout_minutes' => $shopTimeout,
                'hq_brand_takeaway_payment_timeout_minutes' => $brandTimeout,
                'effective_takeaway_payment_timeout_minutes' => $effective,
            ],
        ]);
    }

    /**
     * Update takeaway payment timeout for this shop.
     */
    public function update(Request $request, ShopBranchSettingsService $service): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $shop = $service->updateTakeawayPaymentTimeout(
            $shop,
            $request->input('takeaway_payment_timeout_minutes'),
        );

        // Reload and return fresh data
        $shop->loadMissing('brand');
        $brandTimeout = $shop->brand?->takeaway_payment_timeout_minutes;
        $shopTimeout = $shop->takeaway_payment_timeout_minutes;
        $effective = $shopTimeout ?? $brandTimeout;

        return response()->json([
            'data' => [
                'takeaway_payment_timeout_minutes' => $shopTimeout,
                'hq_brand_takeaway_payment_timeout_minutes' => $brandTimeout,
                'effective_takeaway_payment_timeout_minutes' => $effective,
            ],
        ]);
    }
}
