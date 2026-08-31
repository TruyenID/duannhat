<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Api\V1\Shop\Concerns\ShopBoundController;
use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/shops/{shopSlug}/notifications/templates*` — shop-level
 * template overrides (plan-023 M6 T6.7).
 *
 * A shop admin can create a template row with the SAME `key` as a
 * brand or system template, pinned to their own `branch_id`. The
 * TemplateRenderer::resolveForKey precedence (shop → brand → system)
 * picks the shop row first when rendering for notifications dispatched
 * inside that shop, leaving brand/system text untouched for other
 * shops.
 *
 * Constraints:
 *   - `is_system` is ALWAYS false on shop rows — the shop admin
 *     surface cannot mint platform-protected templates.
 *   - `branch_id` is pinned from the route URL, never accepted from
 *     the body.
 *   - List + show + update + delete all scope to `branch_id =
 *     $shop->id` so cross-shop probes return 404.
 */
class ShopNotificationTemplateController extends Controller
{
    use AuthorizesRequests, ShopBoundController;

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/notifications/templates',
        summary: 'List shop-scoped template overrides',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated list')]
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageTemplates', $shop);

        $paginator = NotificationTemplate::query()
            ->where('branch_id', $shop->id)
            ->orderBy('key')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/notifications/templates',
        summary: 'Create a shop-scoped template override',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Created')]
    )]
    public function store(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageTemplates', $shop);

        $data = Validator::make($request->all(), [
            'key' => ['required', 'string', 'max:100'],
            'content' => ['required', 'array'],
            'default_channels' => ['nullable', 'array'],
            'params_schema' => ['nullable', 'array'],
        ])->validate();

        $brand = $this->requireBrandForShop($shop);
        $orgIds = $this->shopOrgIds($shop);

        $row = NotificationTemplate::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand?->id,
            'branch_id' => $shop->id,
            'key' => $data['key'],
            'content' => $data['content'],
            'default_channels' => $data['default_channels'] ?? null,
            'params_schema' => $data['params_schema'] ?? null,
            'is_system' => false,                              // shop-scope never seeds system rows
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $row], 201);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/notifications/templates/{id}',
        summary: 'Update a shop-scoped template override',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found in this shop'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageTemplates', $shop);

        $data = Validator::make($request->all(), [
            'content' => ['sometimes', 'array'],
            'default_channels' => ['sometimes', 'nullable', 'array'],
            'params_schema' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $row = NotificationTemplate::query()
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $row->fill($data)->save();

        return response()->json(['data' => $row->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/notifications/templates/{id}',
        summary: 'Delete a shop-scoped template override',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found in this shop'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageTemplates', $shop);

        $row = NotificationTemplate::query()
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $row->delete();

        return response()->noContent();
    }
}
