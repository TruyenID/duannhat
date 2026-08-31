<?php

/*
 * #821 mục A — các guard tiền cho ghi nợ / tất toán nợ.
 *
 * Trước đây "ghi nợ" là đường một chiều VÀ khai thác được:
 *   A4 — không so số tiền  → 30.000 xoá sạch khoản nợ 5.000.000
 *   A5 — không lọc status  → thẻ bị từ chối làm khoản nợ biến mất vĩnh viễn
 *   A6 — refund lọt aggregate → /debts báo nợ ÂM (shop nợ ngược khách)
 *   A7 — order_payments không có customer_id → guard sai-khách không bao giờ chạy
 *
 * Mỗi test dưới đây fail trên code cũ.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'money-shop',
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    $this->other = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->debtMethod = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'code' => 'debt',
        'type' => 'on_account',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);
    $this->cashMethod = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'code' => 'cash',
        'type' => 'cash',
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);
});

function moneyOrder(int $total, ?string $customerId = null, string $status = 'checkout'): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->shop->id,
        'customer_id' => $customerId ?? test()->customer->id,
        'status' => $status,
        'total_amount' => $total,
    ]);
}

/** Ghi nợ đúng như nút "Ghi nợ toàn bộ" của POS: phủ hết số còn lại, đơn đóng. */
function moneyDebt(int $amount, ?string $customerId = null, string $status = 'succeeded'): string
{
    $order = moneyOrder($amount, $customerId);
    $id = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $id,
        'payment_code' => 'PAY-'.Str::upper(Str::random(8)),
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->debtMethod->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'amount' => $amount,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $order->update(['paid_amount' => $amount, 'status' => 'closed']);

    return $id;
}

function postSettle(CustomerOrder $order, string $debtId, int $amount)
{
    return test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments", [
            'payment_method_id' => test()->cashMethod->id,
            'amount' => $amount,
            'tendered_amount' => $amount,
            'metadata' => ['settles_payment_id' => $debtId],
        ]);
}

function openDebtTotal(): string
{
    $rows = test()->actingAs(test()->manager)
        ->getJson('/api/v1/shops/'.test()->shop->slug.'/debts')
        ->json('data');

    return (string) collect($rows)->sum(fn ($r) => (float) $r['open_debt_total']);
}

// =========================================================================
//  A4 — số tiền tất toán phải khớp khoản nợ
// =========================================================================

it('A4: từ chối tất toán THIẾU — 30.000 không xoá được khoản nợ 5.000.000', function () {
    $debtId = moneyDebt(5_000_000);
    $coffee = moneyOrder(30_000);

    postSettle($coffee, $debtId, 30_000)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_amount_mismatch']);

    // Nợ còn nguyên. Đây mới là điều quan trọng: 4.970.000 không bốc hơi.
    expect(openDebtTotal())->toBe('5000000');
});

it('A4: CHO PHÉP tất toán đủ — kể cả khi khoản nợ lớn hơn đơn đang mở', function () {
    $debtId = moneyDebt(5_000_000);

    // Khách quay lại mua cà phê 30.000 và trả nốt 5 triệu nợ cũ. Tiền thu nợ
    // KHÔNG phải tiền trả cho ly cà phê, nên guard overpay không được chặn —
    // trước đây nó chặn, khiến việc trả đủ là bất khả thi.
    $coffee = moneyOrder(30_000);

    postSettle($coffee, $debtId, 5_000_000)->assertCreated();

    expect(openDebtTotal())->toBe('0');

    // Và ly cà phê vẫn CHƯA được trả: 5 triệu kia là tiền nợ cũ, không phải
    // tiền cà phê. Nếu cộng vào sẽ đóng đơn cà phê mà khách chưa hề trả.
    expect((float) $coffee->fresh()->paid_amount)->toBe(0.0);
});

// =========================================================================
//  A5 — tất toán thất bại không được giết khoản nợ
// =========================================================================

