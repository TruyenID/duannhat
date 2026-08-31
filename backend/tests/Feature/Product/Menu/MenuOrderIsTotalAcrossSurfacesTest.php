<?php

declare(strict_types=1);

/**
 * #3170 — ba màn hình đọc CÙNG một thực đơn phải ra CÙNG một dãy id.
 *
 * `ORDER BY display_order` một mình không phải thứ tự toàn phần: PR #3160 đo
 * được 104/127 dòng của một thực đơn thật cùng nằm ở `display_order = 0`. Trên
 * một cụm hoà nhau như thế, DB được tự do trả các dòng theo thứ tự bất kỳ, và
 * thứ tự đó đổi khi query plan đổi. POS đọc qua `MenuService`, menu khách đọc
 * qua `CustomerMenuService`, máy trạm đọc qua `MenuCatalogReplicaBuilder` —
 * ba câu query khác nhau, nên ba thứ tự ĐƯỢC PHÉP khác nhau. Nhân viên gọi đó
 * là "menu không đồng bộ".
 *
 * Rào này cố ý dựng dữ liệu sao cho **thứ tự chèn NGƯỢC với thứ tự id**: mọi
 * dòng cùng `display_order = 0`, còn id thì giảm dần theo lượt INSERT. Một
 * đường đọc thiếu tie-break sẽ rơi về thứ tự vật lý (= thứ tự chèn) và lệch
 * ngay với hai đường còn lại — nếu chèn theo đúng thứ tự id thì cả ba cùng
 * đúng vì lý do sai, và rào không bao giờ kêu.
 *
 * Vì thế test khẳng định HAI điều, không phải một:
 *   1. ba dãy id bằng nhau (điều issue yêu cầu), và
 *   2. cả ba bằng dãy id TĂNG DẦN (hợp đồng thật: display_order rồi id).
 * Vế 2 là vế kêu được cả khi cả ba cùng lệch một kiểu.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Services\Customer\CustomerMenuService;
use App\Services\Product\MenuService;
use App\Services\Workstation\MenuCatalogReplicaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Số dòng cùng chia nhau MỘT display_order — đủ nhiều để "tình cờ trùng" không còn là lời giải thích. */
const TIED_ROWS = 24;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        // 'Active' là trạng thái CustomerMenuService lọc theo, và cũng nằm trong
        // tập trạng thái MenuCatalogReplicaBuilder chấp nhận — nên cả ba đường
        // đọc cùng thấy thực đơn này.
        'status' => 'Active',
        'valid_from' => null,
        'valid_to' => null,
    ]);

    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Món chính',
    ]);
    DB::table('menu_menu_sections')->insert([
        'menu_id' => $this->menu->id,
        'menu_section_id' => $section->id,
        'display_order' => 1,
    ]);

    // Id dựng tay theo thứ tự TĂNG rõ ràng (id thật là UUIDv7 — cũng tăng theo
    // thời gian tạo). Chèn theo chiều NGƯỢC lại để thứ tự vật lý của bảng khác
    // hẳn thứ tự id; đó là toàn bộ sức mạnh đo đạc của rào này.
    $ids = [];
    for ($i = 0; $i < TIED_ROWS; $i++) {
        $ids[] = sprintf('01936f00-%04d-7000-8000-%012d', $i, $i);
    }
    $this->expectedIds = $ids;

    foreach (array_reverse($ids) as $position => $menuProductId) {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'is_hidden' => false,
            'name' => 'Món '.$position,
        ]);

        $sku = ProductSku::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'selling_price' => 1000,
        ]);

        DB::table('menu_products')->insert([
            'id' => $menuProductId,
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
            // MỌI dòng cùng một display_order — đúng hình dạng của thực đơn thật
            // đã đo (104/127 dòng ở 0).
            'display_order' => 0,
        ]);

        DB::table('menu_product_skus')->insert([
            'id' => (string) Str::uuid(),
            'menu_product_id' => $menuProductId,
            'product_sku_id' => $sku->id,
            'selling_price' => 1000,
            'is_active' => true,
        ]);
    }
});

