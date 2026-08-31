<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Api\V1\Shop\Concerns\ShopBoundController;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/shops/{shopSlug}/notifications` — shop audit list
 * (plan-023 M6 T6.10).
 *
 * Scope: notifications whose subject lives in this shop OR whose
 * recipients include members of this shop. v1 takes the simpler
 * org-scoped query then filters by morph subject/branch hint —
 * good enough for the shop admin "did we send anything?" use
 * case without a heavyweight recipient-side join.
 *
 * The HQ NotificationAdminController is the deep-audit surface;
 * this controller is a shop-scoped slice.
 */
class ShopNotificationAdminController extends Controller
{
    use AuthorizesRequests, ShopBoundController;

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/notifications',
        summary: 'Shop-scoped notification audit list',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'priority', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'normal', 'high', 'urgent'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated audit list')]
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.viewAudit', $shop);

        $orgIds = $this->shopOrgIds($shop);

        $query = Notification::query()
            ->whereIn('organization_id', $orgIds)
            // v1 audit scope: notifications whose aggregation_key carries
            // the branch hint OR whose subject is a row tied to this shop
            // (CustomerOrder.branch_id, StockAlert.warehouse.branch_id).
            // Practical shortcut: filter by aggregation_key pattern, which
            // the M5 emitters populate consistently.
            ->where(function ($q) use ($shop) {
                $q->where('aggregation_key', 'like', "%:branch:{$shop->id}:%")
                    ->orWhere('aggregation_key', 'like', "%:branch:{$shop->id}");
            })
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));

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
}
