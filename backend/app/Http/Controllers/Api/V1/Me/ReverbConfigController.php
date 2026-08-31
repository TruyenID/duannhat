<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\User;
use App\Services\Notification\BrandReverbAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/me/reverb-config` (plan-012 T4.6).
 *
 * Returns the Reverb client creds the authenticated user's browser needs
 * to connect Laravel Echo to the correct per-brand Reverb app. Called by
 * `useNotificationRealtime()` on login and on brand-switch.
 *
 * Resolution order (first match wins):
 *   1. `?brand={slug}` — explicit brand context (HQ pages).
 *   2. `?shop={slug}` — derive the brand from the shop → branch → brand
 *      relation (shop pages that only know a shop slug).
 *   3. no query — pick the first brand in the user's organization.
 *      Used by /inbox and other cross-context surfaces.
 *
 * Response omits `app_secret`; only the public `app_key` is safe for the
 * browser.
 */
class ReverbConfigController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me/reverb-config',
        summary: "Return the Reverb public creds the user's browser should use",
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brand', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reverb client creds'),
            new OA\Response(response: 403, description: 'User does not belong to the requested brand'),
            new OA\Response(response: 404, description: 'Brand not found'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $brand = $this->resolveBrand($request, $user);
        abort_if($brand === null, 404, 'Brand not found.');

        abort_if(
            $user->console_organization_id !== $brand->console_organization_id,
            403,
            'Forbidden — user does not belong to the brand organization.'
        );

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
                // Client-facing host: what the browser uses to reach Reverb.
                // In Docker, the backend talks to Reverb on the internal hostname
                // (REVERB_HOST=reverb) while the browser must use the host-mapped
                // address. REVERB_CLIENT_HOST overrides for that case; fall back
                // to REVERB_HOST for single-host deployments.
                'host' => (string) (env('REVERB_CLIENT_HOST') ?: config('reverb.servers.reverb.hostname', config('broadcasting.connections.reverb.options.host', 'localhost'))),
                'port' => (int) (env('REVERB_CLIENT_PORT') ?: config('broadcasting.connections.reverb.options.port', 8080)),
                'scheme' => (string) (env('REVERB_CLIENT_SCHEME') ?: config('broadcasting.connections.reverb.options.scheme', 'http')),
            ],
        ]);
    }

    private function resolveBrand(Request $request, User $user): ?Brand
    {
        $brandSlug = trim((string) $request->query('brand', ''));
        if ($brandSlug !== '') {
            return Brand::query()
                ->where('slug', $brandSlug)
                ->where('console_organization_id', $user->console_organization_id)
                ->first()
                ?? Brand::query()->where('slug', $brandSlug)->first();
        }

        $shopSlug = trim((string) $request->query('shop', ''));
        if ($shopSlug !== '') {
            $branch = Branch::query()
                ->where('slug', $shopSlug)
                ->where('console_organization_id', $user->console_organization_id)
                ->first()
                ?? Branch::query()->where('slug', $shopSlug)->first();
            if ($branch === null) {
                return null;
            }

            return Brand::query()
                ->where('console_brand_id', $branch->console_brand_id)
                ->first();
        }

        // No context — fall back to the first brand in the user's organization.
        return Brand::query()
            ->where('console_organization_id', $user->console_organization_id)
            ->whereNotNull('reverb_app_id')
            ->orderBy('created_at')
            ->first();
    }
}
