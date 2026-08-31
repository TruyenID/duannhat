<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Plan-044 R2 (issue #503) — NO auto carry-over; operator-confirmed gap claim.
 *
 * R2 pivot: open() no longer re-stamps active orders OR sweeps gap payments.
 * Order attribution is display-only (read by no reconcile query). Gap PAYMENTS
 * are attributed ONLY by an explicit operator claim (claimed_gap_payment_ids),
 * bounded to (prev_end, opened_at], branch-owned, still NULL, succeeded. Cash is
 * claimable (held aside by staff, not in the opening float — DESIGN R2 §3).
 * These tests replace the old auto-sweep assertions this file used to hold.
 */

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
        'is_active' => true,
    ]);
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    // A non-cash method (code != 'cash') so is_cash / cash_amount can diverge.
    $this->cardMethod = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'code' => 'card',
        'type' => 'card',
    ]);
});

/** Minimal branch-scoped active order header (defaults to $this branch/brand/org). */
function sweepOrder(object $ctx, string $status, ?string $tillSessionId = null, ?Branch $branch = null, ?Brand $brand = null, ?string $orgId = null): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-S'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'till_session_id' => $tillSessionId,
        'branch_id' => ($branch ?? $ctx->branch)->id,
        'brand_id' => ($brand ?? $ctx->brand)->id,
        'organization_id' => $orgId ?? $ctx->orgId,
    ]);
}

/** A NULL-attributed payment on a fresh order, at a chosen wall-clock. */
function sweepPayment(object $ctx, Carbon $when, string $status = 'succeeded', ?string $methodId = null, ?string $tillSessionId = null, ?Branch $branch = null, ?Brand $brand = null, ?string $orgId = null): OrderPayment
{
    $order = sweepOrder($ctx, 'paying', null, $branch, $brand, $orgId);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $methodId ?? $ctx->cashMethod->id,
        'amount' => 1000,
        'status' => $status,
        'refund_of_id' => null,
        'till_session_id' => $tillSessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $orgId ?? $ctx->orgId,
        'branch_id' => ($branch ?? $ctx->branch)->id,
        'brand_id' => ($brand ?? $ctx->brand)->id,
        'created_at' => $when,
    ]);
}

/** Seed a prior settled shift closed at $tPrev and release the till lock. */
function seedPriorSettled(object $ctx, Carbon $tPrev): TillSession
{
    $prior = TillSession::factory()->settled()->create([
        'till_id' => $ctx->till->id,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'closed_at' => $tPrev,
    ]);
    $ctx->till->update(['current_session_id' => null]);

    return $prior;
}

function openNextShift(object $ctx, array $claimedIds = [], bool $ack = false): TillSession
{
    return app(TillSessionService::class)->open([
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'opening_counts' => [],
        'claimed_gap_payment_ids' => $claimedIds,
        'gap_cash_held_separately_ack' => $ack,
    ]);
}

// ── R2: open() performs NO automatic carry-over ─────────────────────────────

it('does NOT re-stamp active orders on open (queue removed, R2)', function () {
    seedPriorSettled($this, now()->subHours(2));
    $gapOrder = sweepOrder($this, 'open');
    $unpaid = sweepOrder($this, 'dining');

    openNextShift($this);

    expect($gapOrder->fresh()->till_session_id)->toBeNull()
        ->and($unpaid->fresh()->till_session_id)->toBeNull();
});

it('does NOT auto-sweep gap payments on open without a claim (R2)', function () {
    seedPriorSettled($this, now()->subHours(2));
    $gapPayment = sweepPayment($this, now()->subMinutes(15));

    openNextShift($this);

    expect($gapPayment->fresh()->till_session_id)->toBeNull();
});

// ── R2: operator-confirmed claim ────────────────────────────────────────────

it('claims confirmed gap payments — CASH and non-cash alike — to the new shift', function () {
    seedPriorSettled($this, now()->subHours(2));
    $cash = sweepPayment($this, now()->subMinutes(20), methodId: $this->cashMethod->id);
    $card = sweepPayment($this, now()->subMinutes(10), methodId: $this->cardMethod->id);

    $new = openNextShift($this, [$cash->id, $card->id], ack: true);

    expect($cash->fresh()->till_session_id)->toBe($new->id)
        ->and($card->fresh()->till_session_id)->toBe($new->id)
        ->and($new->gapPaymentsClaimed)->toBe(2);
});

