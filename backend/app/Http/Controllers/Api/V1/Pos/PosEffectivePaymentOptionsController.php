<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosEffectivePaymentOptionsController extends Controller
{
    public function __construct(
        private readonly PaymentPolicyEvaluationService $service,
        private readonly PosEffectivePaymentOptionEnricher $enricher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        /** @var Device|null $device */
        $device = $request->attributes->get('device');
        $deviceId = $device instanceof Device ? (string) $device->id : null;

        $data = $this->enricher->enrichEvaluation(
            $this->service->effectiveOptions(
                $shop,
                $deviceId,
                PaymentChannelEnum::Pos,
            ),
            $shop,
        );

        return response()->json(['data' => $data]);
    }
}
