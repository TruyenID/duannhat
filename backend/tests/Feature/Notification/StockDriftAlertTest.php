<?php

declare(strict_types=1);

/**
 * #2697 (lỗ 3 của #2694) — `[inventory.stock_drift]` phải tới được một con người.
 *
 * Hai chiều, và chiều thứ hai là chiều mà một slug vai sai sẽ giết trong im
 * lặng:
 *
 *   1. **PHẢI BÁO** — trừ kho hỏng lúc đóng đơn ⇒ có thông báo thật, và **số
 *      người nhận > 0**. Chỉ khẳng định "có một hàng `notifications`" là chưa
 *      đủ: `EloquentRoleAssignmentDirectory::withRole()` so `roles.slug` chính
 *      xác, nên `shop_manager` (gạch dưới) dựng ra đúng một hàng thông báo với
 *      **không một người nhận nào** — xanh, và vô dụng. Đã xảy ra bốn lần
 *      (#2451, #2456).
 *   2. **PHẢI IM** — đóng đơn bình thường ⇒ không thông báo drift nào. Một cảnh
 *      báo kêu cả lúc mọi thứ đúng thì bị tắt, và tắt xong thì không còn cảnh
 *      báo nào nữa.
 *
 * Người nhận gồm cả `org-admin` giữ `all_branches_access` (`branch_id IS NULL`)
 * — #2460: NULL nghĩa là MỌI chi nhánh, không phải "không chi nhánh nào".
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\StockDriftAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $consoleOrgId = (string) Str::uuid();

    $this->organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrgId,
        'slug' => 'drift-'.Str::random(6),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
    ]);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    // Quản lý quán: phân công GẮN chi nhánh.
    $this->shopManager = User::factory()->create(['console_organization_id' => $consoleOrgId]);
    stockDriftAssignRole($this->shopManager, 'shop-manager', (string) $this->organization->id, (string) $this->branch->id);

    // Admin tổ chức: `branch_id = NULL` = all_branches_access (#2460). Nếu cổng
    // đọc NULL thành "không chi nhánh nào", người này biến mất khỏi danh sách.
    $this->orgAdmin = User::factory()->create(['console_organization_id' => $consoleOrgId]);
    stockDriftAssignRole($this->orgAdmin, 'org-admin', (string) $this->organization->id, null);
});

/** Gắn một vai cho user, đúng hình dạng `role_user_pivots` mà cổng vai đọc. */
function stockDriftAssignRole(User $user, string $slug, string $organizationId, ?string $branchId): void
{
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => $organizationId,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'level' => 100,
        ],
    );

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Đơn takeaway đã trả đủ tiền, sẵn sàng để `close()` chạy tới bước trừ kho. */
function stockDriftPaidOrder(object $test): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $test->organization->id,
        'brand_id' => $test->brand->id,
        'branch_id' => $test->branch->id,
        'order_code' => 'ORD-DRIFT-'.Str::random(5),
        'order_type' => 'takeaway',
        'status' => 'open',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);

    $order->items()->create([
        'product_sku_id' => $test->sku->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'original_unit_price' => 1000,
        'tax_rate' => 0,
        'subtotal' => 1000,
        'status' => 'served',
        'served_at' => now(),
    ]);

    return $order;
}

// =========================================================================
//  PHẢI BÁO
// =========================================================================

it('#2697 — trừ kho hỏng lúc đóng đơn sinh thông báo drift tới người nhận KHÁC RỖNG', function () {
    $fake = Mockery::mock(OrderLineStockDeduction::class);
    $fake->shouldIgnoreMissing();
    $fake->shouldReceive('sweepUndeductedLinesAtClose')
        ->andThrow(new RuntimeException('insufficient stock for material X'));
    app()->instance(OrderLineStockDeduction::class, $fake);

    $order = stockDriftPaidOrder($this);

    app(OrderClosingService::class)->close($order);

    // Tiền vẫn còn, đơn vẫn đóng — cảnh báo là thứ THÊM vào, không thay thế.
    expect($order->fresh()->status->value)->toBe('closed');

    $notification = Notification::query()
        ->where('type', StockDriftAlertService::TYPE)
        ->latest('id')
        ->first();

    expect($notification)->not->toBeNull();

    $recipientIds = $notification->recipients()->pluck('recipient_id')->all();

    // ĐÂY là khẳng định có giá. Một slug sai vẫn tạo ra `$notification` ở trên;
    // chỉ con số này mới phân biệt "đã báo" với "đã ghi một hàng cho không ai".
    expect(count($recipientIds))->toBeGreaterThan(0)
        ->and($recipientIds)->toContain($this->shopManager->id)
        ->and($recipientIds)->toContain($this->orgAdmin->id);

    expect($notification->subject_type)->toBe('CustomerOrder')
        ->and($notification->subject_id)->toBe($order->id)
        ->and($notification->params['stage'] ?? null)->toBe(StockDriftAlertService::STAGE_ORDER_CLOSE)
        ->and($notification->params['order_code'] ?? null)->toBe($order->order_code)
        ->and($notification->priority->value)->toBe('high');
});

