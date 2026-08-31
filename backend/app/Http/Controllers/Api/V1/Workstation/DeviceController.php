<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Services\Device\DeviceService;
use App\Services\Device\DeviceSigningKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DeviceController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/self-revoke',
        summary: 'Workstation revokes its own pairing (logout)',
        description: 'Called by workstation when operator clicks Unpair. Marks device.status=revoked and clears device_token + paired_at. Idempotent — re-call on already-revoked token returns 401 from device.auth middleware (good — token already invalidated). After this call, the token no longer authenticates against any /workstation/* or /kiosk/* endpoint.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Device revoked. Body: status=revoked + device_id.'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not allowed'),
        ],
    )]
    public function selfRevoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        // One transaction, owned by the service (#1669): revoking the signing
        // keys and clearing the pairing are one act, not two.
        app(DeviceService::class)->selfRevoke($device);

        return response()->json([
            'status' => 'revoked',
            'device_id' => $device->id,
        ], 200);
    }

    #[OA\Post(
        path: '/api/v1/workstation/keys/rotate',
        summary: 'Register a fresh Ed25519 signing key (rotation)',
        description: 'The device generates a new keypair locally and registers the public half. The PREVIOUS key stays valid until its own expires_at (grace window) so offline orders signed before the rotation still verify on sync UP (#1093 BR-DSK01). Returns the new key id + expiry.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'New key registered. Body: key_id + expires_at.'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 422, description: 'Malformed public key'),
        ],
    )]
    public function rotateSigningKey(Request $request): JsonResponse
    {
        $request->validate([
            'public_key' => ['required', 'string', 'size:44'],
        ]);

        $device = $request->attributes->get('device');
        $key = app(DeviceSigningKeyService::class)
            ->issue($device, (string) $request->input('public_key'));

        return response()->json([
            'key_id' => $key->id,
            'expires_at' => $key->expires_at?->toISOString(),
        ], 200);
    }
}
