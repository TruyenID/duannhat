<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Notification;
use App\Services\Notification\BrandReverbAppService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/settings/reverb/*` (plan-012 T4.7).
 *
 * Rotation regenerates the brand's Reverb app key + secret. Connected
 * clients disconnect on next heartbeat; the response body carries the new
 * creds once so the admin UI can show them to the operator (and no state
 * is persisted client-side).
 */
class BrandReverbController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly BrandReverbAppService $reverb) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settings/reverb',
        summary: "Show the brand's Reverb connection config (app_key + host/port; secret omitted)",
        tags: ['HQ - Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Connection config'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        // app_secret is intentionally omitted — it is only ever revealed once on
        // rotation (TC-SET-REV04). app_key is the public client key.
        return response()->json([
            'data' => [
                'app_id' => $brand->reverb_app_id,
                'app_key' => $brand->reverb_app_key,
                'allowed_origins' => $this->reverb->allowedOrigins($brand),
                'provisioned_at' => $brand->reverb_provisioned_at?->toISOString(),
                ...$this->serverConfig(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/settings/reverb/test',
        summary: 'Test reachability of the Reverb server (TCP connect)',
        tags: ['HQ - Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Connection result ({ ok, host, port, message })'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function test(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        // The backend reaches Reverb via the broadcasting connection options —
        // that is the meaningful "is the Reverb server up" check.
        $host = (string) config('broadcasting.connections.reverb.options.host', '127.0.0.1');
        $port = (int) config('broadcasting.connections.reverb.options.port', 8080);

        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 2.0);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($socket !== false) {
            fclose($socket);

            return response()->json([
                'data' => [
                    'ok' => true,
                    'host' => $host,
                    'port' => $port,
                    'latency_ms' => $latencyMs,
                    'message' => 'Connected to the Reverb server.',
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'message' => $errstr !== '' ? $errstr : 'Could not reach the Reverb server.',
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/settings/reverb/rotate',
        summary: "Regenerate the brand's Reverb app key + secret",
        tags: ['HQ - Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'New credentials'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function rotate(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        $this->reverb->rotate($brand);
        $brand->refresh();

        return response()->json([
            'data' => [
                'app_id' => $brand->reverb_app_id,
                'app_key' => $brand->reverb_app_key,
                'app_secret' => $brand->reverb_app_secret,
                'rotated_at' => $brand->reverb_provisioned_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Client-facing host/port/scheme/cluster — infrastructure config shared by
     * every brand (same shape as /me/reverb-config). NOT per-brand editable.
     *
     * @return array{host: string, port: int, scheme: string, cluster: string}
     */
    private function serverConfig(): array
    {
        return [
            'host' => (string) (env('REVERB_CLIENT_HOST') ?: config('reverb.servers.reverb.hostname', config('broadcasting.connections.reverb.options.host', 'localhost'))),
            'port' => (int) (env('REVERB_CLIENT_PORT') ?: config('broadcasting.connections.reverb.options.port', 8080)),
            'scheme' => (string) (env('REVERB_CLIENT_SCHEME') ?: config('broadcasting.connections.reverb.options.scheme', 'http')),
            'cluster' => (string) config('broadcasting.connections.reverb.options.cluster', 'mt1'),
        ];
    }
}
