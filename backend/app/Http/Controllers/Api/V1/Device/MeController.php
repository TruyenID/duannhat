<?php

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/devices/me',
        summary: 'Neutral device-token verify',
        description: 'Accepts any active device type. Used by workstation CloudVerifier and any device-side client that needs to confirm its identity + branch.',
        tags: ['Devices'],
        security: [['deviceToken' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Device info (token stripped)'),
            new OA\Response(response: 401, description: 'Missing/invalid/inactive token'),
        ]
    )]
    public function __invoke(Request $request): DeviceResource
    {
        $device = $request->attributes->get('device');

        return new DeviceResource($device);
    }
}
