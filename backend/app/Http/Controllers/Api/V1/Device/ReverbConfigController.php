<?php

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Notification\BrandReverbAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/devices/reverb-config` (plan-027 T0.2).
 *
 * Returns the Reverb client creds the device-paired Echo client needs
 * to connect to the correct per-brand Reverb app. Mirrors /me/reverb-config
 * but uses device token auth instead of SSO.
 *
 * Device → branch → console_brand_id → brand → reverb_app_key
 */
class ReverbConfigController extends Controller
{
    #[OA\Get(
        path: '/api/v1/devices/reverb-config',
        summary: 'Return the Reverb public creds for device-paired Echo clients',
        tags: ['Devices'],
        security: [['deviceToken' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Reverb client creds'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 422, description: 'Device branch not linked to a Reverb-provisioned brand'),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $device->loadMissing('branch');
        $branch = $device->branch;

        if (! $branch) {
            abort(422, 'Device is not associated with an active branch.');
        }

        $brand = Brand::query()
            ->where('console_brand_id', $branch->console_brand_id)
            ->first();

        if (! $brand) {
            abort(422, 'Device branch not linked to a Reverb-provisioned brand.');
        }

        // #1208 — a brand with no provisioned app answers `app_key: null`, and
        // the client then cannot connect at all. `Brand::created` hook provisions on
        // create and a seeder backfills the older rows, so this is the gap
        // between those two: a row inserted without events, or an environment
        // where the backfill never ran. provision() is documented idempotent
        // and a no-op once set; healing here beats handing out a null.
        if (empty($brand->reverb_app_key)) {
            app(BrandReverbAppService::class)->provision($brand);
            $brand->refresh();
        }

        return response()->json([
            'data' => [
                'app_key' => $brand->reverb_app_key,
                'cluster' => (string) config('broadcasting.connections.reverb.options.cluster', 'mt1'),
                'host' => (string) (env('REVERB_CLIENT_HOST') ?: config('reverb.servers.reverb.hostname', config('broadcasting.connections.reverb.options.host', 'localhost'))),
                'port' => (int) (env('REVERB_CLIENT_PORT') ?: config('broadcasting.connections.reverb.options.port', 8080)),
                'scheme' => (string) (env('REVERB_CLIENT_SCHEME') ?: config('broadcasting.connections.reverb.options.scheme', 'http')),
            ],
        ]);
    }
}
