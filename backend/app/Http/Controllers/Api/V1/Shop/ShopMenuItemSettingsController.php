<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Menu;
use App\Services\Product\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Per-menu settings override at the shop level.
 *
 * Endpoints:
 *   GET   /api/v1/shops/{shopSlug}/menus/{menu}/settings
 *   PATCH /api/v1/shops/{shopSlug}/menus/{menu}/settings
 *
 * Currently manages: cart_timeout_minutes (tầng ④ in the 4-level inheritance chain).
 * The response always embeds the full chain so the frontend can render it without
 * additional requests.
 */
class ShopMenuItemSettingsController extends Controller
{
    public function __construct(
        private readonly MenuService $service,
    ) {}

    // =========================================================================
    //  Show
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/settings',
        summary: 'Get per-menu timeout settings for a shop',
        description: 'Returns the full cart-timeout inheritance chain for this branch menu. All four tiers are included so the client can render them without additional requests.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Timeout chain',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'hq_brand_timeout_minutes', type: 'integer', nullable: true),
                            new OA\Property(property: 'hq_menu_timeout_minutes', type: 'integer', nullable: true),
                            new OA\Property(property: 'shop_default_timeout_minutes', type: 'integer', nullable: true),
                            new OA\Property(property: 'shop_menu_timeout_minutes', type: 'integer', nullable: true),
                            new OA\Property(property: 'effective_timeout_minutes', type: 'integer', nullable: true),
                        ]
                    ),
                ])
            ),
            new OA\Response(response: 404, description: 'Menu not found or does not belong to this shop'),
        ]
    )]
    public function show(Request $request, Menu $menu): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        if ((string) $menu->branch_id !== (string) $shop->id) {
            abort(404);
        }

        $masterMenu = $menu->master_menu_id
            ? Menu::find($menu->master_menu_id)
            : null;

        return response()->json([
            'data' => $this->buildChain($menu, $masterMenu),
        ]);
    }

    // =========================================================================
    //  Update
    // =========================================================================

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/settings',
        summary: 'Override cart timeout for a specific branch menu',
        description: 'Sets cart_timeout_minutes on the branch menu (tầng ④). Pass null to clear the override and fall back to the inherited value.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(
                    property: 'cart_timeout_minutes',
                    type: 'integer',
                    nullable: true,
                    minimum: 1,
                    description: 'Minutes after menu expiry before cart items are disabled. null = use inherited value. 0 is invalid.'
                ),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated — returns full chain'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 404, description: 'Menu not found or does not belong to this shop'),
        ]
    )]
    public function update(Request $request, Menu $menu): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        if ((string) $menu->branch_id !== (string) $shop->id) {
            abort(404);
        }

        $request->validate([
            'cart_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // service_type (#463 shop inherit): null = inherit the master (HQ)
            // menu's service type; a value = override for this branch menu only.
            'service_type' => ['sometimes', 'nullable', Rule::in(['Takeaway', 'DineIn', 'Both'])],
        ]);

        $updates = [];
        if ($request->has('cart_timeout_minutes')) {
            $updates['cart_timeout_minutes'] = $request->input('cart_timeout_minutes');
        }
        if ($request->has('service_type')) {
            $updates['service_type'] = $request->input('service_type');
        }
        if ($updates !== []) {
            $menu = $this->service->update($menu, $updates);
        }

        $masterMenu = $menu->master_menu_id
            ? Menu::find($menu->master_menu_id)
            : null;

        return response()->json([
            'data' => $this->buildChain($menu, $masterMenu),
        ]);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function buildChain(Menu $branchMenu, ?Menu $masterMenu): array
    {
        $masterMenu?->loadMissing('brand');
        $branchMenu->loadMissing('branch');

        $brand = $masterMenu?->brand;
        $branch = $branchMenu->branch;

        return [
            'hq_brand_timeout_minutes' => $brand?->cart_timeout_minutes,          // tầng ①
            'hq_menu_timeout_minutes' => $masterMenu?->cart_timeout_minutes,       // tầng ②
            'shop_default_timeout_minutes' => $branch?->cart_timeout_minutes,      // tầng ③
            'shop_menu_timeout_minutes' => $branchMenu->cart_timeout_minutes,      // tầng ④
            'effective_timeout_minutes' => $branchMenu->cart_timeout_minutes       // tầng ④
                ?? $branch?->cart_timeout_minutes                                  // tầng ③
                ?? $masterMenu?->cart_timeout_minutes                              // tầng ②
                ?? $brand?->cart_timeout_minutes,                                  // tầng ①

            // service_type inheritance (#463): branch menu's own value overrides;
            // null = inherit the master (HQ) menu's type; final fallback Both.
            'hq_service_type' => $masterMenu?->service_type,                       // HQ (master) value
            'shop_service_type' => $branchMenu->service_type,                     // branch own override (null = inherit)
            'effective_service_type' => $branchMenu->service_type                 // own …
                ?? $masterMenu?->service_type                                     // … or inherit HQ …
                ?? 'Both',                                                        // … or default Both
        ];
    }
}
