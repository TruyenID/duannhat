<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuPromotionResource;
use App\Models\MenuPromotion;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Plan-019 — HQ cross-shop promotion list (read-only, S11).
 *
 * Filters by branch_id when supplied (HQ wants to drill into one
 * shop's promotions); otherwise returns every promotion belonging to
 * the brand. Sorting on `discount_percent` / `valid_from` /
 * `valid_until` / `created_at` / `updated_at`.
 */
class MenuPromotionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MenuPromotionService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/promotions',
        summary: 'List promotions across every branch in the brand (KPI report)',
        description: 'Read-only cross-shop list (S11). Always opts into `with_report=true` server-side so each row carries `report.items_with_promotion_count` + `report.total_discount_applied` for the HQ KPI tiles. Branch info is flattened to `branch_slug` + `branch_name` to spare the FE a nested traversal.',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Fuzzy match on promotion name (whereTranslationLike).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'currently_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Prefix `-` for desc. Allowed: created_at, updated_at, discount_percent, valid_from, valid_until.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated MenuPromotionResource with flat branch_slug + branch_name + report aggregates.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'discount_percent', type: 'number'),
                    new OA\Property(property: 'applies_to', type: 'string', enum: ['all_items', 'categories', 'products', 'mixed']),
                    new OA\Property(property: 'stacking_mode', type: 'string', enum: ['exclusive_with_coupons', 'stackable_with_coupons']),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                    new OA\Property(property: 'currently_active', type: 'boolean', description: 'Derived: is_active AND now in [valid_from, valid_until].'),
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'branch_slug', type: 'string', description: 'Plan-019 — flat shop slug for the HQ list table row.'),
                    new OA\Property(property: 'branch_name', type: 'string'),
                    new OA\Property(property: 'items_with_promotion_count', type: 'integer', description: 'Count of customer_order_items that applied this promotion.'),
                    new OA\Property(property: 'total_discount_amount', type: 'number', description: 'Σ (original_unit_price − unit_price) × quantity across all items that applied this promo.'),
                    new OA\Property(property: 'report', type: 'object', properties: [
                        new OA\Property(property: 'items_with_promotion_count', type: 'integer'),
                        new OA\Property(property: 'total_discount_applied', type: 'number'),
                    ]),
                ])),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'links', type: 'object'),
            ])),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyHq', MenuPromotion::class);

        $filters = $request->only(['search', 'branch_id', 'is_active', 'currently_active', 'sort', 'per_page', 'with_trashed']);
        $filters['brand_id'] = $request->attributes->get('brand_id');
        $filters['organization_id'] = $request->attributes->get('organization_id');
        // HQ list always opts into the report aggregates — KPI tiles +
        // per-row "Total discount" column depend on them.
        $filters['with_report'] = true;

        return MenuPromotionResource::collection($this->service->list($filters));
    }
}
