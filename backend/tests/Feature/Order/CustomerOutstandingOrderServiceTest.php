<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Order\Contracts\CustomerOrderPresence;
use App\Services\Order\Contracts\PartPaidOrderReads;
use App\Services\Order\CustomerOutstandingOrderService;
use Illuminate\Support\Str;

/**
 * #1596 — hai câu hỏi VỀ ĐƠN HÀNG mà `CustomerService` từng tự trả lời.
 *
 * Luật "đơn nào là nợ" nằm trong một docblock dài của bản cũ và **không có test
 * nào ghim**: `paying` VÀ `paid_amount < total_amount`; `checkout` KHÔNG tính
 * (chưa thu đồng nào — là vé đang mở, không phải nợ). Nhầm chỗ này thì màn hình
 * khách hiện nợ sai, và không có gì đỏ lên.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->customer = Customer::factory()->create(['organization_id' => $this->orgId]);
    $this->service = app(CustomerOutstandingOrderService::class);
    $this->presence = app(CustomerOrderPresence::class);

    $this->order = function (array $attributes): CustomerOrder {
        return CustomerOrder::create(array_merge([
            'order_code' => 'O-'.Str::random(6),
            'order_type' => 'spot',
            'status' => 'paying',
            'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
            'tax_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0,
            'opened_at' => now(),
            'checkout_at' => now(),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ], $attributes));
    };
});

it('đơn `paying` trả chưa đủ → là nợ', function () {
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 400]);

    expect($this->service->listOutstanding((string) $this->customer->id, $this->orgId))->toHaveCount(1);
});

it('đơn `paying` đã trả ĐỦ → không phải nợ', function () {
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 1000]);

    expect($this->service->listOutstanding((string) $this->customer->id, $this->orgId))->toHaveCount(0);
});

/**
 * Ca dễ nhầm nhất, và bản cũ phải viết hẳn một đoạn để giải thích: `checkout`
 * là vé đang mở chưa thu đồng nào — **không** phải nợ. Nới trạng thái ra là
 * mọi bàn đang ăn dở đều hiện thành khách nợ tiền.
 */
it('đơn `checkout` KHÔNG phải nợ dù chưa trả đồng nào', function () {
    ($this->order)(['status' => 'checkout', 'total_amount' => 1000, 'paid_amount' => 0]);

    expect($this->service->listOutstanding((string) $this->customer->id, $this->orgId))->toHaveCount(0);
});

it('lọc theo chi nhánh khi được yêu cầu', function () {
    ($this->order)(['paid_amount' => 100]);
    ($this->order)(['paid_amount' => 100, 'branch_id' => $this->otherBranch->id]);

    expect($this->service->listOutstanding((string) $this->customer->id, $this->orgId))->toHaveCount(2)
        ->and($this->service->listOutstanding((string) $this->customer->id, $this->orgId, (string) $this->branch->id))
        ->toHaveCount(1);
});

it('tổng nợ = Σ(total − paid), dạng chuỗi hai chữ số thập phân', function () {
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 400]);
    ($this->order)(['total_amount' => 500, 'paid_amount' => 0]);

    expect($this->service->outstandingTotal((string) $this->customer->id, $this->orgId))->toBe('1100.00');
});

it('không nợ gì → "0.00", không phải 0', function () {
    expect($this->service->outstandingTotal((string) $this->customer->id, $this->orgId))->toBe('0.00');
});

it('KHÔNG lẫn tổ chức khác', function () {
    ($this->order)(['paid_amount' => 100]);

    expect($this->service->listOutstanding((string) $this->customer->id, (string) Str::uuid()))->toHaveCount(0);
});

// ─── #1992: MỘT định nghĩa, hai đường đọc ───────────────────────────────────

/**
 * Đây là bài test mà #1992 tồn tại vì nó.
 *
 * "Đơn trả chưa đủ" từng được viết tay ở hai nơi — service này (Eloquent, theo
 * khách) và `DebtController::partPaid()` (`DB::table` thô, theo chi nhánh). Hai
 * bản chưa lệch về tiền, nhưng đã lệch về cách lọc đơn xoá mềm và cách sắp xếp.
 * Giờ cả hai đọc chung `CustomerOrder::partPaid()`, và bài này ghim sự đồng ý
 * đó: sửa một bên mà quên bên kia thì nó đỏ.
 *
 * Bốn ca đi kèm là bốn ranh giới của định nghĩa, không phải bốn ca ngẫu nhiên —
 * đặc biệt `checkout`, chỗ dễ nhầm nhất, phải bị LOẠI ở cả hai đường.
 */
