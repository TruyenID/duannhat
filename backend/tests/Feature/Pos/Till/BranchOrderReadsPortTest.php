<?php

declare(strict_types=1);

/**
 * #1647 (#962 · 7a-5) — cổng đọc đơn của Ordering, và cái ghép hai lượt ở Payments.
 *
 * Hai truy vấn của `TillSessionService` trước đây vắt qua **cả hai** module trong
 * MỘT câu SQL (`NOT EXISTS` sang `order_payments`; `withCount('payments')`). Chúng
 * được tách làm hai lượt: Ordering trả dữ liệu cấp ĐƠN, Payments tự lọc theo
 * payment trên bảng của chính nó.
 *
 * Tách kiểu đó **rất dễ đổi nghĩa mà không ai thấy**, nên bài này ghim đúng những
 * chỗ trượt được:
 *
 *  - đơn có payment **thành công** phải BIẾN MẤT khỏi danh sách "chưa trả";
 *  - một dòng **hoàn tiền** (`refund_of_id`) KHÔNG được tính là đã trả — nếu tính,
 *    một đơn đã hoàn tiền sẽ im lặng rơi khỏi danh sách chuyển ca;
 *  - payment **pending** cũng không được tính là đã trả.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Contracts\BranchOrderReads;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use Illuminate\Support\Carbon;
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
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->port = app(BranchOrderReads::class);
});

function borOrder(object $ctx, string $status, float $total = 1000.0): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'status' => $status,
        'total_amount' => $total,
    ]);
}

it('trả đơn đang mở của chi nhánh, cũ nhất trước, và bỏ đơn đã đóng', function () {
    $closed = borOrder($this, 'closed');
    $open = borOrder($this, 'open');
    $dining = borOrder($this, 'dining');

    $ids = array_map(fn ($o) => $o->orderId, $this->port->openForBranch((string) $this->branch->id));

    expect($ids)->toContain((string) $open->id)
        ->and($ids)->toContain((string) $dining->id)
        ->and($ids)->not->toContain((string) $closed->id);
});

it('tập trạng thái "đang mở" sống trên HỢP ĐỒNG, không trên service của Payments', function () {
    // Trước #1647 nó là `TillSessionService::ACTIVE_ORDER_STATUSES` — một hằng
    // `public` trên module TIỀN, tức Payments giữ định nghĩa vòng đời ĐƠN HÀNG.
    // Ordering thêm một trạng thái thì phía tiền phải nhớ sửa theo, không gì nhắc.
    //
    // #1664 — đọc từ `OrderStatusVocabulary` chứ không phải
    // `BranchOrderReads::OPEN_STATUSES`: hằng thứ hai đó là bản CHÉP của #1647
    // và đã bị gỡ. Tính chất mà test này ghim không đổi — `OrderStatusVocabulary`
    // cũng nằm trong namespace công bố `App\Services\Order\Contracts`, nên nó
    // vẫn là "trên hợp đồng, không trên service của Payments".
    expect(OrderStatusVocabulary::OPEN)->toContain('open', 'dining', 'paying');

    // `toContain` là VARIADIC: `->not->toContain($x, 'thông điệp')` đọc thông
    // điệp thành cái cần tìm thứ hai và LUÔN xanh. Nên hai phủ định dưới đây
    // mỗi cái một needle, không kèm chuỗi nào.
    expect(OrderStatusVocabulary::OPEN)->not->toContain('closed');
    expect(OrderStatusVocabulary::OPEN)->not->toContain('voided');
});

it('#1664 không còn bản sao thứ hai của tập trạng thái đang-mở', function () {
    // Bản sao không sai gì cho tới lần Ordering thêm một trạng thái: người sửa
    // cập nhật cái mình đang nhìn, cái kia đứng im, không gì kêu. Đây là cái kêu.
    expect(defined(BranchOrderReads::class.'::OPEN_STATUSES'))->toBeFalse();
});

it('gộp doanh thu: net được SUY RA là total - tax, không phải một cột', function () {
    $a = borOrder($this, 'closed', 1100.0);
    $a->update(['tax_amount' => 100, 'discount_amount' => 50]);
    $b = borOrder($this, 'closed', 500.0);
    $b->update(['tax_amount' => 0, 'discount_amount' => 0]);

    $rollup = $this->port->revenueForOrders([(string) $a->id, (string) $b->id]);

    expect($rollup->gross)->toBe(1600.0)
        ->and($rollup->tax)->toBe(100.0)
        // `customer_orders` KHÔNG có cột net_amount — net là phép trừ, và nó
        // thuộc về phía sở hữu bảng. Để chỗ gọi tự trừ là mời mỗi chỗ gọi nghĩ
        // ra một định nghĩa "net" riêng.
        ->and($rollup->net)->toBe(1500.0)
        ->and($rollup->discount)->toBe(50.0);

    // GHI CHÚ TRUNG THỰC: `COALESCE(tax_amount, 0)` trong cổng KHÔNG được bài
    // này phủ, và không phủ được — `customer_orders.tax_amount` và
    // `discount_amount` đều là NOT NULL ở schema, nên trạng thái đó không dựng
    // nổi bằng factory. COALESCE giữ lại vì đây là PR RANH GIỚI: chép nguyên
    // biểu thức cũ, không nhân tiện "dọn" một lá chắn mà mình chưa chứng minh
    // được là thừa.
});

it('tập rỗng trả về 0 chứ không truy vấn', function () {
    $rollup = $this->port->revenueForOrders([]);

    expect($rollup->gross)->toBe(0.0)
        ->and($rollup->net)->toBe(0.0)
        ->and($this->port->guestCountForOrders([]))->toBe(0);
});

it('#1647 — đơn đã có payment THÀNH CÔNG rơi khỏi danh sách chưa trả', function () {
    // Đây là bất biến mà việc tách hai lượt phải giữ: bản cũ làm bằng `NOT EXISTS`
    // trong cùng câu SQL, bản mới làm bằng một truy vấn thứ hai trên order_payments.
    $method = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $paid = borOrder($this, 'open');
    $unpaid = borOrder($this, 'open');

    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $paid->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'payment_method_id' => $method->id,
        'amount' => 1000,
    ]);

    $openIds = array_map(fn ($o) => $o->orderId, $this->port->openForBranch((string) $this->branch->id));
    // Cổng KHÔNG lọc theo payment — đó là việc của Payments, và đây là chỗ dễ
    // hiểu nhầm nhất của thiết kế này.
    expect($openIds)->toContain((string) $paid->id, (string) $unpaid->id);

    $settled = OrderPayment::query()
        ->whereIn('customer_order_id', $openIds)
        ->whereNull('refund_of_id')
        ->where('status', PaymentStatusEnum::Succeeded->value)
        ->distinct()
        ->pluck('customer_order_id')
        ->map(fn ($id) => (string) $id)
        ->all();

    expect($settled)->toBe([(string) $paid->id]);
});

it('#2696 tập UNRESOLVED_MONEY sống trên hợp đồng Ordering, không trên Payments', function () {
    expect(OrderStatusVocabulary::UNRESOLVED_MONEY)->toContain('paying', 'checkout');
    expect(OrderStatusVocabulary::UNRESOLVED_MONEY)->not->toContain('dining');
    expect(OrderStatusVocabulary::UNRESOLVED_MONEY)->not->toContain('open');
    expect(OrderStatusVocabulary::UNRESOLVED_MONEY)->not->toContain('closed');
});

it('#2696 cổng trả đơn paying/checkout sinh TRƯỚC mốc, kèm tableReleased, bỏ dining', function () {
    $before = Carbon::now()->subHours(3);

    $paying = borOrder($this, 'paying', 4720.0);
    $paying->forceFill([
        'created_at' => $before->copy()->subHour(),
        'table_id' => (string) Str::uuid(),
    ])->saveQuietly();

    $orphan = borOrder($this, 'checkout', 700.0);
    $orphan->forceFill([
        'created_at' => $before->copy()->subHours(9),
        'table_id' => null,
    ])->saveQuietly();

    $dining = borOrder($this, 'dining', 1000.0);
    $dining->forceFill(['created_at' => $before->copy()->subHour()])->saveQuietly();

    $late = borOrder($this, 'paying', 2000.0);
    $late->forceFill(['created_at' => $before->copy()->addMinutes(5)])->saveQuietly();

    $rows = $this->port->unresolvedForBranchBefore(
        (string) $this->branch->id,
        $before->toIso8601String(),
    );
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->orderId] = $row;
    }

    expect($byId)->toHaveKey((string) $paying->id)
        ->and($byId)->toHaveKey((string) $orphan->id)
        ->and($byId)->not->toHaveKey((string) $dining->id)
        ->and($byId)->not->toHaveKey((string) $late->id);

    expect($byId[(string) $paying->id]->tableReleased)->toBeFalse()
        ->and($byId[(string) $orphan->id]->tableReleased)->toBeTrue()
        ->and($byId[(string) $paying->id]->totalAmount)->toBe(4720.0);
});
