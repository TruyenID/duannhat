<?php

/**
 * #1731 — cạnh Inventory → Ordering / Catalog cuối cùng của #962.
 *
 * `StockDeductionService` thôi cầm `CustomerOrderItem`, `ProductSku` và
 * `Recipe`; nó hỏi qua `OrderStockLineReads` (Ordering) + `SkuDirectory`
 * (Catalog).
 *
 * ## File này tồn tại để chặn ĐÚNG MỘT kiểu gian lận
 *
 * Cách rẻ tiền để làm deptrac xanh là bỏ type-hint mà vẫn đọc y nguyên model.
 * Bánh cóc sẽ tụt, ranh giới thì không đổi gì. Nên phép đo ở đây **không đọc mã
 * nguồn**: nó thay hiện thực của hai cổng bằng bản gián điệp trong container rồi
 * chạy đường trừ kho thật. Nếu service quay về đọc Eloquent, gián điệp đếm 0 và
 * test đỏ — không có cách nào vừa qua được nó vừa không thật sự đi qua cổng.
 *
 * ## Cái file này KHÔNG chứng minh được, và tại sao
 *
 * `lockForUpdate()` **không kiểm được ở đây**: bộ test chạy trên SQLite
 * (`phpunit.xml`), mà `SQLiteGrammar::compileLock()` trả về CHUỖI RỖNG — câu
 * lệnh chạy, không lỗi, và không khoá gì. Một test kiểu "SQL có chứa `for
 * update`" sẽ **luôn đỏ** ở đây và luôn xanh trên MySQL, tức nó đo driver chứ
 * không đo ý định.
 *
 * Thứ kiểm được, và là thứ thật sự hay hỏng: **điều kiện tiên quyết của khoá** —
 * khoá phải nằm trong một transaction, nếu không nó nhả ngay và biến mất trong
 * im lặng. Cái đó được cưỡng chế ở `EloquentOrderStockLineReads` và kiểm ở dưới.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\Contracts\OrderLineStockSnapshot;
use App\Services\Order\Contracts\OrderStockLineReads;
use App\Services\Order\Internal\EloquentOrderStockLineReads;
use App\Services\Product\Contracts\SkuDirectory;
use App\Services\Product\Contracts\SkuSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 1000,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $this->material->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->recipeId = $recipe->id;

    $this->orders = app(CustomerOrderService::class);
    $this->closing = app(OrderClosingService::class);
});

/**
 * SKU của tổ chức test, chế độ kho do lời gọi quyết định.
 *
 * MỖI SKU một sản phẩm riêng: `product_skus` có unique
 * `(product_id, option_signature)` và factory để `option_signature` rỗng, nên
 * hai SKU trên cùng sản phẩm là vi phạm ràng buộc chứ không phải hai biến thể.
 */
function sdlpSku(string $inventoryMode): ProductSku
{
    $product = Product::factory()->active()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => $inventoryMode,
        'recipe_id' => test()->recipeId,
        'selling_price' => 500,
    ]);

    StockLevel::create([
        'warehouse_id' => test()->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    return $sku;
}

function sdlpOrder(): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'created_by_id' => test()->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
}

function sdlpAddItem(CustomerOrder $order, ProductSku $sku, float $qty = 2): CustomerOrderItem
{
    $items = test()->orders->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => $qty,
    ]]]);

    return $items[0]->fresh();
}

function sdlpClose(CustomerOrder $order): void
{
    $fresh = $order->fresh();
    $fresh->forceFill(['paid_amount' => $fresh->total_amount])->save();
    test()->closing->close($fresh);
}

function sdlpTxs(CustomerOrder $order, string $subType)
{
    return StockTransaction::where('reference_type', 'customer_order')
        ->where('reference_id', $order->id)
        ->where('sub_type', $subType)
        ->with('items')
        ->get();
}

function sdlpMaterialLevel(): float
{
    return (float) StockLevel::where('warehouse_id', test()->warehouse->id)
        ->where('material_id', test()->material->id)
        ->value('quantity');
}

// =========================================================================
//  A — runtime ĐI QUA CỔNG, không phải chỉ hết type-hint
// =========================================================================

