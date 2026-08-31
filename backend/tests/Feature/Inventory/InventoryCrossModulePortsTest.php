<?php

declare(strict_types=1);

/**
 * #962 (cụm Inventory) — bốn cổng mà Inventory KHAI và module khác HIỆN THỰC.
 *
 * Trước PR này, ba class của Inventory với tay thẳng vào model của module khác:
 *
 *   StockDeductionService          → ShopOrderSetting, VoidReason   (Ordering)
 *   EloquentOrderLineStockDeduction → VoidReason                     (Ordering)
 *   RecallService                  → CustomerOrder                   (Ordering)
 *                                  → Customer                        (CustomerEngagement)
 *
 * Bài test này ghim hai thứ khác nhau, và cả hai đều cần thiết:
 *
 *  1. **Cổng có thật** — bind được, và trả về ĐÚNG dữ liệu mà bản cũ đọc trực
 *     tiếp từ model. Một cổng công bố mà không ai bind là cái bẫy #1544 đã bắt
 *     được; một cổng bind đúng nhưng trả sai thì tệ hơn hẳn, vì nó im lặng.
 *  2. **Hành vi trừ kho không đổi** — đặc biệt là món `voided` VẪN bị loại khỏi
 *     phép trừ NVL (chính sách #1148). Đó là thứ duy nhất trong gói này mà làm
 *     hỏng thì hỏng KHO THẬT, nên nó được đo lại sau khi đường đọc cấu hình
 *     `stock_deduction_timing` đi qua cổng.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\VoidReason;
use App\Models\Warehouse;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\Internal\EloquentCustomerNotifiableDirectory;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\Contracts\CustomerNotifiableDirectory;
use App\Services\Inventory\Contracts\VoidReasonStockEffects;
use App\Services\Inventory\RecallService;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\Contracts\BranchStockDeductionTiming;
use App\Services\Order\Contracts\OrderCustomerContacts;
use App\Services\Order\Contracts\OrderStockContext;
use App\Services\Order\Contracts\OrderStockContextReads;
use App\Services\Order\Internal\EloquentBranchStockDeductionTiming;
use App\Services\Order\Internal\EloquentOrderCustomerContacts;
use App\Services\Order\Internal\EloquentOrderStockContextReads;
use App\Services\Order\Internal\EloquentVoidReasonStockEffects;
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

    // allow_negative_sales=true + 0 SKU stock = ca made-to-order chuẩn (plan-024
    // G3): NVL của công thức trừ lúc bán, rào "hàng làm sẵn" đứng im.
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

    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
        'selling_price' => 500,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $this->orders = app(CustomerOrderService::class);
    $this->closing = app(OrderClosingService::class);
});

function icpSetting(array $attrs): void
{
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => test()->branch->id],
        array_merge(['organization_id' => test()->orgId], $attrs),
    );
}

function icpOrder(array $overrides = []): CustomerOrder
{
    return CustomerOrder::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'created_by_id' => test()->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ], $overrides));
}

function icpMaterialLevel(): float
{
    return (float) StockLevel::where('warehouse_id', test()->warehouse->id)
        ->where('material_id', test()->material->id)
        ->value('quantity');
}

// =========================================================================
//  Cổng có THẬT — bind được, và bind vào đúng hiện thực
// =========================================================================

it('bốn cổng đều bind vào hiện thực của module SỞ HỮU BẢNG', function (string $port, string $impl) {
    expect(app($port))->toBeInstanceOf($impl);
})->with([
    // shop_order_settings / void_reasons / customer_orders đều là bảng của Ordering…
    [BranchStockDeductionTiming::class, EloquentBranchStockDeductionTiming::class],
    [VoidReasonStockEffects::class, EloquentVoidReasonStockEffects::class],
    [OrderCustomerContacts::class, EloquentOrderCustomerContacts::class],
    // …còn customers là của CustomerEngagement.
    [CustomerNotifiableDirectory::class, EloquentCustomerNotifiableDirectory::class],
]);

// =========================================================================
//  BranchStockDeductionTiming — một cột, ba ca hạ cấp
// =========================================================================

it('timing đọc qua cổng trả đúng giá trị đã cấu hình', function () {
    icpSetting(['stock_deduction_timing' => 'on_add']);

    expect(app(BranchStockDeductionTiming::class)->rawTimingFor((string) $this->branch->id))
        ->toBe('on_add')
        ->and(app(StockDeductionService::class)->timingForBranch((string) $this->branch->id))
        ->toBe(StockDeductionTimingEnum::OnAdd);
});

it('chi nhánh KHÔNG có hàng cấu hình: cổng trả null, service hạ về on_close', function () {
    $ghostBranch = '00000000-0000-4000-8000-0000000000ff';

    expect(app(BranchStockDeductionTiming::class)->rawTimingFor($ghostBranch))->toBeNull()
        ->and(app(StockDeductionService::class)->timingForBranch($ghostBranch))
        ->toBe(StockDeductionTimingEnum::OnClose);
});

it('hàng cấu hình CÓ nhưng shop chưa chọn timing: mặc định on_close', function () {
    icpSetting([]); // cột NOT NULL, lấy default của schema

    expect(app(BranchStockDeductionTiming::class)->rawTimingFor((string) $this->branch->id))
        ->toBe(StockDeductionTimingEnum::OnClose->value)
        ->and(app(StockDeductionService::class)->timingForBranch((string) $this->branch->id))
        ->toBe(StockDeductionTimingEnum::OnClose);
});

// =========================================================================
//  VoidReasonStockEffects — ba trường của bảng sự thật #1149
// =========================================================================

it('cổng lý do void trả đúng ba trường mà compensateVoid đọc', function () {
    $reason = VoidReason::create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'stock_effect' => 'waste',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
        'en' => ['label' => 'Đổ mất'],
    ]);

    $snapshot = app(VoidReasonStockEffects::class)->find((string) $reason->id);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->id)->toBe((string) $reason->id)
        ->and($snapshot->stockEffect)->toBe('waste')
        ->and($snapshot->label)->toBe('Đổ mất');
});

it('lý do void không tồn tại ⇒ null (rơi vào nhánh "không rõ lý do → không bù")', function () {
    expect(app(VoidReasonStockEffects::class)->find('00000000-0000-4000-8000-000000000000'))
        ->toBeNull();
});

// =========================================================================
//  OrderCustomerContacts / CustomerNotifiableDirectory — đường đi tìm
//  người nhận thông báo thu hồi
// =========================================================================

it('cổng đơn→khách phân biệt khách đăng ký với khách vãng lai', function () {
    $customer = Customer::factory()->create(['organization_id' => $this->orgId]);
    $withCustomer = icpOrder(['customer_id' => $customer->id]);
    $guest = icpOrder(['customer_id' => null]);
    $ghost = '00000000-0000-4000-8000-000000000000';

    $map = app(OrderCustomerContacts::class)->customerIdsByOrderId([
        (string) $withCustomer->id,
        (string) $guest->id,
        $ghost,
    ]);

    expect($map[(string) $withCustomer->id])->toBe((string) $customer->id)
        // Khách vãng lai: CÓ khoá, giá trị null. "Không có ai để báo" khác với
        // "chưa báo" — `RecallService::notify` dựa vào đúng phân biệt này.
        ->and(array_key_exists((string) $guest->id, $map))->toBeTrue()
        ->and($map[(string) $guest->id])->toBeNull()
        // Đơn không tồn tại: KHÔNG có khoá, y như `pluck` của bản cũ.
        ->and(array_key_exists($ghost, $map))->toBeFalse();

    expect(app(OrderCustomerContacts::class)->customerIdsByOrderId([]))->toBe([]);
});

it('RecallService::notify đi qua CẢ HAI cổng: chỉ khách đăng ký được báo, đơn vãng lai vẫn đóng sổ', function () {
    $customer = Customer::factory()->create(['organization_id' => $this->orgId]);
    $withCustomer = icpOrder(['customer_id' => $customer->id]);
    $guest = icpOrder(['customer_id' => null]);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
    ]);
    $recall = Recall::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'root_lot_id' => $lot->id,
        'status' => 'active',
        'completed_at' => null,
        'cancelled_at' => null,
        'cancellation_reason' => null,
    ]);
    foreach ([$withCustomer, $guest] as $order) {
        RecallAffectedOrder::factory()->create([
            'recall_id' => $recall->id,
            'customer_order_id' => $order->id,
            'notified_at' => null,
            'notification_id' => null,
            'notification_channel' => null,
        ]);
    }

    // Nền tảng thông báo nằm ngoài phạm vi bài test này; cái được ghim là DANH
    // SÁCH NGƯỜI NHẬN mà hai cổng dựng ra.
    $dispatcher = Mockery::mock(NotificationDispatcher::class);
    $dispatcher->shouldReceive('toRecipients')
        ->once()
        ->withArgs(function (NotificationRequest $request, iterable $recipients) use ($customer): bool {
            $ids = collect($recipients)->pluck('id')->map(fn ($id) => (string) $id)->all();

            return $request->type === 'material_lot.recall_affected'
                && $ids === [(string) $customer->id];
        })
        ->andReturn('notification-1');
    app()->instance(NotificationDispatcher::class, $dispatcher);

    app(RecallService::class)->notify($recall);

    $rows = RecallAffectedOrder::where('recall_id', $recall->id)
        ->get()
        ->keyBy('customer_order_id');

    expect($rows[(string) $withCustomer->id]->notification_id)->toBe('notification-1')
        ->and($rows[(string) $withCustomer->id]->notified_at)->not->toBeNull()
        // Đơn vãng lai: đã xử lý, nhưng không có ai để báo.
        ->and($rows[(string) $guest->id]->notification_id)->toBeNull()
        ->and($rows[(string) $guest->id]->notified_at)->not->toBeNull();
});

it('cổng người-nhận trả đúng khách theo id, danh sách rỗng ⇒ collection rỗng', function () {
    $a = Customer::factory()->create(['organization_id' => $this->orgId]);
    $b = Customer::factory()->create(['organization_id' => $this->orgId]);
    Customer::factory()->create(['organization_id' => $this->orgId]); // không được gọi tên

    $port = app(CustomerNotifiableDirectory::class);

    $recipients = $port->notifiablesForIds([(string) $a->id, (string) $b->id]);
    expect($recipients)->toHaveCount(2)
        ->and($recipients->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all())
        ->toBe(collect([(string) $a->id, (string) $b->id])->sort()->values()->all());

    expect($port->notifiablesForIds([]))->toHaveCount(0);
});

// =========================================================================
//  LOAD-BEARING — món `voided` VẪN bị loại khỏi phép trừ NVL (#1148)
// =========================================================================

it('on_preparing: deductLine trên dòng ĐÃ VOID không trừ một gram NVL nào', function () {
    icpSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = icpOrder();
    $item = $this->orders->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
    ]]])[0]->fresh();

    // Void dòng khi nó còn pending ⇒ chưa có marker, chưa trừ gì.
    $item->forceFill(['status' => 'voided', 'voided_at' => now()])->save();

    app(StockDeductionService::class)->deductLine((string) $item->getKey(), 'on_preparing');

    expect(icpMaterialLevel())->toBe(1000.0)
        ->and($item->fresh()->stock_deducted_at)->toBeNull();
});

it('on_close: quét lúc đóng đơn BỎ QUA dòng voided và chỉ trừ dòng còn sống', function () {
    icpSetting([]); // mặc định on_close

    $order = icpOrder();
    $alive = $this->orders->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
    ]]])[0]->fresh();

    // Dòng thứ hai dựng thẳng bằng factory: `addItems` GỘP hai dòng cùng SKU
    // vào một dòng, nên gọi nó hai lần không cho hai dòng để so sánh.
    $voided = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 3,
        'unit_price' => 500,
        'subtotal' => 1500,
        'status' => 'voided',
        'voided_at' => now(),
        'note' => null,
    ]);

    $fresh = $order->fresh();
    $fresh->forceFill(['paid_amount' => $fresh->total_amount])->save();
    $this->closing->close($fresh);

    // Chỉ 2 suất × 10g = 20g. Nếu dòng voided lọt vào thì sẽ là 50g — đúng cái
    // lệch kho mà cảnh báo đỏ trong admin Settings nói tới.
    expect(icpMaterialLevel())->toBe(980.0)
        ->and($voided->fresh()->stock_deducted_at)->toBeNull()
        ->and($alive->fresh()->stock_deducted_at)->not->toBeNull();
});

// =========================================================================
//  #1605 — OrderStockContextReads: sáu trường của ĐƠN mà động cơ trừ kho đọc
// =========================================================================

it('cổng ảnh chụp đơn bind vào hiện thực của Ordering', function () {
    expect(app(OrderStockContextReads::class))->toBeInstanceOf(EloquentOrderStockContextReads::class);
});

it('cổng trả đúng sáu trường, và null cho đơn không tồn tại', function () {
    $customer = Customer::factory()->create(['organization_id' => $this->orgId]);
    $order = icpOrder(['customer_id' => $customer->id]);

    $ctx = app(OrderStockContextReads::class)->find((string) $order->id);

    expect($ctx)->toBeInstanceOf(OrderStockContext::class)
        ->and($ctx->id)->toBe((string) $order->id)
        ->and($ctx->organizationId)->toBe((string) $order->organization_id)
        ->and($ctx->branchId)->toBe((string) $order->branch_id)
        ->and($ctx->orderCode)->toBe((string) $order->order_code)
        ->and($ctx->createdById)->toBe((string) $this->user->id)
        ->and($ctx->customerId)->toBe((string) $customer->id);

    expect(app(OrderStockContextReads::class)->find('00000000-0000-4000-8000-000000000000'))
        ->toBeNull();
});

it('đơn không có người tạo / không có khách: hai trường nullable ra null chứ không ra chuỗi rỗng', function () {
    $order = icpOrder(['created_by_id' => null, 'customer_id' => null]);

    $ctx = app(OrderStockContextReads::class)->find((string) $order->id);

    expect($ctx->createdById)->toBeNull()
        ->and($ctx->customerId)->toBeNull();
});

/*
 * Hai bài dưới là phép thử CHỐNG ĂN GIAN, không phải phép thử tính năng.
 *
 * Gỡ một dòng `use App\Models\CustomerOrder` mà thân method vẫn đọc model thì
 * deptrac xanh còn ranh giới thì không đổi. Cách duy nhất phân biệt được là
 * THAY hiện thực của cổng và xem đầu ra có đổi theo không: nếu service vẫn tự
 * `CustomerOrder::find()`, thay cổng chẳng ảnh hưởng gì và cả hai bài này đỏ.
 */