it('A5: settlement FAILED không xoá nợ, và cho phép thử lại', function () {
    $debtId = moneyDebt(90_000);
    $order = moneyOrder(90_000);

    // Quẹt thẻ hỏng → row failed.
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => 'PAY-'.Str::upper(Str::random(8)),
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cashMethod->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => 90_000,
        'status' => 'failed',
        'metadata' => json_encode(['settles_payment_id' => $debtId]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Nợ vẫn phải còn đó (trước đây: biến mất vĩnh viễn).
    expect(openDebtTotal())->toBe('90000');

    // Và thử lại phải được (trước đây: settles_already_settled chặn cứng).
    postSettle(moneyOrder(90_000), $debtId, 90_000)->assertCreated();

    expect(openDebtTotal())->toBe('0');
});

// =========================================================================
//  A6 — refund một khoản nợ không được tạo "nợ âm"
// =========================================================================

it('A6: khoản nợ đã refund không quay lại /debts dưới dạng số ÂM', function () {
    $debtId = moneyDebt(5_000);
    $debtOrder = CustomerOrder::query()->whereKey(
        DB::table('order_payments')->where('id', $debtId)->value('customer_order_id')
    )->first();

    // refund() ghi row đảo với CÙNG payment_method_id (vẫn type=on_account).
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => 'PAY-'.Str::upper(Str::random(8)),
        'customer_order_id' => $debtOrder->id,
        'payment_method_id' => $this->debtMethod->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => -5_000,
        'status' => 'succeeded',
        'refund_of_id' => $debtId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('order_payments')->where('id', $debtId)->update(['status' => 'refunded']);

    // Cả gốc (refunded) lẫn row đảo (-5.000) đều phải biến mất khỏi aggregate.
    // Trước đây: open_debt_total = "-5000" — shop nợ ngược khách.
    expect(openDebtTotal())->toBe('0');
});

// =========================================================================
//  A7 — không được tất toán nợ của khách KHÁC
// =========================================================================

it('A7: từ chối settlement trỏ vào khoản nợ của khách khác', function () {
    $othersDebt = moneyDebt(90_000, $this->other->id);

    // Đơn của khách A, nhưng settlement trỏ vào nợ của khách B.
    $mine = moneyOrder(90_000, $this->customer->id);

    postSettle($mine, $othersDebt, 90_000)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_wrong_customer']);

    expect(openDebtTotal())->toBe('90000');
});

// =========================================================================
//  A11 — cursor phải thật sự phân trang
// =========================================================================

it('A11: cursor thật sự tiến trang — trước đây trang 2 lặp lại trang 1', function () {
    // 3 khách, mỗi người 1 khoản nợ.
    foreach ([$this->customer->id, $this->other->id] as $cid) {
        moneyDebt(10_000, $cid);
    }
    $third = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    moneyDebt(10_000, $third->id);

    $page1 = $this->actingAs($this->manager)
        ->getJson('/api/v1/shops/'.$this->shop->slug.'/debts?limit=2')
        ->assertOk()
        ->json();

    expect($page1['data'])->toHaveCount(2)
        ->and($page1['next_cursor'])->not->toBeNull();

    $page2 = $this->actingAs($this->manager)
        ->getJson('/api/v1/shops/'.$this->shop->slug.'/debts?limit=2&cursor='.$page1['next_cursor'])
        ->assertOk()
        ->json();

    // Trước đây cursor bị bỏ qua hoàn toàn → trang 2 trả về ĐÚNG 2 khách của
    // trang 1, nên khách thứ 3 không bao giờ hiện ra và tổng công nợ manager
    // nhìn thấy bị cắt cụt.
    $ids1 = collect($page1['data'])->pluck('customer_id')->all();
    $ids2 = collect($page2['data'])->pluck('customer_id')->all();

    expect($page2['data'])->toHaveCount(1)
        ->and(array_intersect($ids1, $ids2))->toBe([]);
});
