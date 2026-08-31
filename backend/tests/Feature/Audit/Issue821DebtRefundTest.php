<?php

/*
 * #821 mục A — ghi nợ + refund, các lỗ CÒN LẠI sau PR #823.
 *
 *   A4-REGRESSION — PR #823 loại settlement khỏi paid_amount bằng metadata,
 *                   nhưng refund() không copy metadata sang hàng đảo => bản gốc
 *                   bị loại, bản đảo THÌ KHÔNG => paid_amount ÂM.
 *   A5 (còn hở)   — settlement `pending` không chặn settlement thứ hai => thu
 *                   nợ hai lần trên cùng một khoản.
 *   A6 (còn hở)   — refund MỘT PHẦN làm cả khoản nợ biến mất khỏi /debts.
 *
 * Mọi test dưới đây FAIL trên code trước khi fix.
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
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'debt-refund-shop',
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
    ]);

    $this->debtMethod = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'code' => 'debt', 'type' => 'on_account', 'is_auto_confirm' => true, 'requires_tendered' => false,
    ]);
    $this->cash = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'code' => 'cash', 'type' => 'cash', 'is_auto_confirm' => true, 'requires_tendered' => true,
    ]);
    // Máy POS quẹt thẻ: KHÔNG auto-confirm -> payment nằm ở `pending`.
    $this->terminal = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'code' => 'card', 'type' => 'card', 'is_auto_confirm' => false, 'requires_tendered' => false,
    ]);
});

/** Đơn đang mở, chưa thu đồng nào — sẵn sàng nhận payment (kể cả payment tất toán nợ). */
function drOrder(int $total, ?string $customerId = null): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->shop->id,
        'customer_id' => $customerId ?? test()->customer->id,
        'status' => 'checkout',
        'total_amount' => $total,
        'paid_amount' => 0,
    ]);
}

/** Một khoản ghi nợ: đơn riêng đã đóng + payment on_account. Trả về [order, paymentId]. */
function drDebt(int $amount, ?string $customerId = null): array
{
    $order = drOrder($amount, $customerId);
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
        'status' => 'succeeded',
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Ghi nợ = đơn đã đóng, tiền "đã thu" dưới dạng công nợ.
    $order->update(['paid_amount' => $amount, 'status' => 'closed']);

    return [$order->fresh(), $id];
}

function drSettle(CustomerOrder $order, string $debtId, int $amount, ?string $methodId = null)
{
    return test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments", [
            'payment_method_id' => $methodId ?? test()->cash->id,
            'amount' => $amount,
            'tendered_amount' => $amount,
            'metadata' => ['settles_payment_id' => $debtId],
        ]);
}

function drRefund(CustomerOrder $order, string $paymentId, ?int $amount = null)
{
    return test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments/{$paymentId}/refund",
            $amount !== null ? ['amount' => $amount] : []);
}

function drDebtTotal(): float
{
    $rows = test()->actingAs(test()->manager)
        ->getJson('/api/v1/shops/'.test()->shop->slug.'/debts')
        ->json('data');

    return (float) collect($rows)->sum(fn ($r) => (float) $r['open_debt_total']);
}

// =========================================================================
//  A4-REGRESSION — refund một settlement không được làm paid_amount âm
// =========================================================================

it('A4-REG: refund khoản tất toán KHÔNG làm paid_amount của đơn cà phê âm', function () {
    [, $debtId] = drDebt(5_000_000);

    // Khách mua cà phê 30.000 và trả nốt 5 triệu nợ cũ trên cùng đơn.
    $coffee = drOrder(30_000);
    $settleId = drSettle($coffee, $debtId, 5_000_000)->assertSuccessful()->json('data.id');

    // Tiền thu nợ không phải tiền trả cho ly cà phê -> không tính vào đơn.
    expect((float) $coffee->fresh()->paid_amount)->toBe(0.0);

    // Cashier quẹt nhầm -> refund khoản tất toán đó.
    drRefund($coffee, $settleId)->assertSuccessful();

    // Trước fix: hàng đảo mất metadata => KHÔNG bị loại, trong khi bản gốc VẪN
    // bị loại => aggregate chỉ thấy -5.000.000. Giờ cả cặp cùng bị loại => net 0.
    expect((float) $coffee->fresh()->paid_amount)->toBe(0.0);

    // Hàng đảo phải mang theo settles_payment_id để cặp đôi đối xứng.
    $reversal = DB::table('order_payments')->where('refund_of_id', $settleId)->first();
    expect(json_decode($reversal->metadata, true)['settles_payment_id'] ?? null)->toBe($debtId);
});

it('A4-REG: refund khoản tất toán TRẢ LẠI khoản nợ cho /debts', function () {
    [, $debtId] = drDebt(5_000_000);
    $coffee = drOrder(30_000);

    $settleId = drSettle($coffee, $debtId, 5_000_000)->assertSuccessful()->json('data.id');
    expect(drDebtTotal())->toBe(0.0); // đã tất toán -> hết nợ

    // Huỷ khoản tất toán => khách nợ lại như cũ. Trước fix, hàng đảo thừa hưởng
    // settles_payment_id sẽ giả danh một settlement còn sống và giấu luôn khoản nợ.
    drRefund($coffee, $settleId)->assertSuccessful();

    expect(drDebtTotal())->toBe(5000000.0);
});

