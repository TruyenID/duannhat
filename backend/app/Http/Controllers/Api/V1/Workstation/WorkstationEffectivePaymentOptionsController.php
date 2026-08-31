<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkstationEffectivePaymentOptionsController extends Controller
{
    /** Terminal device types the LAN mirror serves, and the channel each resolves with. */
    private const TERMINAL_CHANNELS = [
        'pos' => PaymentChannelEnum::Pos,
        'kiosk' => PaymentChannelEnum::Kiosk,
        // #1085 — self-checkout register: its own capability channel.
        'self_regi' => PaymentChannelEnum::SelfRegi,
    ];

    public function __construct(
        private readonly PaymentPolicyEvaluationService $service,
        private readonly PosEffectivePaymentOptionEnricher $posEnricher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $shop = $device->branch;
        abort_if($shop === null, 404, 'Device branch not found.');

        return response()->json([
            'data' => $this->service->effectiveOptions(
                $shop,
                (string) $device->id,
                PaymentChannelEnum::Workstation,
            ),
        ]);
    }

    /**
     * GET /workstation/effective-payment-options/matrix — #1080.
     *
     * The flat single-snapshot mirror dropped BOTH gates: device restrictions
     * (a kiosk's `disabled` preference vanished on LAN reads) and channel (the
     * set was resolved as channel=workstation but served to pos/kiosk). This
     * returns the full device×option matrix in ONE request so the workstation
     * can mirror per-terminal rows: `branch` holds the deviceless fallback per
     * channel, `devices` one entry per active pos/kiosk terminal, each
     * resolved with ITS device id and ITS channel. POS-channel sets ride the
     * same enricher as the direct-Cloud POS endpoint (legacy identity +
     * internal tenders) so LAN parity holds byte-for-byte.
     */
    public function matrix(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $shop = $device->branch;
        abort_if($shop === null, 404, 'Device branch not found.');

        $branch = [];
        foreach (self::TERMINAL_CHANNELS as $type => $channel) {
            $branch[$channel->value] = $this->evaluate($shop, null, $channel);
        }

        $terminals = Device::query()
            ->where('branch_id', $shop->id)
            ->where('status', 'active')
            ->whereIn('type', array_keys(self::TERMINAL_CHANNELS))
            ->orderBy('created_at')
            ->get();

        $devices = $terminals->map(function (Device $terminal) use ($shop): array {
            $type = $terminal->type instanceof \BackedEnum ? $terminal->type->value : (string) $terminal->type;
            $channel = self::TERMINAL_CHANNELS[$type];

            return [
                'device_id' => (string) $terminal->id,
                'device_type' => $type,
                'channel' => $channel->value,
                'evaluation' => $this->evaluate($shop, (string) $terminal->id, $channel),
            ];
        })->values();

        return response()->json([
            'data' => [
                'branch' => $branch,
                'devices' => $devices,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluate($shop, ?string $deviceId, PaymentChannelEnum $channel): array
    {
        $evaluation = $this->service->effectiveOptions($shop, $deviceId, $channel);

        // #1085 — self_regi rides the same internal-tender enricher as POS
        // (cash + card_terminal by catalog default); kiosk stays policy-only.
        return in_array($channel, [PaymentChannelEnum::Pos, PaymentChannelEnum::SelfRegi], true)
            ? $this->posEnricher->enrichEvaluation($evaluation, $shop, $channel)
            : $evaluation;
    }
}
