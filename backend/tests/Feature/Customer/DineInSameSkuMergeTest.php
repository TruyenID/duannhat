<?php

/**
 * #2522 — WHY 人形町店 C-6 got four lines and four kitchen slips for one dish.
 *
 * The complaint was "1 person, many orders". The investigation established
 * there was ONE order with four lines of the identical SKU (no toppings, qty 1
 * each) and four `kitchen` print jobs, and concluded the customer must have
 * confirmed four times.
 *
 * That conclusion skipped a question: the system MERGES same-SKU lines. Four
 * identical bowls on one order should have become one line of qty 4, and one
 * kitchen slip. They did not, and that is the actual defect.
 *
 * The chain, all three links in this repo:
 *
 *   WritesCustomerOrders:1578  a new line is born with resolveDefaultItemStatus()
 *   WritesCustomerOrders:1546  BR-OI06 merges ONLY into a line whose status is `pending`
 *   shop_order_settings        ningyocho sets default_order_item_status = `served`
 *
 * So at that shop no line is ever `pending`, the merge can never fire, every
 * add creates a new line, and every new line fires its own kitchen ticket. The
 * behaviour scales with how often the customer orders, which is exactly the
 * "the busier it gets, the worse it looks" shape of the complaint.
 *
 * These tests hold the two halves side by side, because the defect is invisible
 * unless you compare them: the same requests, the same cart, one shop setting
 * apart.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\PrintJob;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Printing\Enums\PrintJobKind;

uses()->group('customer');

beforeEach(function () {
    $orgId = '00000000-0000-0000-0000-000000000001';

    $this->orgId = $orgId;
    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->zone = Zone::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->table = Table::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'merge-probe-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->sku = ProductSku::factory()->create();
});

/** Order the same single bowl, the way the confirm screen does. */
function orderOneBowl(): void
{
    test()->postJson('/api/v1/customer/tables/merge-probe-token/orders', [
        'items' => [['product_sku_id' => test()->sku->id, 'quantity' => 1]],
    ]);
}

function setDefaultItemStatus(string $status): void
{
    ShopOrderSetting::factory()->create([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
        'default_order_item_status' => $status,
    ]);
}

it('merges repeat orders of the same dish into ONE line at a default shop', function () {
    // The behaviour BR-OI06 promises, and the baseline the next test is read
    // against. Four confirms of the same bowl → one line, qty 4.
    setDefaultItemStatus(OrderItemStatusEnum::Pending->value);

    orderOneBowl();
    orderOneBowl();
    orderOneBowl();
    orderOneBowl();

    $order = CustomerOrder::query()->sole();

    expect($order->items()->count())->toBe(1)
        ->and((float) $order->items()->sole()->quantity)->toBe(4.0);
});

it('FIX #2522: a born-served shop merges repeat orders (#2623: không còn cửa sổ)', function () {
    // 人形町店's setting. Trước bản vá, đúng bốn request này ra bốn dòng và bốn
    // phiếu bếp, và quán đọc thành "một khách đặt bốn lần".
    setDefaultItemStatus(OrderItemStatusEnum::Served->value);

    orderOneBowl();
    orderOneBowl();
    orderOneBowl();
    orderOneBowl();

    $order = CustomerOrder::query()->sole();

    expect($order->items()->count())->toBe(1)
        ->and((float) $order->items()->sole()->quantity)->toBe(4.0);
});

it('#2623 — vẫn gộp sau HAI MƯƠI PHÚT: cửa sổ 120s đã chết', function () {
    // Bài này ĐẢO NGƯỢC bài cũ "stops merging once the window has closed".
    //
    // Cửa sổ là XẤP XỈ cho "bếp đã biết phần này chưa". Câu hỏi đó nay trả lời
    // chính xác bằng `printed_quantity`: máy trạm pull DOWN thấy `quantity`
    // tăng, `printed_quantity` KHÔNG bị upsert đụng tới, nên nó in đúng phần
    // chênh. Một khách gọi lại đúng món ở phút thứ hai mươi là chuyện thật
    // (#2551 nêu đích danh), và trước đây nó ra dòng thứ hai + phiếu thứ hai.
    setDefaultItemStatus(OrderItemStatusEnum::Served->value);

    orderOneBowl();

    $order = CustomerOrder::query()->sole();
    $order->items()->sole()->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();

    orderOneBowl();

    expect($order->items()->count())->toBe(1)
        ->and((float) $order->items()->sole()->quantity)->toBe(2.0);
});

it('#2623 — vẫn gộp SAU KHI phiếu bếp đã ra', function () {
    // Bài này đảo ngược bài cũ "stops merging the moment a kitchen ticket goes
    // out". Rào đó hỏi `print_jobs` để đoán xem bếp đã biết gì; nay không cần
    // đoán, và giữ nó lại là làm Cloud dè dặt ở đúng nơi đã đủ thông tin.
    //
    // An toàn KHÔNG đến từ tầng này mà từ chuỗi bên máy trạm — nơi DUY NHẤT
    // phát phiếu bếp (Cloud không có emitter nào; hàng `print_jobs` kind
    // `kitchen` chỉ tới qua `POST /workstation/print-jobs`).
    setDefaultItemStatus(OrderItemStatusEnum::Served->value);

    orderOneBowl();
    $order = CustomerOrder::query()->sole();

    PrintJob::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'order_id' => $order->id,
        'kind' => PrintJobKind::Kitchen->value,
    ]);

    orderOneBowl();

    expect($order->items()->count())->toBe(1)
        ->and((float) $order->items()->sole()->quantity)->toBe(2.0);
});

it('mẫu số: đường gộp thật sự CHẠY qua endpoint, không phải luôn ra 1 dòng', function () {
    // Không có bài này thì ba bài trên xanh kể cả khi đường tạo dòng hỏng và
    // mọi đơn ra đúng một dòng vì lý do khác. Hai SKU KHÁC nhau phải ra HAI
    // dòng — số 1 ở trên vì thế là một khẳng định, không phải mặc định.
    setDefaultItemStatus(OrderItemStatusEnum::Served->value);

    $other = ProductSku::factory()->create();

    orderOneBowl();
    test()->postJson('/api/v1/customer/tables/merge-probe-token/orders', [
        'items' => [['product_sku_id' => $other->id, 'quantity' => 1]],
    ]);

    expect(CustomerOrder::query()->sole()->items()->count())->toBe(2);
});

it('leaves the pending-shop rule completely alone', function () {
    // Quán born-pending đi đường trả sớm của `resolveMergeWindow()` — byte
    // identical, không phải chỉ tương đương.
    setDefaultItemStatus(OrderItemStatusEnum::Pending->value);

    orderOneBowl();
    orderOneBowl();

    expect(CustomerOrder::query()->sole()->items()->count())->toBe(1);
});
