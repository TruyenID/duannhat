<?php

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Device\DeviceService;
use App\Services\Device\DeviceSigningKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PairingController extends Controller
{
    public function __construct(
        private readonly DeviceService $service,
        private readonly DeviceSigningKeyService $signingKeys,
    ) {}

    /**
     * POST /api/v1/devices/pair — public pairing endpoint (no auth required).
     */
    public function pair(Request $request): JsonResponse
    {
        $request->validate([
            'pairing_code' => ['required', 'string', 'size:6'],
            // Optional CSV of device types the calling app accepts (e.g. "handy,pos").
            // Nullable so legacy apps that don't send it keep the old behaviour.
            'expected_type' => ['nullable', 'string'],
            'device_info' => ['nullable', 'array'],
            'device_info.user_agent' => ['nullable', 'string'],
            'device_info.ip_address' => ['nullable', 'string'],
            'device_info.app_version' => ['nullable', 'string'],
            // #1093 — optional Ed25519 public key (base64, 44 chars). The
            // device generated the pair itself; Cloud stores the public half
            // and returns the key id for OfflineOrderEvidence. Absent = old
            // build, no key issued (evidence replay stays opt-in).
            'public_key' => ['nullable', 'string', 'size:44'],
        ]);

        $expectedTypes = null;

        if ($request->filled('expected_type')) {
            $expectedTypes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $request->input('expected_type')),
            )));

            // Fail loudly on a malformed expected_type so a client typo surfaces
            // as a request error instead of silently rejecting a valid device.
            if ($expectedTypes === [] || array_diff($expectedTypes, DeviceTypeEnum::values()) !== []) {
                throw ValidationException::withMessages([
                    'expected_type' => [__('Invalid expected device type.')],
                ]);
            }
        }

        $device = $this->service->pair(
            $request->input('pairing_code'),
            $request->input('device_info', []),
            $expectedTypes,
        );

        $signingKey = $request->filled('public_key')
            ? $this->signingKeys->issue($device, (string) $request->input('public_key'))
            : null;

        return response()->json(array_filter([
            'device_token' => $device->device_token,
            'device' => new DeviceResource($device),
            'signing_key' => $signingKey === null ? null : [
                'key_id' => $signingKey->id,
                'expires_at' => $signingKey->expires_at?->toISOString(),
            ],
        ], static fn ($v) => $v !== null), 200);
    }
}