it('#1731 đường trừ kho đi qua CỔNG dòng đơn — thay hiện thực thì nó thấy', function () {
    $calls = [];

    // Gián điệp bọc hiện thực thật: hành vi không đổi, chỉ đếm. Service đọc
    // Eloquent thẳng thì bộ đếm này đứng ở 0.
    app()->extend(OrderStockLineReads::class, function (OrderStockLineReads $inner) use (&$calls) {
        return new class($inner, $calls) implements OrderStockLineReads
        {
            public function __construct(private OrderStockLineReads $inner, private array &$calls) {}

            public function orderIdOf(string $id): ?string
            {
                $this->calls[] = 'orderIdOf';

                return $this->inner->orderIdOf($id);
            }

            public function find(string $id): ?OrderLineStockSnapshot
            {
                $this->calls[] = 'find';

                return $this->inner->find($id);
            }

            public function lockLine(string $id): ?OrderLineStockSnapshot
            {
                $this->calls[] = 'lockLine';

                return $this->inner->lockLine($id);
            }

            public function lockUndeductedLine(string $id): ?OrderLineStockSnapshot
            {
                $this->calls[] = 'lockUndeductedLine';

                return $this->inner->lockUndeductedLine($id);
            }

            public function undeductedLinesOfOrder(string $orderId): array
            {
                $this->calls[] = 'undeductedLinesOfOrder';

                return $this->inner->undeductedLinesOfOrder($orderId);
            }

            public function activeLinesOfOrder(string $orderId): array
            {
                $this->calls[] = 'activeLinesOfOrder';

                return $this->inner->activeLinesOfOrder($orderId);
            }

            public function byIds(array $ids): array
            {
                $this->calls[] = 'byIds';

                return $this->inner->byIds($ids);
            }
        };
    });

    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    sdlpAddItem($order, $sku);
    sdlpClose($order);

    expect($calls)->toContain('undeductedLinesOfOrder')
        ->and(sdlpTxs($order, 'sales'))->toHaveCount(1);
});

it('#1731 đường trừ kho đi qua CỔNG danh mục — thay hiện thực thì nó thấy', function () {
    $seen = [];

    app()->extend(SkuDirectory::class, function (SkuDirectory $inner) use (&$seen) {
        return new class($inner, $seen) implements SkuDirectory
        {
            public function __construct(private SkuDirectory $inner, private array &$seen) {}

            public function findWithRecipe(string $skuId): ?SkuSnapshot
            {
                return $this->inner->findWithRecipe($skuId);
            }

            public function findWithRecipeForOrganization(string $skuId, string $organizationId): ?SkuSnapshot
            {
                return $this->inner->findWithRecipeForOrganization($skuId, $organizationId);
            }

            public function getWithRecipeForOrganization(string $skuId, string $organizationId): SkuSnapshot
            {
                return $this->inner->getWithRecipeForOrganization($skuId, $organizationId);
            }

            public function activeWithRecipeForOrganization(string $organizationId, ?string $brandId = null): array
            {
                return $this->inner->activeWithRecipeForOrganization($organizationId, $brandId);
            }

            public function byIdsForOrganization(array $skuIds, string $organizationId): array
            {
                return $this->inner->byIdsForOrganization($skuIds, $organizationId);
            }

            public function byIds(array $skuIds): array
            {
                $this->seen = array_merge($this->seen, $skuIds);

                return $this->inner->byIds($skuIds);
            }
        };
    });

    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    sdlpAddItem($order, $sku);
    sdlpClose($order);

    expect($seen)->toContain((string) $sku->id)
        ->and(sdlpTxs($order, 'sales_material_consumption'))->toHaveCount(1);
});

// =========================================================================
//  B — `inventoryMode` thật sự tới được động cơ (nửa thứ nhất của #1731)
// =========================================================================

it('#1731 SkuSnapshot mang inventoryMode — track_stock sinh phiếu xuất, made_to_order thì không', function () {
    $tracked = sdlpSku('track_stock');
    $orderA = sdlpOrder();
    sdlpAddItem($orderA, $tracked);
    sdlpClose($orderA);

    $made = sdlpSku('made_to_order');
    $orderB = sdlpOrder();
    sdlpAddItem($orderB, $made);
    sdlpClose($orderB);

    expect(sdlpTxs($orderA, 'sales'))->toHaveCount(1)
        ->and(sdlpTxs($orderB, 'sales'))->toHaveCount(0);
});

it('#1731 cổng danh mục trả đúng inventoryMode cho cả hai chế độ', function () {
    $tracked = sdlpSku('track_stock');
    $made = sdlpSku('made_to_order');

    $snapshots = app(SkuDirectory::class)->byIds([(string) $tracked->id, (string) $made->id]);

    expect($snapshots[(string) $tracked->id]->tracksStock())->toBeTrue()
        ->and($snapshots[(string) $made->id]->tracksStock())->toBeFalse();
});

// =========================================================================
//  C — điều kiện tiên quyết của KHOÁ được cưỡng chế (nửa thứ hai)
// =========================================================================

