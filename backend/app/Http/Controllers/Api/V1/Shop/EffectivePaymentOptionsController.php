<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\PaymentGatewayOption;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EffectivePaymentOptionsController extends Controller
{
    public function __construct(
        private readonly PaymentPolicyEvaluationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'data' => $this->service->effectiveOptions(
                $shop,
                channel: PaymentChannelEnum::tryFrom((string) $request->query('channel', 'pos'))
                    ?? PaymentChannelEnum::Pos,
            ),
        ]);
    }

    public function deviceIndex(Request $request, Device $device): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        abort_unless((string) $device->branch_id === (string) $shop->id, 404);

        return response()->json([
            'data' => $this->service->effectiveOptions($shop, (string) $device->id),
        ]);
    }

    public function deviceUpdate(Request $request, Device $device): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        abort_unless((string) $device->branch_id === (string) $shop->id, 404);

        $validated = $request->validate([
            'option_id' => ['required', 'uuid'],
            'preference' => ['required', 'string', 'max:32'],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $option = PaymentGatewayOption::query()->whereKey($validated['option_id'])->firstOrFail();

        return response()->json([
            'data' => $this->service->updateDeviceOption(
                $shop,
                (string) $device->id,
                $option,
                $validated,
            ),
        ]);
    }
}