it('hai đường đọc trả về CÙNG tập đơn', function () {
    $partPaid = ($this->order)(['total_amount' => 1000, 'paid_amount' => 400]);
    $alsoPartPaid = ($this->order)(['total_amount' => 500, 'paid_amount' => 1]);
    // Ba ca phải bị loại, mỗi ca một lý do khác nhau.
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 1000]);
    ($this->order)(['status' => 'checkout', 'total_amount' => 1000, 'paid_amount' => 0]);
    ($this->order)(['status' => 'closed', 'total_amount' => 1000, 'paid_amount' => 400]);

    $fromService = $this->service
        ->listOutstanding((string) $this->customer->id, $this->orgId, (string) $this->branch->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $fromPort = collect(app(PartPaidOrderReads::class)->forBranch((string) $this->branch->id))
        ->where('customerId', (string) $this->customer->id)
        ->pluck('orderId')
        ->sort()
        ->values()
        ->all();

    $expected = collect([$partPaid->id, $alsoPartPaid->id])->sort()->values()->all();

    expect($fromService)->toBe($expected)
        // Bằng NHAU là điều bài test này ghim; hai bên cùng bằng `$expected`
        // mới nói lên rằng cả hai đọc đúng định nghĩa, không phải cùng sai.
        ->and($fromPort)->toBe($expected);
});

it('đơn xoá mềm rụng khỏi CẢ HAI đường', function () {
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 400]);
    $deleted = ($this->order)(['total_amount' => 2000, 'paid_amount' => 100]);
    $deleted->delete();

    $fromService = $this->service
        ->listOutstanding((string) $this->customer->id, $this->orgId, (string) $this->branch->id)
        ->pluck('id')
        ->all();
    $fromPort = collect(app(PartPaidOrderReads::class)->forBranch((string) $this->branch->id))
        ->pluck('orderId')
        ->all();

    expect($fromService)->not->toContain($deleted->id)
        ->and($fromPort)->not->toContain($deleted->id)
        ->and($fromService)->toHaveCount(1)
        ->and($fromPort)->toHaveCount(1);
});

/**
 * `unpaidAmount` tính sẵn ở cổng, và phải khớp với tổng mà service trả ra —
 * hai chỗ tự trừ là hai chỗ có thể trừ khác nhau.
 */
it('tổng của hai đường khớp nhau', function () {
    ($this->order)(['total_amount' => 1000, 'paid_amount' => 400]);
    ($this->order)(['total_amount' => 500, 'paid_amount' => 0]);

    $portTotal = collect(app(PartPaidOrderReads::class)->forBranch((string) $this->branch->id))
        ->sum(fn ($o) => $o->unpaidAmount);

    expect(number_format($portTotal, 2, '.', ''))
        ->toBe($this->service->outstandingTotal((string) $this->customer->id, $this->orgId, (string) $this->branch->id));
});

// ─── CustomerOrderPresence (chặn xoá khách còn đơn dở, plan-042) ─────────────

it('cổng hiện diện: có binding thật', function () {
    expect($this->presence)->toBeInstanceOf(CustomerOrderPresence::class);
});

it('khách có đơn CHƯA ĐÓNG → true', function () {
    ($this->order)(['status' => 'open']);

    expect($this->presence->hasOpenOrder((string) $this->customer->id))->toBeTrue();
});

it('khách chỉ có đơn ĐÃ ĐÓNG → false', function () {
    ($this->order)(['status' => 'closed']);

    expect($this->presence->hasOpenOrder((string) $this->customer->id))->toBeFalse();
});

it('đơn của khách KHÁC không chặn', function () {
    $other = Customer::factory()->create(['organization_id' => $this->orgId]);
    ($this->order)(['status' => 'open', 'customer_id' => $other->id]);

    expect($this->presence->hasOpenOrder((string) $this->customer->id))->toBeFalse();
});
