<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrandOrderPolicy;
use App\Models\ShopOrderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Returns details for the shop resolved by ResolveShopFromSlug middleware.
 *
 * Frontend uses this to validate {shopSlug} on every shop page load. Same
 * pattern as HQ\BrandInfoController — single-row lookup that piggybacks on
 * the existing middleware so the controller body is just "format and return".
 */
class ShopInfoController extends Controller
{
    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}',
        summary: 'Get the resolved shop',
        description: 'Returns details for the shop identified by {shopSlug}. The shop is loaded by ResolveShopFromSlug middleware, which returns 404 if the slug does not exist and 403 if the shop belongs to a different organization.',
        tags: ['Shop'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resolved shop',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'slug', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'code', type: 'string', nullable: true),
                            new OA\Property(property: 'is_headquarters', type: 'boolean'),
                            new OA\Property(property: 'console_brand_id', type: 'string', nullable: true),
                            new OA\Property(property: 'timezone', type: 'string', nullable: true),
                            new OA\Property(property: 'currency_code', type: 'string', nullable: true),
                        ]
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Shop belongs to a different organization'),
            new OA\Response(response: 404, description: 'Shop not found or inactive'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $shop->loadMissing('brand');

        // There is no orderSetting relation on Branch; the rest of the codebase
        // queries this table directly by branch_id, so this follows suit rather
        // than adding a relation to a generated model.
        $shopCurrency = ShopOrderSetting::query()
            ->where('branch_id', $shop->id)
            ->value('currency_code') ?? $shop->currency;

        // #890 — HQ brand-level switch: may this shop edit HQ-origin tables?
        // Missing brand/policy row ⇒ false (locked, the safe default).
        $allowShopEditHqTables = $shop->brand !== null
            && (bool) BrandOrderPolicy::query()
                ->where('brand_id', $shop->brand->id)
                ->value('allow_shop_edit_hq_tables');

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'slug' => $shop->slug,
                'name' => $shop->name,
                'code' => $shop->code,
                'is_headquarters' => (bool) $shop->is_headquarters,
                'console_brand_id' => $shop->console_brand_id,
                'brand_slug' => $shop->brand?->slug,
                'timezone' => $shop->timezone,
                // #1260 — the shop's money currency, so shop-scoped screens can
                // stop falling back to the platform's per-locale billing default
                // (which maps English to JPY). shop_order_settings is the
                // authority; branches.currency is the older column and is used
                // only when no settings row exists yet.
                'currency_code' => $shopCurrency,
                'address' => $shop->address,
                'phone' => $shop->phone,
                'logo' => $shop->logo,
                'img_branches' => $shop->img_branches,
                // #936 — per-breakpoint banners for the admin edit form.
                ...$shop->bannerUrls(),
                'seat_capacity' => $shop->seat_capacity,
                'business_hours' => $shop->business_hours,
                'weekly_hours' => $shop->weekly_hours,
                // #890 — drives the Edit action on HQ-origin table cards.
                'allow_shop_edit_hq_tables' => $allowShopEditHqTables,
            ],
        ]);
    }
}