it('skips a FOREIGN-branch claim id — never 422, others still applied', function () {
    seedPriorSettled($this, now()->subHours(2));
    $ours = sweepPayment($this, now()->subMinutes(10));

    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $fBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $fBranch = Branch::factory()->create(['console_organization_id' => $foreignOrgId, 'console_brand_id' => $fBrand->console_brand_id, 'is_active' => true]);
    $fMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $foreignOrgId]);
    $foreign = sweepPayment(
        (object) ['branch' => $fBranch, 'brand' => $fBrand, 'orgId' => $foreignOrgId, 'cashMethod' => $fMethod],
        now()->subMinutes(10),
        methodId: $fMethod->id,
        branch: $fBranch,
        brand: $fBrand,
        orgId: $foreignOrgId,
    );

    $new = openNextShift($this, [$ours->id, $foreign->id], ack: true);

    expect($ours->fresh()->till_session_id)->toBe($new->id)
        ->and($foreign->fresh()->till_session_id)->toBeNull()
        ->and($new->gapPaymentsClaimed)->toBe(1);
});

it('skips an OUT-OF-WINDOW claim id (created before prev_end)', function () {
    $tPrev = now()->subHours(2);
    seedPriorSettled($this, $tPrev);
    $beforeWindow = sweepPayment($this, $tPrev->copy()->subMinutes(30)); // inside the prior shift
    $inWindow = sweepPayment($this, now()->subMinutes(10));

    $new = openNextShift($this, [$beforeWindow->id, $inWindow->id], ack: true);

    expect($beforeWindow->fresh()->till_session_id)->toBeNull()
        ->and($inWindow->fresh()->till_session_id)->toBe($new->id);
});

it('skips an ALREADY-STAMPED claim id (keeps its original shift, R5)', function () {
    $prior = seedPriorSettled($this, now()->subHours(2));
    $stamped = sweepPayment($this, now()->subMinutes(10), tillSessionId: $prior->id);

    $new = openNextShift($this, [$stamped->id], ack: true);

    expect($stamped->fresh()->till_session_id)->toBe($prior->id)
        ->and($new->gapPaymentsClaimed)->toBe(0);
});

it('is idempotent — re-claiming an already-claimed payment is a no-op', function () {
    seedPriorSettled($this, now()->subHours(2));
    $p = sweepPayment($this, now()->subMinutes(10));

    $first = openNextShift($this, [$p->id], ack: true);
    expect($p->fresh()->till_session_id)->toBe($first->id)->and($first->gapPaymentsClaimed)->toBe(1);

    // Close the first shift, then re-open claiming the same (now non-NULL) id.
    // Query-builder updates because $this->till is stale in-memory, so a model
    // update to current_session_id would no-op. (It used to be needed for a
    // second reason too: open() returned a model carrying gap_payments_claimed
    // as a phantom attribute, so saving it emitted an UPDATE for a column that
    // does not exist. That is fixed — the value is a real property now.)
    Till::whereKey($this->till->id)->update(['current_session_id' => null]);
    TillSession::whereKey($first->id)->update(['status' => 'settled', 'closed_at' => now()]);
    $second = openNextShift($this, [$p->id], ack: true);

    expect($p->fresh()->till_session_id)->toBe($first->id) // unchanged — stays with the first
        ->and($second->gapPaymentsClaimed)->toBe(0);
});

// ── Audit ───────────────────────────────────────────────────────────────────

it('writes a till_session.gap_claim audit with counts + ack when a claim applies', function () {
    seedPriorSettled($this, now()->subHours(2));
    $cash = sweepPayment($this, now()->subMinutes(20), methodId: $this->cashMethod->id);
    $card = sweepPayment($this, now()->subMinutes(10), methodId: $this->cardMethod->id);

    $new = openNextShift($this, [$cash->id, $card->id], ack: true);

    $audit = AuditLog::query()
        ->where('auditable_id', $new->id)
        ->where('action', 'till_session.gap_claim')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(count($audit->metadata['applied_ids']))->toBe(2)
        ->and((float) $audit->metadata['total_amount'])->toBe(2000.0)
        ->and((float) $audit->metadata['cash_amount'])->toBe(1000.0) // only the cash-method one
        ->and($audit->metadata['held_separately_ack'])->toBeTrue();
});