/** POS / HQ: đường đọc chi tiết thực đơn. */
function posMenuProductIds(string $menuId): array
{
    return app(MenuService::class)->findById($menuId)->menuProducts->pluck('id')->all();
}

/** Menu khách (QR / kiosk): item['id'] CHÍNH LÀ menu_products.id. */
function guestMenuProductIds(string $branchId): array
{
    $payload = app(CustomerMenuService::class)->getMenuForBranch($branchId);

    return collect($payload['categories'] ?? [])
        ->flatMap(fn (array $category) => array_column($category['items'], 'id'))
        ->all();
}

/** Máy trạm: feed replica mà workstation kéo về SQLite của nó. */
function replicaMenuProductIds(string $branchId, string $menuId): array
{
    return collect(app(MenuCatalogReplicaBuilder::class)->buildForBranch($branchId)['menu_products'])
        ->where('menu_id', $menuId)
        ->pluck('id')
        ->all();
}

it('trả cùng một dãy id cho POS, menu khách và replica máy trạm khi mọi dòng hoà display_order', function () {
    $pos = posMenuProductIds($this->menu->id);
    $guest = guestMenuProductIds($this->branch->id);
    $replica = replicaMenuProductIds($this->branch->id, $this->menu->id);

    // Chống pass rỗng: một đường đọc trả 0 dòng vì lý do không liên quan thì ba
    // mảng rỗng vẫn "bằng nhau" và rào im lặng vĩnh viễn.
    expect($pos)->toHaveCount(TIED_ROWS, 'MenuService không trả đủ dòng — test đang so hai mảng rỗng.');
    expect($guest)->toHaveCount(TIED_ROWS, 'CustomerMenuService không trả đủ dòng — test đang so hai mảng rỗng.');
    expect($replica)->toHaveCount(TIED_ROWS, 'MenuCatalogReplicaBuilder không trả đủ dòng — test đang so hai mảng rỗng.');

    // Vế 2 trước: nó chỉ đích danh đường nào trượt hợp đồng, thay vì chỉ nói
    // "ba đường lệch nhau".
    expect($pos)->toBe($this->expectedIds,
        'MenuService xếp cụm hoà display_order theo thứ tự khác id tăng dần — thiếu tie-break `menu_products.id` (#3170).');
    expect($guest)->toBe($this->expectedIds,
        'CustomerMenuService xếp cụm hoà display_order theo thứ tự khác id tăng dần — thiếu tie-break `menu_products.id` (#3170).');
    expect($replica)->toBe($this->expectedIds,
        'MenuCatalogReplicaBuilder xếp cụm hoà display_order theo thứ tự khác id tăng dần — thiếu tie-break `menu_products.id` (#3170).');

    // Vế 1 — điều issue yêu cầu, viết thẳng ra để lý do tồn tại của file không
    // phải suy từ ba assert trên.
    expect($guest)->toBe($pos, 'Menu khách và POS xếp cùng một thực đơn khác nhau (#3170).');
    expect($replica)->toBe($pos, 'Replica máy trạm và POS xếp cùng một thực đơn khác nhau (#3170).');
});

it('vẫn tôn trọng display_order khi display_order KHÔNG hoà — tie-break chỉ phá thế hoà', function () {
    // Đảo display_order theo chiều ngược id: nếu tie-break lỡ được viết thành
    // `orderBy('id')` ĐỨNG TRƯỚC display_order thì test trên vẫn xanh còn test
    // này đỏ. Không có nó, rào không phân biệt được hai cách viết.
    foreach ($this->expectedIds as $rank => $menuProductId) {
        DB::table('menu_products')
            ->where('id', $menuProductId)
            ->update(['display_order' => TIED_ROWS - $rank]);
    }

    $reversed = array_reverse($this->expectedIds);

    expect(posMenuProductIds($this->menu->id))->toBe($reversed);
    expect(guestMenuProductIds($this->branch->id))->toBe($reversed);
    expect(replicaMenuProductIds($this->branch->id, $this->menu->id))->toBe($reversed);
});
