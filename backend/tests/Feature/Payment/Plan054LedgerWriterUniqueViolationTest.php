<?php

declare(strict_types=1);

/**
 * plan-054 T4.3 — `createWithUniqueCode()` phải phân biệt HAI unique khác nhau
 * trên `order_payments`, vì chỉ một trong hai thử lại được:
 *
 *   - `payment_code`  — dãy số class này tự sinh. Đụng nhau ⇒ bốc số khác, chèn lại.
 *   - `(customer_order_id, idempotency_key)` — chốt chặn của DB chống ghi hai lần
 *     cùng một khoản tiền. plan-054 T4.2 đóng `merchantPaymentId` của PayPay vào
 *     đây, nên nó nổ nghĩa là "khoản này ĐÃ nằm trong sổ".
 *
 * Trước đây vòng lặp bắt `UniqueConstraintViolationException` **trần**: một va
 * chạm idempotency ăn đủ 5 lần chèn lại vào bảng tiền, rồi ném
 * `RuntimeException('Could not generate a unique payment code…')` — báo sai
 * nguyên nhân, và người đọc log đi tìm đúng chỗ không có gì.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
    ]);

    $this->organizationId = $organizationId;
    $this->brand = Brand::factory()->create(['console_organization_id' => $organizationId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $organizationId]);
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
});

/** @return array<string, mixed> */
function plan054LedgerRow(object $ctx, string $idempotencyKey, float $amount = 3000): array
{
    return [
        'amount' => $amount,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
        'reference_no' => $idempotencyKey,
        'idempotency_key' => $idempotencyKey,
        'payment_method_id' => $ctx->method->id,
        'customer_order_id' => $ctx->order->id,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->organizationId,
        'received_by_id' => $ctx->operator->id,
        'note' => 'paypay_qr',
    ];
}

it('ném thẳng lỗi unique khi đụng chốt idempotency, không giả vờ là lỗi payment_code', function () {
    $writer = app(OrderPaymentLedgerWriter::class);
    $mpid = 'tempoqr-'.Str::uuid();

    $writer->createRow(plan054LedgerRow($this, $mpid));

    // Cùng đơn, cùng merchantPaymentId — đây chính là ca "khách quét lại / webhook
    // về hai lần" mà T4.2 dựng chốt DB để chặn.
    expect(fn () => $writer->createRow(plan054LedgerRow($this, $mpid)))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('không thử lại 4 lần nữa khi va chạm là idempotency — chỉ đúng MỘT lần chèn', function () {
    $writer = app(OrderPaymentLedgerWriter::class);
    $mpid = 'tempoqr-'.Str::uuid();

    $writer->createRow(plan054LedgerRow($this, $mpid));

    // Đếm số VÒNG, không đếm INSERT: `DB::listen` chỉ bắn khi query THÀNH CÔNG
    // (`Connection::run()` gọi `logQuery` sau callback, nên insert hỏng không bao
    // giờ tới đó) — đo bằng insert luôn ra 0 dù thử lại mấy lần. Mỗi vòng lại
    // chạy `generateCode()`, và cái SELECT MAX đó thì thành công, nên nó ĐẾM ĐƯỢC.
    $rounds = 0;
    DB::listen(function ($query) use (&$rounds) {
        if (str_contains(strtolower($query->sql), 'max(cast(substring(payment_code')) {
            $rounds++;
        }
    });

    try {
        $writer->createRow(plan054LedgerRow($this, $mpid));
    } catch (UniqueConstraintViolationException) {
        // mong đợi
    }

    // Con số này LÀ hành vi đang được ghim: 5 nghĩa là ta vẫn nện bảng tiền bốn
    // lần thừa cho một khoản đã có trong sổ, rồi báo sai nguyên nhân.
    expect($rounds)->toBe(1);

    // Và sổ vẫn đúng một dòng.
    expect(OrderPayment::query()->where('idempotency_key', $mpid)->count())->toBe(1);
});

it('vẫn thử lại như cũ khi va chạm thật sự là payment_code', function () {
    $writer = app(OrderPaymentLedgerWriter::class);

    $first = $writer->createRow(plan054LedgerRow($this, 'tempoqr-'.Str::uuid()));

    // Chiếm sẵn con số kế tiếp mà generateCode() sắp bốc, để lần chèn sau buộc
    // phải bump offset. Nếu discrimination làm sai chiều (ném thay vì thử lại)
    // thì bài này đỏ.
    $year = now()->year;
    $next = (int) substr((string) $first->payment_code, strlen("PAY-{$year}-")) + 1;

    $squatter = plan054LedgerRow($this, 'tempoqr-'.Str::uuid());
    $squatter['id'] = (string) Str::uuid();
    $squatter['payment_code'] = sprintf('PAY-%d-%04d', $year, $next);
    $squatter['created_at'] = now();
    $squatter['updated_at'] = now();
    DB::table('order_payments')->insert($squatter);

    $third = $writer->createRow(plan054LedgerRow($this, 'tempoqr-'.Str::uuid()));

    expect($third->payment_code)->not->toBe($squatter['payment_code']);
    expect($third->exists)->toBeTrue();
});
