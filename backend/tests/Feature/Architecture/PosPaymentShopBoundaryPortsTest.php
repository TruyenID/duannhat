<?php

declare(strict_types=1);

/**
 * #962 (7b) — gói `App\Services\{Pos,Payment,Shop}` thôi cầm model/service của
 * Ordering.
 *
 * Bài này ghim HAI thứ khác nhau, và thứ hai mới là thứ dễ mất:
 *
 *  1. **Ranh giới** — sáu class không được import lại thứ vừa gỡ. Deptrac đã đo
 *     rồi, nhưng deptrac đo qua baseline mà baseline thì có thể được thêm vào;
 *     bài này thì không.
 *  2. **Tiền + pháp lý** — cổng phải trả ĐÚNG như đường cũ, kể cả những điều
 *     kiện trông như thừa. Đây là chỗ một PR "dọn dẹp" sau này bỏ bộ lọc void
 *     hay đổi nguồn tiền tệ mà ranh giới vẫn sạch, còn hoá đơn thì sai.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TableStatusChange;
use App\Models\TaxType;
use App\Models\Zone;
use App\Services\Customer\OrderTaxBreakdownAggregator;
use App\Services\Order\Contracts\OpenOrderTableUsage;
use App\Services\Order\Contracts\OrderPrepTimeDefaults;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;
use App\Services\Order\Contracts\TableStatusJournal;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
    ]);
    $this->reduced = TaxType::factory()->reduced()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->standard = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->branch->id,
    ]);
});

function sku962(object $ctx): ProductSku
{
    $product = Product::factory()->create([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'product_type_id' => $ctx->productType->id,
    ]);

    return ProductSku::factory()->create(['product_id' => $product->id]);
}

/** Bentō ¥1000 @8% + bia ¥500 @10% = ¥1500 + ¥130 thuế. */
function order962(object $ctx, string $status = 'closed'): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'branch_id' => $ctx->branch->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $ctx->orgId,
        'customer_id' => $ctx->customer->id, 'order_type' => 'takeaway', 'status' => $status,
        'subtotal' => 1500, 'discount_amount' => 0, 'tax_amount' => 130, 'total_amount' => 1630,
        'is_tax_included' => false,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id, 'product_sku_id' => sku962($ctx)->id, 'tax_type_id' => $ctx->reduced->id,
        'quantity' => 1, 'unit_price' => 1000, 'topping_subtotal' => 0, 'subtotal' => 1000,
        'tax_rate' => 8, 'tax_amount' => 80, 'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id, 'product_sku_id' => sku962($ctx)->id, 'tax_type_id' => $ctx->standard->id,
        'quantity' => 1, 'unit_price' => 500, 'topping_subtotal' => 0, 'subtotal' => 500,
        'tax_rate' => 10, 'tax_amount' => 50, 'status' => 'served',
    ]);

    return $order->fresh();
}

// =============================================================================
//  1. Ranh giới
// =============================================================================

it('bốn cổng mới đều bind được — cổng không có hiện thực là cổng trang trí', function () {
    // #1544 đã trả giá đúng chỗ này: `OrderQueryPort` từng tồn tại mà không ai
    // bind, nên mọi caller tin nó đều ăn container exception rồi quay về import
    // model — và cổng thành đồ trang trí trong khi nợ vẫn nguyên.
    expect(app(OpenOrderTableUsage::class))->toBeInstanceOf(OpenOrderTableUsage::class)
        ->and(app(TableStatusJournal::class))->toBeInstanceOf(TableStatusJournal::class)
        ->and(app(OrderTaxBreakdownReads::class))->toBeInstanceOf(OrderTaxBreakdownReads::class);
});

it('sáu class Pos/Payment/Shop không import lại thứ vừa gỡ', function (string $relativePath, array $forbidden) {
    $src = (string) file_get_contents(app_path($relativePath));

    foreach ($forbidden as $import) {
        expect($src)->not->toContain("use {$import};");
    }
})->with([
    ['Services/Pos/TillSessionService.php', ['App\Services\Customer\OrderTaxBreakdownAggregator']],
    ['Services/Shop/ShopTillTrackingService.php', ['App\Services\Customer\OrderTaxBreakdownAggregator']],
    ['Services/Shop/TableService.php', ['App\Models\CustomerOrder']],
    ['Services/Shop/ZoneService.php', ['App\Models\CustomerOrder']],
    ['Services/Shop/TableStatusService.php', ['App\Models\TableStatusChange']],
    ['Services/Shop/BrandSettingsService.php', ['App\Services\Shop\EffectiveOrderPolicyService']],
]);

it('mặc định phút chuẩn bị có ĐÚNG MỘT giá trị, không phải hai bản sao', function () {
    // Cách sửa hiển nhiên cho cạnh này là chép hằng số sang Organization. Hai
    // hằng số cùng tên ở hai module lệch nhau vào ngày ai đó chỉnh một cái, và
    // triệu chứng là màn hình HQ hứa một ETA khác cái POS in ra.
    expect(EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM)
        ->toBe(OrderPrepTimeDefaults::MINUTES_PER_ITEM);
});

// =============================================================================
//  2. Ba cổng hẹp — chuyển tiếp đúng vị từ cũ
// =============================================================================

it('OpenOrderTableUsage giữ nguyên định nghĩa "chưa đóng" của Ordering', function () {
    $zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $table = Table::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'zone_id' => $zone->id,
    ]);
    $port = app(OpenOrderTableUsage::class);

    // Lô rỗng: false mà không chạm DB — người gọi vốn đã tự bỏ qua ca này.
    expect($port->anyOpenOrderUsesTables([]))->toBeFalse()
        ->and($port->anyOpenOrderUsesTables([(string) $table->id]))->toBeFalse();

    $open = order962($this, 'open');
    CustomerOrder::query()->whereKey($open->id)->update(['table_id' => $table->id]);
    expect($port->anyOpenOrderUsesTables([(string) $table->id]))->toBeTrue();

    // `closed` KHÔNG nằm trong OPEN_STATUSES: một bàn có đơn đã đóng thì xoá được.
    CustomerOrder::query()->whereKey($open->id)->update(['status' => 'closed']);
    expect($port->anyOpenOrderUsesTables([(string) $table->id]))->toBeFalse();
});

it('TableStatusJournal ghi đúng một dòng append-only', function () {
    $zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $table = Table::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'zone_id' => $zone->id,
    ]);
    $userId = (string) Str::uuid();

    app(TableStatusJournal::class)->record((string) $table->id, 'free', 'occupied', $userId, 'ghi chú');

    // `from_status` / `to_status` cast sang enum khi đọc lại — so ở ->value.
    $row = TableStatusChange::query()->where('table_id', $table->id)->sole();
    expect($row->from_status->value)->toBe('free')
        ->and($row->to_status->value)->toBe('occupied')
        ->and((string) $row->changed_by_id)->toBe($userId)
        ->and($row->note)->toBe('ghi chú')
        ->and($row->changed_at)->not->toBeNull();
});

it('OrderTaxBreakdownReads CHUYỂN TIẾP, không cộng lại theo cách riêng', function () {
    $order = order962($this);
    $ids = [(string) $order->id];

    // Cộng riêng theo cách khác — làm tròn lại từng dòng, hay `tax = taxable ×
    // rate` — cho ra một con số KHÁC với hoá đơn đã in, và đó là báo cáo thuế.
    expect(app(OrderTaxBreakdownReads::class)->forOrders($ids))
        ->toBe((new OrderTaxBreakdownAggregator)->forOrders($ids));

    expect(app(OrderTaxBreakdownReads::class)->forOrders([])['by_rate'])->toBe([]);
});