it('A4-REG: sau khi refund settlement, khách tất toán LẠI được', function () {
    [, $debtId] = drDebt(5_000_000);
    $coffee = drOrder(30_000);
    $settleId = drSettle($coffee, $debtId, 5_000_000)->assertSuccessful()->json('data.id');
    drRefund($coffee, $settleId)->assertSuccessful();

    // settles_already_settled không được chặn — settlement cũ đã bị đảo.
    $again = drOrder(30_000);
    drSettle($again, $debtId, 5_000_000)->assertSuccessful();

    expect(drDebtTotal())->toBe(0.0);
});

// =========================================================================
//  A5 — settlement đang pending phải chặn settlement thứ hai
// =========================================================================

it('A5: settlement PENDING chặn settlement thứ hai (không thu nợ 2 lần)', function () {
    [, $debtId] = drDebt(5_000_000);

    // Quẹt thẻ: is_auto_confirm = false -> payment nằm ở `pending`.
    $o1 = drOrder(30_000);
    drSettle($o1, $debtId, 5_000_000, test()->terminal->id)->assertSuccessful();

    // Trước fix: $alreadySettled chỉ đếm confirmed|succeeded nên hàng `pending`
    // không chặn gì. Cashier bấm lại -> settlement thứ hai -> confirm cả hai ->
    // khách bị thu 10.000.000 cho khoản nợ 5.000.000.
    $o2 = drOrder(30_000);
    drSettle($o2, $debtId, 5_000_000, test()->terminal->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_already_settled']);

    expect(DB::table('order_payments')
        ->where('metadata->settles_payment_id', $debtId)->count())->toBe(1);
});

it('A5: settlement FAILED vẫn cho phép thử lại', function () {
    [, $debtId] = drDebt(5_000_000);
    $o1 = drOrder(30_000);
    $failed = drSettle($o1, $debtId, 5_000_000, test()->terminal->id)->assertSuccessful()->json('data.id');

    // Thẻ bị từ chối.
    DB::table('order_payments')->where('id', $failed)->update(['status' => 'failed']);

    // Phải cho quẹt lại — nếu chặn thì khoản nợ chết vĩnh viễn (đúng lỗi A5 gốc).
    $o2 = drOrder(30_000);
    drSettle($o2, $debtId, 5_000_000)->assertSuccessful();

    expect(drDebtTotal())->toBe(0.0);
});

// =========================================================================
//  A6 — refund một phần không được xoá sổ khoản nợ
// =========================================================================

it('A6: refund 1.000 trên khoản nợ 5.000.000 -> /debts còn ĐÚNG 4.999.000', function () {
    [$debtOrder, $debtId] = drDebt(5_000_000);
    expect(drDebtTotal())->toBe(5000000.0);

    // Giảm trừ thiện chí 1.000.
    drRefund($debtOrder, $debtId, 1_000)->assertSuccessful();

    // refund() flip bản gốc sang `refunded` vô điều kiện. Trước fix, DebtController
    // loại bản gốc (status) VÀ loại hàng đảo (refund_of_id) => khoản nợ bốc hơi
    // sạch, 4.999.000 khách vẫn nợ biến mất khỏi mọi báo cáo (im lặng, bằng 0).
    // Giờ hai hàng được CỘNG BÙ TRỪ.
    expect(drDebtTotal())->toBe(4999000.0);
});

it('A6: refund TOÀN BỘ khoản nợ -> /debts về 0, không âm', function () {
    [$debtOrder, $debtId] = drDebt(5_000_000);

    drRefund($debtOrder, $debtId)->assertSuccessful();

    // 5.000.000 + (-5.000.000) = 0. Không bao giờ âm (hàng đảo không thể lớn hơn
    // bản gốc) => lỗi "nợ âm" gốc của A6 cũng không tái phát.
    expect(drDebtTotal())->toBe(0.0);
});

it('A6: khoản nợ net về 0 KHÔNG chiếm chỗ trong danh sách /debts', function () {
    [$paidOffOrder, $paidOffId] = drDebt(5_000_000);
    drRefund($paidOffOrder, $paidOffId)->assertSuccessful();

    // Khách khác còn nợ thật.
    $other = Customer::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
    ]);
    drDebt(700_000, $other->id);

    $rows = $this->actingAs($this->manager)
        ->getJson('/api/v1/shops/'.$this->shop->slug.'/debts')->json('data');

    // Chỉ còn đúng một khách nợ; khách đã hoàn hết không được đội sổ (và không
    // ăn mất một slot của trang).
    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['open_debt_total'])->toBe(700000.0)
        ->and($rows[0]['customer_id'])->toBe($other->id);
});

it('A6: đếm số khoản nợ không tính hàng đảo', function () {
    [$o1, $p1] = drDebt(1_000_000);
    drDebt(2_000_000);            // khoản nợ thứ hai, còn nguyên
    drRefund($o1, $p1, 400_000)->assertSuccessful();   // hoàn một phần khoản 1

    $rows = $this->actingAs($this->manager)
        ->getJson('/api/v1/shops/'.$this->shop->slug.'/debts')->json('data');

    // 2 khoản nợ (không phải 3 — hàng đảo không phải một khoản nợ mới).
    expect((int) $rows[0]['open_debt_count'])->toBe(2)
        // 1.000.000 - 400.000 + 2.000.000
        ->and((float) $rows[0]['open_debt_total'])->toBe(2600000.0);
});
