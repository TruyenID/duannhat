<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPaymentConfigurationController extends Controller
{
    public function __construct(
        private readonly PaymentPolicyEvaluationService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'data' => $this->service->shopConfiguration($shop),
        ]);
    }

    public function storeGateway(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $validated = $request->validate([
            'provider_id' => ['required', 'uuid'],
            'brand_owner_org_unit_id' => ['required', 'uuid'],
            'operator_org_unit_id' => ['required', 'uuid'],
            'ownership_revision' => ['required', 'string', 'max:32'],
            'merchant_account_id' => ['required', 'string', 'max:255'],
            'merchant_display_name' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'in:test,live,sandbox,local'],
            'charge_model' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'data' => $this->service->onboardFranchiseConnection($shop, $validated),
        ], 201);
    }

    public function updateGateway(Request $request, PaymentGatewayConnection $connection): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $validated = $request->validate([
            'merchant_display_name' => ['nullable', 'string', 'max:255'],
            'merchant_store_id' => ['nullable', 'string', 'max:255'],
            'merchant_terminal_id' => ['nullable', 'string', 'max:255'],
            'charge_model' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'data' => $this->service->updateFranchiseConnection($shop, $connection, $validated),
        ]);
    }

    public function updateOption(Request $request, PaymentGatewayOption $option): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $validated = $request->validate([
            'preference' => ['required', 'string', 'max:32'],
            'connection_id' => ['nullable', 'uuid'],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->service->updateShopOption($shop, $option, $validated),
        ]);
    }
}