it('thay cổng bằng bản trả null ⇒ lượt quét lúc đóng đơn KHÔNG trừ một gram nào', function () {
    icpSetting([]); // on_close

    $order = icpOrder();
    $item = $this->orders->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
    ]]])[0]->fresh();

    app()->instance(OrderStockContextReads::class, new class implements OrderStockContextReads
    {
        public function find(string $orderId): ?OrderStockContext
        {
            return null;
        }
    });

    app(StockDeductionService::class)->sweepUndeductedLinesAtClose((string) $order->id);

    expect(icpMaterialLevel())->toBe(1000.0)
        ->and($item->fresh()->stock_deducted_at)->toBeNull();
});

it('mã đơn in trên phiếu kho đến TỪ CỔNG, không từ model', function () {
    icpSetting([]); // on_close

    $order = icpOrder();
    $this->orders->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
    ]]]);

    $real = app(OrderStockContextReads::class)->find((string) $order->id);
    app()->instance(OrderStockContextReads::class, new class($real) implements OrderStockContextReads
    {
        public function __construct(private readonly OrderStockContext $real) {}

        public function find(string $orderId): ?OrderStockContext
        {
            return new OrderStockContext(
                id: $this->real->id,
                organizationId: $this->real->organizationId,
                branchId: $this->real->branchId,
                orderCode: 'PORT-1605',
                createdById: $this->real->createdById,
                customerId: $this->real->customerId,
            );
        }
    });

    app(StockDeductionService::class)->sweepUndeductedLinesAtClose((string) $order->id);

    $notes = StockTransaction::where('reference_type', 'customer_order')
        ->where('reference_id', $order->id)
        ->pluck('note')
        ->all();

    expect($notes)->not->toBeEmpty();
    foreach ($notes as $note) {
        expect($note)->toContain('PORT-1605')
            ->and($note)->not->toContain((string) $order->order_code);
    }

    // Và tiền/kho vẫn đi đúng: 2 suất × 10g.
    expect(icpMaterialLevel())->toBe(980.0);
});

