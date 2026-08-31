<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `payments:audit-overcollection` — đơn nào đã thu VƯỢT tổng của nó (#1994).
 *
 * Sinh ra từ #1988: một đơn ¥715 bị thu ¥2.860. Chuỗi lỗi đó không để lại dấu
 * hiệu nào trên đơn — nó chỉ lộ khi cộng tiền đã thu rồi so với tổng.
 *
 * Mỗi test dưới đây ghim một cách mà lệnh có thể NÓI DỐI, chứ không ghim đường
 * chính. Đường chính chỉ cần một test; những cách nói dối mới là thứ đắt.
 */
beforeEach(function () {
    $id = (string) Str::uuid();
    $this->org = Organization::factory()->create(['id' => $id, 'console_organization_id' => $id]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $id,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
});

function makeOverCollectedOrder(object $ctx, float $total): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->org->id,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'total_amount' => $total,
    ]);
}

function pay(object $ctx, CustomerOrder $order, float $amount, string $status = 'succeeded', array $extra = []): OrderPayment
{
    return OrderPayment::factory()->create(array_merge([
        'organization_id' => $ctx->org->id,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'customer_order_id' => $order->id,
        'amount' => $amount,
        'status' => $status,
    ], $extra));
}

function auditJson(array $opts = []): array
{
    Artisan::call('payments:audit-overcollection', array_merge(['--json' => true], $opts));

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

it('bắt đúng kịch bản #1988: quẹt bốn lần cho một đơn', function () {
    $order = makeOverCollectedOrder($this, 715);
    foreach ([715, 715, 715, 715] as $a) {
        pay($this, $order, $a);
    }

    $out = auditJson();

    expect($out['finding_count'])->toBe(1)
        ->and($out['orders'][0]['order_id'])->toBe((string) $order->id)
        // Ép kiểu: `json_encode` viết 2860.0 thành `2860`, decode ra `int` —
        // so `toBe(2860.0)` sẽ đỏ vì KIỂU chứ không vì giá trị, và người đọc
        // tưởng lệnh cộng sai.
        ->and((float) $out['orders'][0]['collected'])->toBe(2860.0)
        ->and((float) $out['orders'][0]['over_by'])->toBe(2145.0)
        ->and($out['orders'][0]['payment_count'])->toBe(4);
});

it('KHÔNG báo một đơn trả đúng đủ', function () {
    $order = makeOverCollectedOrder($this, 715);
    pay($this, $order, 715);

    expect(auditJson()['finding_count'])->toBe(0);
});

it('KHÔNG báo một đơn đã HOÀN phần thừa — hàng hoàn mang số âm và phải được cộng', function () {
    // Đây là cách dễ nhất để lệnh nói dối: chỉ cộng `succeeded` thì một đơn đã
    // được xử lý xong vẫn bị báo là sự cố, và người ta học cách phớt lờ nó.
    $order = makeOverCollectedOrder($this, 715);
    pay($this, $order, 715);
    pay($this, $order, 715);
    pay($this, $order, -715, 'refunded');

    expect(auditJson()['finding_count'])->toBe(0);
});

it('KHÔNG đếm hai lần một payment settle cho payment khác', function () {
    // Hàng mang `metadata->settles_payment_id` thanh toán cho một payment khác.
    // Cộng nó vào là đếm hai lần cùng một số tiền — và sẽ dựng ra một "sự cố"
    // không tồn tại.
    $order = makeOverCollectedOrder($this, 715);
    $first = pay($this, $order, 715);
    pay($this, $order, 715, 'succeeded', ['metadata' => ['settles_payment_id' => (string) $first->id]]);

    expect(auditJson()['finding_count'])->toBe(0);
});

it('bỏ qua payment THẤT BẠI — tiền chưa rời tài khoản khách', function () {
    $order = makeOverCollectedOrder($this, 715);
    pay($this, $order, 715);
    pay($this, $order, 715, 'failed');

    expect(auditJson()['finding_count'])->toBe(0);
});

it('không coi sai số dưới một BƯỚC TIỀN TỆ là sự cố', function () {
    // JPY bước 1. So bằng `>` trần thì mọi sai số dấu phẩy động thành "sự cố
    // tiền" — cách nhanh nhất để một cảnh báo bị tắt tiếng.
    $order = makeOverCollectedOrder($this, 715);
    pay($this, $order, 715.4);

    expect(auditJson()['finding_count'])->toBe(0);
});

it('lọc theo chi nhánh và theo ngày', function () {
    $a = makeOverCollectedOrder($this, 100);
    pay($this, $a, 300);

    expect(auditJson(['--branch' => (string) $this->branch->id])['finding_count'])->toBe(1)
        ->and(auditJson(['--branch' => (string) Str::uuid()])['finding_count'])->toBe(0)
        ->and(auditJson(['--since' => now()->addDay()->toDateString()])['finding_count'])->toBe(0);
});

it('--strict thoát khác 0 khi có phát hiện, và bằng 0 khi sạch', function () {
    // Không có cái này thì không cắm được vào cron/cảnh báo: một sự cố tiền sẽ
    // in ra rồi trôi qua trong log.
    $order = makeOverCollectedOrder($this, 100);
    pay($this, $order, 300);

    expect(Artisan::call('payments:audit-overcollection', ['--strict' => true]))->toBe(1);

    OrderPayment::query()->delete();

    expect(Artisan::call('payments:audit-overcollection', ['--strict' => true]))->toBe(0);
});