it('#2697 — một lần trừ kho hỏng chỉ sinh MỘT thông báo, kể cả khi đóng lại', function () {
    $fake = Mockery::mock(OrderLineStockDeduction::class);
    $fake->shouldIgnoreMissing();
    $fake->shouldReceive('sweepUndeductedLinesAtClose')
        ->andThrow(new RuntimeException('insufficient stock for material X'));
    app()->instance(OrderLineStockDeduction::class, $fake);

    $order = stockDriftPaidOrder($this);

    app(OrderClosingService::class)->close($order);
    app(OrderClosingService::class)->close($order->fresh());

    expect(Notification::query()->where('type', StockDriftAlertService::TYPE)->count())->toBe(1);
});

it('#2697 — lệnh repair bù kho thất bại cũng báo cho người sống', function () {
    $order = stockDriftPaidOrder($this);

    $item = $order->items()->first();
    $item->forceFill([
        'status' => OrderItemStatusEnum::Voided->value,
        'stock_deducted_at' => now()->subHour(),
        'voided_at' => now()->subMinutes(30),
        'void_reason_id' => null,
    ])->save();

    $stock = Mockery::mock(StockDeductionService::class);
    $stock->shouldIgnoreMissing();
    $stock->shouldReceive('hasOutstandingDeduction')->andReturn(true);
    $stock->shouldReceive('compensateVoid')->andThrow(new RuntimeException('lot already consumed'));
    app()->instance(StockDeductionService::class, $stock);

    $this->artisan('stock:repair-void-compensation', ['--repair' => true])->assertExitCode(1);

    $notification = Notification::query()
        ->where('type', StockDriftAlertService::TYPE)
        ->latest('id')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->params['stage'] ?? null)->toBe(StockDriftAlertService::STAGE_VOID_REPAIR)
        ->and($notification->params['order_item_id'] ?? null)->toBe((string) $item->getKey());

    expect($notification->recipients()->count())->toBeGreaterThan(0);
});

// =========================================================================
//  PHẢI IM
// =========================================================================

it('#2697 — đóng đơn bình thường KHÔNG sinh thông báo drift nào', function () {
    $order = stockDriftPaidOrder($this);

    app(OrderClosingService::class)->close($order);

    expect($order->fresh()->status->value)->toBe('closed')
        ->and(Notification::query()->where('type', StockDriftAlertService::TYPE)->count())->toBe(0);
});

// =========================================================================
//  Người nhận rỗng — hỏng, nhưng hỏng LỘ RA, không hỏng im
// =========================================================================

it('#2697 — không ai giữ vai ở chi nhánh thì việc đóng đơn vẫn sống sót', function () {
    DB::table('role_user_pivots')->delete();

    $fake = Mockery::mock(OrderLineStockDeduction::class);
    $fake->shouldIgnoreMissing();
    $fake->shouldReceive('sweepUndeductedLinesAtClose')
        ->andThrow(new RuntimeException('insufficient stock for material X'));
    app()->instance(OrderLineStockDeduction::class, $fake);

    $order = stockDriftPaidOrder($this);

    // Audience rỗng ném `NotificationException` bên trong cổng; nó phải chết ở
    // đó, không được leo lên đường đã thu tiền của khách.
    app(OrderClosingService::class)->close($order);

    expect($order->fresh()->status->value)->toBe('closed')
        ->and(Notification::query()->where('type', StockDriftAlertService::TYPE)->count())->toBe(0);
});
