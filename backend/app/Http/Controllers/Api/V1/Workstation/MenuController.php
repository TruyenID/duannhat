<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuProductResource;
use App\Http\Resources\ShopMenuByDayResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\TaxType;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Customer\CustomerMenuService;
use App\Services\Product\MenuService;
use App\Services\Promotion\MenuPromotionService;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class MenuController extends Controller
{
    /**
     * #1661 — bản snapshot ĐẦU TIÊN định giá được topping ở cả tầng SHOP (#1192).
     *
     * Cố ý là một con số cố định chứ không phải
     * {@see CatalogRevisionService::SNAPSHOT_VERSION}: hai thứ này trả lời hai
     * câu hỏi khác nhau, và buộc chúng vào nhau làm mỗi lần đổi hình dạng
     * snapshot lại hạ cấp bằng chứng offline của toàn fleet.
     */
    private const TOPPING_PRICING_SNAPSHOT_VERSION = 3;

    public function __construct(
        private readonly CustomerMenuService $menuService,
        private readonly MenuService $shopMenuService,
        private readonly MenuPromotionService $promotionService,
        private readonly CatalogRevisionService $catalogRevisions,
    ) {}

    #[OA\Get(
        path: '/api/v1/workstation/menu',
        summary: 'Pull the active menu for the workstation device branch (kiosk shape)',
        description: 'Sync DOWN endpoint for kiosk/customer-web. Returns CustomerMenuService shape (categories→items, single default SKU per item).',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active menu (data=null when branch has no active menu).',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', nullable: true),
                    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not allowed'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        $menu = $this->menuService->getMenuForBranch((string) $device->branch_id);

        // plan-043 T3.2 — ship the brand's full tax-type catalog alongside the
        // menu so the workstation can resolve a line's tax when the item only
        // carries a (possibly null) tax_type_id. Resolved in the controller so
        // transformMenu() (shared with customer-web) stays lean. Brand is
        // resolved the same way BranchController does: Branch.console_brand_id
        // → Brand.id.
        if ($menu !== null) {
            $branch = Branch::find($device->branch_id);
            $brandId = $branch
                ? Brand::where('console_brand_id', $branch->console_brand_id)->value('id')
                : null;

            $menu['tax_types'] = $brandId
                ? TaxType::query()
                    ->where('brand_id', $brandId)
                    ->get()
                    ->map(fn (TaxType $t) => [
                        'id' => $t->id,
                        'code' => $t->code,
                        'name' => $t->name,
                        'rate' => (float) $t->rate,
                        'is_default' => (bool) $t->is_default,
                        'is_active' => (bool) $t->is_active,
                    ])
                    ->all()
                : [];

            // #1095 — the catalog version this menu snapshot represents. An
            // offline device stamps it onto every order it signs so the
            // verifier (#1096) can re-price against the catalog AS SOLD, not
            // today's. null = branch has no revision yet (fresh install); the
            // device then omits the claim and its orders take the legacy path.
            $revision = $this->catalogRevisions->currentFor((string) $device->branch_id);
            $menu['catalog_revision'] = $revision?->revision;
            // #1114 — whether that revision can price toppings (snapshot v2+
            // carries the topping pricing inputs). The device's signer gates
            // on this: a topping-bearing order is only signed when the claimed
            // revision can actually price its toppings; otherwise it takes the
            // legacy path instead of queueing evidence the verifier is
            // guaranteed to reject.
            //
            // #1192 raised the bar to v3, which is the first shape that can
            // express a SHOP topping override. A v2 revision prices tier-1
            // toppings LOW and its orders would be rejected as tampered, so
            // branches stay on the legacy path until their revision is rebuilt
            // (`php artisan catalog:rebuild-revisions`, or any catalog edit).
            //
            // #1661 — ngưỡng là HẰNG SỐ 3, không phải `SNAPSHOT_VERSION`. Câu hỏi
            // ở đây là *"bản này định giá topping đúng chưa"*, và câu trả lời là
            // "v3 trở lên" — nó không đổi khi snapshot mọc thêm trường cho việc
            // KHÁC. Buộc vào `SNAPSHOT_VERSION` thì mỗi lần đổi hình dạng vì bất
            // cứ lý do gì (v4 thêm các tầng thuế) lại hạ cấp MỌI chi nhánh xuống
            // đường legacy cho tới lượt rebuild 03:40 — mất bằng chứng offline
            // một ngày, đổi lấy không gì cả.
            $menu['catalog_revision_has_toppings'] = $revision !== null
                && (int) (((array) $revision->snapshot)['v'] ?? 1) >= self::TOPPING_PRICING_SNAPSHOT_VERSION;
        }

        return response()->json([
            'data' => $menu,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/workstation/menu/handy',
        summary: 'Pull full handy-shaped menus for the workstation branch',
        description: 'Sync DOWN endpoint for godx-handy. Returns the same ShopMenuByDayResource[] shape as Cloud /handy/menus/by-day/{day} so workstation can cache it locally and serve handy clients on the LAN without a round-trip to Cloud. Includes all active menus for today (day-of-week resolved from branch timezone). Each menu entry carries full ShopMenuProduct[] (multi-SKU, toppings, promotions, sections).',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of active menus for today with full product list.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
        ],
    )]
    public function handy(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = (string) $device->branch_id;

        $timezone = $this->resolveBranchTimezone($branchId);
        $dayOfWeek = Carbon::now($timezone)->dayOfWeek; // 0=Sun … 6=Sat

        $menus = $this->shopMenuService->listActiveBranchMenusForShopByDay($branchId, $dayOfWeek, [
            'per_page' => 100,
        ]);

        $result = [];
        foreach ($menus->items() as $menu) {
            $products = $this->shopMenuService->listBranchMenuProducts($menu, ['per_page' => 500]);

            // Batch-resolve active promotions for all products in this menu.
            $items = [];
            foreach ($products->items() as $mp) {
                if ($mp->product) {
                    $items[] = ['product_id' => $mp->product_id, 'category_ids' => []];
                }
            }
            $promotionMap = $this->promotionService->resolveActivePromotionsForMenu($branchId, $items);

            foreach ($products->items() as $mp) {
                $promo = $promotionMap[$mp->product_id] ?? null;
                if ($promo !== null) {
                    $defaultSku = $mp->menuProductSkus->first();
                    $basePrice = (float) ($defaultSku?->selling_price ?? 0);
                    $mp->setAttribute('active_promotion_overlay', [
                        'id' => $promo->id,
                        'discount_percent' => (float) $promo->discount_percent,
                        'discounted_price' => round($basePrice * (100 - (float) $promo->discount_percent) / 100, 2, PHP_ROUND_HALF_UP),
                        'ends_at' => null,
                        'stacking_mode' => $promo->stacking_mode instanceof \BackedEnum
                            ? $promo->stacking_mode->value
                            : (string) $promo->stacking_mode,
                    ]);
                }
            }

            $menuArray = (new ShopMenuByDayResource($menu))->toArray($request);
            $menuArray['menu_products'] = MenuProductResource::collection($products->items())->toArray($request);

            $result[] = $menuArray;
        }

        return response()->json([
            'data' => $result,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /** #1091 — see BusinessClock; this was a third private copy of the same walk. */
    private function resolveBranchTimezone(string $branchId): string
    {
        return BusinessClock::timezoneForBranch($branchId);
    }
}
