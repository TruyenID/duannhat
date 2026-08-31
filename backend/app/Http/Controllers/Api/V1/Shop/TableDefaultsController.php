<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Branch;
use App\Models\Table;
use App\Models\Zone;
use App\Services\Shop\BrandTableDefaultsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * "Nhận bàn mặc định từ HQ" (issue #890) — copies the brand's HQ zone/table
 * templates into real shop zones/tables. Idempotent by code: existing codes
 * are skipped, shop-created tables are never touched, and the shop keeps full
 * CRUD over everything afterwards.
 */
class TableDefaultsController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly BrandTableDefaultsService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/tables/defaults/preview',
        summary: 'Preview applying HQ default tables',
        description: 'Returns how many zones/tables would be created vs skipped if the brand defaults were applied now. Read-only.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview counts',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'zones', type: 'object', properties: [
                        new OA\Property(property: 'create', type: 'integer'),
                        new OA\Property(property: 'skip', type: 'integer'),
                    ]),
                    new OA\Property(property: 'tables', type: 'object', properties: [
                        new OA\Property(property: 'create', type: 'integer'),
                        new OA\Property(property: 'skip', type: 'integer'),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 409, description: 'Shop not linked to a brand'),
        ],
    )]
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Table::class);

        return response()->json(
            $this->service->preview($this->resolvedShop($request), $this->getOrganizationId())
        );
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables/defaults/apply',
        summary: 'Apply HQ default tables to this shop',
        description: 'Copies the brand\'s active zone/table templates into real shop zones/tables. Idempotent — codes that already exist in the shop are skipped, so re-applying never duplicates or overwrites. Requires Shop Manager or Org Admin role.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Apply result',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'zones_created', type: 'integer'),
                    new OA\Property(property: 'zones_skipped', type: 'integer'),
                    new OA\Property(property: 'tables_created', type: 'integer'),
                    new OA\Property(property: 'tables_skipped', type: 'integer'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — caller lacks manager role'),
            new OA\Response(response: 409, description: 'Shop not linked to a brand'),
        ],
    )]
    public function apply(Request $request): JsonResponse
    {
        // Applying creates real zones + tables — same gate as creating them
        // one by one.
        $this->authorize('create', Zone::class);
        $this->authorize('create', Table::class);

        return response()->json(
            $this->service->apply($this->resolvedShop($request), $this->getOrganizationId())
        );
    }

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }
}