// =========================================================================
//  Ratchet — hai cạnh #1605 vừa gỡ không được quay lại bằng một dòng `use`
// =========================================================================

it('P: hai file đã sạch model của Ordering không được nhập lại', function (array $case) {
    /*
     * Đọc mã nguồn chứ không hỏi deptrac: baseline chỉ được phép CO LẠI, nhưng
     * giữa lúc ai đó thêm lại `use` và lúc baseline được sinh lại, deptrac sẽ
     * báo một vi phạm MỚI — còn bài test này chỉ thẳng file và dòng.
     */
    [$file, $forbidden] = $case;

    /*
     * `expect($src)->not->toContain($needle, $message)` KHÔNG có tham số message:
     * `toContain` là biến thiên (`...$needles`), nên chuỗi giải thích bị đọc là
     * needle THỨ HAI và `not` thoả mãn ngay vì đường dẫn tuyệt đối không bao giờ
     * nằm trong mã nguồn. Ratchet P6 của `InventoryCatalogPublishedPortsTest`
     * từng viết đúng như vậy và KHÔNG BAO GIỜ nổ (#1605 đo được). Nên ở đây
     * khẳng định trên một boolean, chỗ mà message thật sự là message.
     */
    expect(str_contains(file_get_contents(base_path($file)), "use {$forbidden};"))
        ->toBeFalse($file." nhập lại {$forbidden} — cạnh #1605 đã gỡ đang quay lại");
})->with([
    [['app/Services/Inventory/StockDeductionService.php', CustomerOrder::class]],
    [['app/Services/Inventory/EloquentOrderLineStockDeduction.php', CustomerOrderItem::class]],
]);
