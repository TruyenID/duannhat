<?php

declare(strict_types=1);

/**
 * #1662 (#962 · 7a-6) — đường GHI đơn thôi cầm model/service của module khác.
 *
 * Ba call site đã đổi sang cổng. Bài này ghim hai thứ khác nhau:
 *
 *  1. **Ranh giới** — trait không được import lại `OrderPayment`/`TillSessionService`.
 *  2. **Số tiền** — cổng phải trả đúng như truy vấn cũ, kể cả những điều kiện trông
 *     như thừa. Đây là phần dễ mất nhất: một PR "dọn dẹp" sau này bỏ
 *     `whereNull('metadata->settles_payment_id')` hay gộp thiếu `refunded` thì ranh
 *     giới vẫn sạch mà tiền thì sai.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Contracts\OpenTillSessionLookup;
use App\Services\Order\Contracts\OrderPaymentLedgerReads;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->ledger = app(OrderPaymentLedgerReads::class);
});

function op1662(object $ctx, CustomerOrder $order, float $amount, string $status, float $tip = 0.0, ?array $metadata = null): OrderPayment
{
    static $method = null;
    $method ??= PaymentMethod::factory()->cash()->create(['organization_id' => $ctx->orgId]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'tip_amount' => $tip,
        'status' => $status,
        'metadata' => $metadata,
    ]);
}

function order1662(object $ctx): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'status' => 'open',
    ]);
}

it('cả hai cổng đều bind được — cổng không có hiện thực là cổng trang trí', function () {
    // #1544 đã trả giá đúng chỗ này: `OrderQueryPort` từng tồn tại mà không ai bind,
    // nên mọi caller tin nó đều ăn container exception và quay về import model.
    expect(app(OpenTillSessionLookup::class))->toBeInstanceOf(OpenTillSessionLookup::class)
        ->and(app(OrderPaymentLedgerReads::class))->toBeInstanceOf(OrderPaymentLedgerReads::class);
});

it('trait ghi đơn KHÔNG còn import OrderPayment hay TillSessionService', function () {
    $src = (string) file_get_contents(app_path('Services/Order/Internal/Concerns/WritesCustomerOrders.php'));

    expect($src)->not->toContain('use App\Models\OrderPayment;')
        ->and($src)->not->toContain('use App\Services\Pos\TillSessionService;');
});

it('tổng đệm gộp CẢ succeeded lẫn refunded — bỏ refunded là đổi số tiền hiển thị', function () {
    $order = order1662($this);
    op1662($this, $order, 1000.0, PaymentStatusEnum::Succeeded->value, tip: 100.0);
    op1662($this, $order, 500.0, PaymentStatusEnum::Refunded->value, tip: 50.0);
    op1662($this, $order, 700.0, PaymentStatusEnum::Pending->value, tip: 70.0);

    $totals = $this->ledger->cachedTotalsForOrder((string) $order->id);

    // pending KHÔNG được tính; refunded CÓ (dòng gốc vẫn giữ +X).
    expect($totals->totalPaid)->toBe(1500.0)
        ->and($totals->totalTip)->toBe(150.0);
});

it('tổng đệm BỎ dòng settlement — điều kiện này trông thừa nhưng không thừa', function () {
    $order = order1662($this);
    op1662($this, $order, 1000.0, PaymentStatusEnum::Succeeded->value);
    op1662($this, $order, 999.0, PaymentStatusEnum::Succeeded->value, metadata: ['settles_payment_id' => (string) Str::uuid()]);

    expect($this->ledger->cachedTotalsForOrder((string) $order->id)->totalPaid)->toBe(1000.0);
});

it('đơn không có dòng tiền nào trả về 0, không phải null', function () {
    $totals = $this->ledger->cachedTotalsForOrder((string) order1662($this)->id);

    expect($totals->totalPaid)->toBe(0.0)->and($totals->totalTip)->toBe(0.0);
});

it('#816 — net collected của đơn ĐÃ HOÀN phải về 0, không phải âm', function () {
    // Đây là bẫy mà cổng tuyệt đối không được tự tính lại. Ghi sổ một lần hoàn là:
    // dòng gốc +X giữ nguyên và chuyển `refunded`, cộng thêm dòng -X `succeeded`.
    // Ai gộp riêng `succeeded` sẽ ra -X: một khoản tín dụng ma huỷ mất tiền mặt
    // thật của người khác trên hoá đơn chia đôi.
    $order = order1662($this);
    $original = op1662($this, $order, 1000.0, PaymentStatusEnum::Refunded->value);
    op1662($this, $order, -1000.0, PaymentStatusEnum::Succeeded->value, metadata: ['refund_of_id' => (string) $original->id]);

    expect($this->ledger->netCollectedForOrder((string) $order->id))->toBe(0.0);
});

it('net collected khớp ĐÚNG định nghĩa gốc trên model — cổng chỉ chuyển tiếp', function () {
    $order = order1662($this);
    op1662($this, $order, 1200.0, PaymentStatusEnum::Succeeded->value);
    op1662($this, $order, 300.0, PaymentStatusEnum::Refunded->value);

    expect($this->ledger->netCollectedForOrder((string) $order->id))
        ->toBe(OrderPayment::netCollectedForOrder((string) $order->id));
});

it('không có ca nào mở thì trả null — đó là trạng thái HỢP LỆ, không phải lỗi', function () {
    // Đơn tạo ngoài giờ mở ca (QR khách tự đặt, kiosk) không thuộc ca nào; plan-044 R2
    // gọi là "gap payment" và đối soát tay ở lần mở ca sau.
    expect(app(OpenTillSessionLookup::class)->openSessionIdForBranch((string) $this->branch->id))->toBeNull();
});