it('writes NO gap_claim audit when nothing is claimed', function () {
    seedPriorSettled($this, now()->subHours(2));
    sweepPayment($this, now()->subMinutes(10)); // exists but not claimed

    $new = openNextShift($this);

    $exists = AuditLog::query()->where('auditable_id', $new->id)->where('action', 'till_session.gap_claim')->exists();
    expect($exists)->toBeFalse();
});

// ── R2: the held-separately ack is a SERVER rule, not a UI nicety ───────────

/*
 * The ack records WHERE the claimed gap cash physically is, and that is the only
 * thing separating a correct claim from double-counting:
 *
 *   held aside    → not in the opening float → attributing it here is right
 *   in the drawer → already inside opening_float_amount → attributing it again
 *                   leaves this shift over by exactly that amount, because
 *                   reconcile() derives 過不足 from order_payments.till_session_id
 *
 * pos-web gates its submit on it, but a UI-only rule is no rule: the workstation,
 * an older pos-web build, or plain curl all reach the same endpoint.
 */
it('rejects a CASH gap claim submitted without the held-separately ack', function () {
    seedPriorSettled($this, now()->subHours(2));
    $cash = sweepPayment($this, now()->subMinutes(20), methodId: $this->cashMethod->id);

    expect(fn () => openNextShift($this, [$cash->id], ack: false))
        ->toThrow(ValidationException::class);

    // And nothing is attributed — the rejection must leave the money exactly
    // where it was, not half-claim it.
    expect($cash->fresh()->till_session_id)->toBeNull();
});

it('allows a NON-CASH gap claim without the ack — no drawer to double-count', function () {
    seedPriorSettled($this, now()->subHours(2));
    $card = sweepPayment($this, now()->subMinutes(10), methodId: $this->cardMethod->id);

    $new = openNextShift($this, [$card->id], ack: false);

    expect($card->fresh()->till_session_id)->toBe($new->id)
        ->and($new->gapPaymentsClaimed)->toBe(1);
});

// ── The open() return value must be safe to persist ─────────────────────────

it('returns a session that can be saved without touching non-existent columns', function () {
    seedPriorSettled($this, now()->subHours(2));
    $cash = sweepPayment($this, now()->subMinutes(20), methodId: $this->cashMethod->id);

    $new = openNextShift($this, [$cash->id], ack: true);

    // gap_payments_claimed / continues_chain are response-only values. When they
    // were assigned as Eloquent attributes, the next save() emitted
    // `UPDATE till_sessions SET ..., gap_payments_claimed = ?` and died with
    // "no such column" — so any caller that persisted the returned session, or
    // any future line inside open() itself, could not open a shift at all.
    $new->update(['opening_float_amount' => 12345]);

    expect((float) $new->fresh()->opening_float_amount)->toBe(12345.0)
        ->and($new->gapPaymentsClaimed)->toBe(1);
});

/*
 * #2744 — ĐƯỜNG GHI của cặp đọc/ghi gap payment.
 *
 * `gapPreview()` (ĐỌC) và `claimGapPayments()` (GHI) phải nhận CÙNG một tập.
 * Trước bản sửa, cả hai lọc `status = succeeded`, nên khoản hoàn MỘT PHẦN — gốc
 * giữ +X và flip `refunded`, hoàn là hàng âm riêng — rơi khỏi cả hai và phần
 * net không bao giờ gắn vào ca nào.
 *
 * Bài này ghim nửa GHI. Nửa ĐỌC ghim ở `TillGapEndpointsTest`. Sửa lệch một
 * nửa là hình dạng TỆ HƠN cả hai cùng sai: preview mời thu ngân tích, claim
 * lặng lẽ từ chối đóng dấu.
 */
it('claim đóng dấu được khoản hoàn MỘT PHẦN — net còn dương thì tiền vẫn trong két (#2744)', function () {
    seedPriorSettled($this, now()->subHours(2));

    $origin = sweepPayment($this, now()->subHour(), 'refunded');
    $origin->update(['amount' => 5000]);
    OrderPayment::factory()->create([
        'customer_order_id' => $origin->customer_order_id,
        'payment_method_id' => $this->cashMethod->id,
        'amount' => -1000,
        'status' => 'succeeded',
        'refund_of_id' => $origin->id,
        'till_session_id' => null,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(50),
    ]);

    $session = openNextShift($this, [$origin->id], true);

    expect($origin->fresh()->till_session_id)->toBe($session->id);
});