it('#1731 lockUndeductedLine NGOÀI transaction thì ném — khoá nhả ngay là không khoá gì', function () {
    // `RefreshDatabase` bọc MỌI test trong một transaction, nên
    // `DB::transactionLevel()` ở đây không bao giờ về 0 trên kết nối mặc định —
    // guard này sẽ không bao giờ được chạm tới nếu chỉ gọi thẳng.
    //
    // Nên đổi kết nối mặc định sang một kết nối SẠCH: guard đọc
    // `DB::transactionLevel()` của kết nối mặc định và ném TRƯỚC khi phát bất kỳ
    // câu lệnh nào, nên kết nối rỗng là đủ để đi đúng nhánh thật.
    config(['database.connections.lockguard_probe' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    $previous = config('database.default');

    try {
        config(['database.default' => 'lockguard_probe']);
        DB::setDefaultConnection('lockguard_probe');

        expect(DB::transactionLevel())->toBe(0);

        $reads = new EloquentOrderStockLineReads;

        expect(fn () => $reads->lockUndeductedLine('bất-kỳ-id-nào'))
            ->toThrow(LogicException::class);
        expect(fn () => $reads->lockLine('bất-kỳ-id-nào'))
            ->toThrow(LogicException::class);
    } finally {
        DB::setDefaultConnection($previous);
        config(['database.default' => $previous]);
    }
});

it('#1731 trong transaction thì hai method khoá chạy bình thường', function () {
    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    $item = sdlpAddItem($order, $sku);

    DB::transaction(function () use ($item) {
        $reads = app(OrderStockLineReads::class);

        expect($reads->lockUndeductedLine((string) $item->id))
            ->toBeInstanceOf(OrderLineStockSnapshot::class);
        expect($reads->lockLine((string) $item->id))
            ->toBeInstanceOf(OrderLineStockSnapshot::class);
    });
});

it('#1731 lockUndeductedLine lọc dòng ĐÃ TRỪ ngay trong câu khoá', function () {
    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    $item = sdlpAddItem($order, $sku);

    CustomerOrderItem::whereKey($item->id)->update(['stock_deducted_at' => now()]);

    DB::transaction(function () use ($item) {
        $reads = app(OrderStockLineReads::class);

        // Bộ lọc nằm TRONG câu khoá: dòng đã có dấu thì không trả về gì cả…
        expect($reads->lockUndeductedLine((string) $item->id))->toBeNull();
        // …trong khi `lockLine` (không lọc) vẫn thấy nó.
        expect($reads->lockLine((string) $item->id))->not->toBeNull();
    });
});

// =========================================================================
//  D — ảnh chụp dòng đơn mang đúng thứ động cơ đọc
// =========================================================================

it('#1731 ảnh chụp dòng đơn mang topping kèm cờ đã-huỷ', function () {
    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    $item = sdlpAddItem($order, $sku, 3);

    $snapshot = app(OrderStockLineReads::class)->find((string) $item->id);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->id)->toBe((string) $item->id)
        ->and($snapshot->orderId)->toBe((string) $order->id)
        ->and($snapshot->productSkuId)->toBe((string) $sku->id)
        ->and($snapshot->quantity)->toBe(3.0)
        ->and($snapshot->isDeducted())->toBeFalse()
        ->and($snapshot->toppings)->toBeArray();
});

it('#1731 undeductedLinesOfOrder bỏ dòng đã có dấu, activeLinesOfOrder thì không', function () {
    // HAI SKU khác nhau: `addItems` GỘP hai lần thêm cùng một SKU vào MỘT dòng,
    // nên bản dùng chung SKU chỉ dựng được một dòng và phép đo vô nghĩa.
    $order = sdlpOrder();
    $a = sdlpAddItem($order, sdlpSku('track_stock'));
    $b = sdlpAddItem($order, sdlpSku('track_stock'));

    CustomerOrderItem::whereKey($a->id)->update(['stock_deducted_at' => now()]);

    $reads = app(OrderStockLineReads::class);

    $undeducted = array_map(static fn ($l) => $l->id, $reads->undeductedLinesOfOrder((string) $order->id));
    $active = array_map(static fn ($l) => $l->id, $reads->activeLinesOfOrder((string) $order->id));

    expect($undeducted)->toBe([(string) $b->id])
        ->and($active)->toHaveCount(2);
});

// =========================================================================
//  E — trừ kho vẫn chỉ xảy ra MỘT lần (dấu vẫn là thứ chặn)
// =========================================================================

it('#1731 quét lúc đóng đơn không trừ lại dòng đã có dấu', function () {
    $sku = sdlpSku('track_stock');
    $order = sdlpOrder();
    sdlpAddItem($order, $sku);

    sdlpClose($order);
    $afterFirst = sdlpMaterialLevel();

    // Chạy lại lượt quét trên đúng đơn đó: mọi dòng đã có dấu ⇒ không gì thêm.
    app(StockDeductionService::class)
        ->sweepUndeductedLinesAtClose((string) $order->id);

    expect(sdlpMaterialLevel())->toBe($afterFirst)
        ->and(sdlpTxs($order, 'sales'))->toHaveCount(1)
        ->and(sdlpTxs($order, 'sales_material_consumption'))->toHaveCount(1);
});