/*
 * #2744 — CONTROL NGƯỢC cho nửa GHI: hoàn HẾT thì KHÔNG được đóng dấu.
 *
 * Thiếu bài này thì một "bản sửa" nới status mà quên điều kiện net sẽ xanh, và
 * ta gán vào ca một khoản đã trả lại khách trọn vẹn — tức thổi doanh thu ca lên
 * đúng bằng số đã hoàn.
 */
it('claim TỪ CHỐI khoản đã hoàn HẾT — net 0 thì không có gì để gán (#2744)', function () {
    seedPriorSettled($this, now()->subHours(2));

    $origin = sweepPayment($this, now()->subHour(), 'refunded');
    $origin->update(['amount' => 3000]);
    OrderPayment::factory()->create([
        'customer_order_id' => $origin->customer_order_id,
        'payment_method_id' => $this->cashMethod->id,
        'amount' => -3000,
        'status' => 'succeeded',
        'refund_of_id' => $origin->id,
        'till_session_id' => null,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(50),
    ]);

    openNextShift($this, [$origin->id], true);

    expect($origin->fresh()->till_session_id)->toBeNull();
});

/*
 * #2744 vòng 2 — bài đóng đúng cái lỗ mà bốn bài vòng 1 không thấy.
 *
 * Vòng 1 chỉ assert `till_session_id` ĐƯỢC GÁN. Nhưng gán đúng hàng gốc mà bỏ
 * hàng hoàn lại NULL thì `reconcile()` (#523) — vốn tính kỳ vọng bằng
 * SUM(amount) trên các hàng mang till_session_id — sẽ đòi ca này 1.000 trong
 * khi thu ngân ack 700 và cầm 700 thật. Ca short đúng bằng số đã hoàn, và −300
 * không ca nào gánh.
 *
 * Nói cách khác: "đã gán" KHÔNG chứng minh "hạch toán đúng". Bài này đo con số
 * cuối — thứ thu ngân thật sự bị đòi lúc đóng ca.
 */
it('reconcile() của ca claim ra NET, không phải GROSS — hàng hoàn đi theo khoản gốc (#2744)', function () {
    seedPriorSettled($this, now()->subHours(2));

    $origin = sweepPayment($this, now()->subHour(), 'refunded');
    $origin->update(['amount' => 1000]);
    $refund = OrderPayment::factory()->create([
        'customer_order_id' => $origin->customer_order_id,
        'payment_method_id' => $this->cashMethod->id,
        'amount' => -300,
        'status' => 'succeeded',
        'refund_of_id' => $origin->id,
        'till_session_id' => null,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(50),
    ]);

    $session = openNextShift($this, [$origin->id], true);

    // Hàng gốc VÀ hàng hoàn cùng về ca mới — không hàng nào mồ côi.
    expect($origin->fresh()->till_session_id)->toBe($session->id)
        ->and($refund->fresh()->till_session_id)->toBe($session->id);

    // Con số quyết định: ca này bị đòi 700, đúng bằng số thu ngân đã ack.
    $recon = app(TillSessionService::class)->reconcile($session->fresh());

    expect((float) $recon['cash']['cash_sales'])->toBe(700.0);
});

/*
 * #2744 vòng 2 — CONTROL: hàng hoàn ĐÃ gắn ca khác thì KHÔNG bị kéo về.
 *
 * Hoàn xảy ra sau khi một ca khác đã mở ⇒ ca đó đã gánh −Y qua reconcile.
 * Kéo nó về ca claim là bẻ sổ của một ca đã chốt, và đếm hai lần.
 */
it('claim KHÔNG cướp hàng hoàn đã thuộc ca khác (#2744)', function () {
    $prior = seedPriorSettled($this, now()->subHours(2));

    $origin = sweepPayment($this, now()->subHour(), 'refunded');
    $origin->update(['amount' => 1000]);
    $refund = OrderPayment::factory()->create([
        'customer_order_id' => $origin->customer_order_id,
        'payment_method_id' => $this->cashMethod->id,
        'amount' => -300,
        'status' => 'succeeded',
        'refund_of_id' => $origin->id,
        'till_session_id' => $prior->id,          // ĐÃ thuộc ca trước
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(50),
    ]);

    $session = openNextShift($this, [$origin->id], true);

    expect($refund->fresh()->till_session_id)->toBe($prior->id)
        ->and($origin->fresh()->till_session_id)->toBe($session->id);
});
