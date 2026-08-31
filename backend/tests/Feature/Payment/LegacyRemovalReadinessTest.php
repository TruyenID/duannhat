<?php

/**
 * #1808 — plan-047 T7.6 readiness gate.
 *
 * T7.6 stalled for weeks because its removal conditions were tribal knowledge:
 * answering "can we delete this yet?" meant grepping four classes and querying
 * the ledger by hand, every time. These tests pin the two properties that make
 * the command trustworthy enough to replace that ritual — it must not report
 * itself as a call site, and it must not call a gate ready on no evidence.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentPolicyRevision;
use App\Services\Payment\Observation\LegacyRemovalReadiness;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\UnavailableBranchManagementProjectionSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

function gate(array $report, string $key): array
{
    foreach ($report['gates'] as $g) {
        if ($g['key'] === $key) {
            return $g;
        }
    }

    throw new RuntimeException('Gate not reported: '.$key);
}

beforeEach(function () {
    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'type' => 'cash',
        'is_active' => true,
    ]);
});

it('reports every gate, including the one whose key contains "deprecated"', function () {
    $report = app(LegacyRemovalReadiness::class)->report();

    expect(array_column($report['gates'], 'key'))->toEqualCanonicalizing([
        'payment_status_compatibility',
        'legacy_global_stripe_connection',
        'legacy_payment_method_code_path',
        'legacy_payment_method_resolver',
        // #2686 — bộ đếm alias của #2609 nay CÓ người đọc. Trước đó
        // `legacy_field_alias_hits` được ghi mỗi ngày mà không cổng nào hỏi
        // tới, nên câu hỏi "còn client nào phụ thuộc tên trường cũ?" không
        // trả lời được dù dữ liệu đã nằm sẵn.
        'legacy_alias_reliance',
    ]);
});

it('never counts its own source file as a call site', function () {
    $report = app(LegacyRemovalReadiness::class)->report();

    foreach ($report['gates'] as $g) {
        expect($g['call_sites'])->each->not->toContain('LegacyRemovalReadiness');
    }
});

it('#1822 shim ĐÃ XOÁ — cổng thành ratchet, không còn danh sách chờ', function () {
    // Trước #1822 cổng này đo hai vế: sổ cái sạch VÀ người vận hành xác nhận
    // hạm đội đã cutover. Chủ repo xác nhận 2026-08-05 chưa có bản phát hành
    // nào, nên vế fleet không có gì để bảo vệ và shim đã bị xoá.
    //
    // Cổng giữ lại với vai mới: `code_present` phải là FALSE. Nếu ai đó thêm
    // lại `PaymentStatusCompatibility` thì đây là chỗ nó sáng đèn.
    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'payment_status_compatibility');

    expect($g['code_present'])->toBeFalse()
        ->and($g['call_sites'])->toBe([])
        ->and($g['ledger_condition_met'])->toBeTrue()
        ->and($g['condition_met'])->toBeTrue()
        ->and($g['measurement'])->toContain('shim đã xoá');
});

it('#1822 cổng KHÔNG còn đọc config fleet đã chết', function () {
    // `payments.legacy_removal.fleet_cutover_attested_at` đã bị gỡ khỏi config.
    // Nếu cổng còn đọc nó thì `condition_met` sẽ phụ thuộc một khoá không tồn
    // tại — im lặng thành `null` và cổng đóng vĩnh viễn mà không ai hiểu vì sao.
    expect(config()->has('payments.legacy_removal'))->toBeFalse();

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'payment_status_compatibility');

    expect($g)->not->toHaveKey('fleet_condition_attested')
        ->and($g['condition_met'])->toBeTrue();
});

it('reports the confirmed-status gate as blocked while a legacy row survives', function () {
    // #1822 — vế fleet đã bị gỡ, nên ca này giờ ghim đúng MỘT thứ: một hàng
    // legacy còn sống thì cổng phải đóng, kể cả khi shim đã xoá. Đó là dấu hiệu
    // còn đường ghi chưa chuẩn hoá.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'closed',
        'total_amount' => 500,
        'paid_amount' => 500,
    ]);

    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 500,
        'status' => 'succeeded',
    ]);

    // `PaymentStatusEnum` no longer HAS a `confirmed` case — that is precisely
    // why the compatibility shim exists (it translates the value on read). So a
    // legacy row cannot be produced through the model at all; it has to be
    // written the way history wrote it, straight to the column.
    DB::table('order_payments')->where('id', $payment->id)->update(['status' => 'confirmed']);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'payment_status_compatibility');

    expect($g['condition_met'])->toBeFalse()
        ->and($g['measurement'])->toContain('1 hàng confirmed còn sống');
});

it('refuses to call the stripe gate ready when no branch was checked', function () {
    Branch::query()->update(['is_active' => false]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_global_stripe_connection');

    // Zero unresolved out of zero checked is NOT evidence of readiness.
    expect($g['condition_met'])->toBeFalse()
        ->and($g['measurement'])->toContain('0/0');
});

it('flags actionable only when a met gate still has code present', function () {
    $report = app(LegacyRemovalReadiness::class)->report();

    $anyMetWithCode = false;
    foreach ($report['gates'] as $g) {
        if ($g['condition_met'] && $g['code_present']) {
            $anyMetWithCode = true;
        }
    }

    expect($report['actionable'])->toBe($anyMetWithCode);
});

/**
 * #1811 — the precondition that the note used to deny.
 *
 * Emptying the resolver's call sites means enforcing effective options, and
 * enforcing before every branch has a published policy revision refuses real
 * checkouts. The coverage number therefore has to be visible on the gate, not
 * re-derived from source by whoever reads it next.
 */
it('reports policy revision coverage on the resolver gate', function () {
    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');

    $coverage = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    // One active branch from beforeEach, no revision published for it.
    expect($coverage)->not->toBeNull()
        ->and($coverage['met'])->toBeFalse()
        ->and($coverage['measurement'])->toContain('0/1 active branches');
});

it('states plainly that policy enforcement is not mandatory while the flag is off', function () {
    config(['payments.policy_enforcement.required' => false]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');

    $mandatory = collect($g['preconditions'])->firstWhere('key', 'policy_enforcement_is_mandatory');

    // The exact phrase that was wrong, not a substring of the correction: the
    // note deliberately opens "NOT ordinary work", so asserting on
    // "ordinary work" would match the fix as well as the defect.
    expect($mandatory['met'])->toBeFalse()
        ->and($mandatory['measurement'])->toContain('OFF')
        ->and($g['note'])->not->toContain('not waiting on production evidence')
        // The ⚠ is the single sentence standing between an operator and
        // refusing every cash transaction. Nothing pinned it, so any future
        // edit could delete it with zero reds — which is how the note drifted
        // in the first place.
        ->and($g['note'])->toContain('EVERY CASH PAYMENT')
        ->and($g['note'])->toContain('#1831');
});

it('#1847 reports enforcement as mandatory once the flag is actually on', function () {
    // The half that did not exist. `met` was a hard-coded `false`, so this gate
    // could never report ready even AFTER Gate 6 flipped the flag — T7.2 asks
    // for exactly this confirmation and could never get it. Without this test
    // the constant could come back and nothing would notice.
    config(['payments.policy_enforcement.required' => true]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');

    $mandatory = collect($g['preconditions'])->firstWhere('key', 'policy_enforcement_is_mandatory');

    expect($mandatory['met'])->toBeTrue()
        ->and($mandatory['measurement'])->toContain('ON');
});

/**
 * plan-055 T1.1 (#1814) — rollout progress of `gateway_option_id`.
 *
 * Two traps this pins: the `unknown` bucket (channel NULL) must be reported —
 * on the dev DB every legacy row is null-channel, and hiding it shows a
 * flattering percentage over a population where no client sent anything — and
 * an empty window must NOT read as a finished rollout.
 */
function paymentRow(array $ctx, array $attributes = []): OrderPayment
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $ctx['org'],
        'brand_id' => $ctx['brand'],
        'branch_id' => $ctx['branch'],
        'status' => 'closed',
        'total_amount' => 500,
        'paid_amount' => 500,
    ]);

    return OrderPayment::factory()->create(array_merge([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx['method'],
        'organization_id' => $ctx['org'],
        'brand_id' => $ctx['brand'],
        'branch_id' => $ctx['branch'],
        'amount' => 500,
        'status' => 'succeeded',
    ], $attributes));
}

it('reports an empty window as NOT ready instead of vacuously complete', function () {
    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'client_sends_gateway_option_id');

    expect($p['met'])->toBeFalse()
        ->and($p['measurement'])->toContain('nothing measured');
});

it('surfaces the unknown-channel bucket rather than dropping it', function () {
    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->cash->id];

    paymentRow($ctx, ['channel' => null, 'gateway_option_id' => null]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'client_sends_gateway_option_id');

    expect($p['met'])->toBeFalse()
        ->and($p['measurement'])->toContain('0% of 1 sale payment')
        ->and($p['measurement'])->toContain('unknown 0% (1)');
});

it('excludes refund rows so they do not dilute the rollout signal', function () {
    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->cash->id];

    $sale = paymentRow($ctx, ['channel' => 'pos', 'gateway_option_id' => null]);
    // A refund carries no option of its own; counting it would report 0/2.
    paymentRow($ctx, ['channel' => 'pos', 'gateway_option_id' => null, 'refund_of_id' => $sale->id, 'amount' => -500]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'client_sends_gateway_option_id');

    expect($p['measurement'])->toContain('of 1 sale payment');
});

it('ignores payments older than the window', function () {
    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->cash->id];

    paymentRow($ctx, ['channel' => 'pos', 'gateway_option_id' => null, 'created_at' => now()->subDays(30)]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(null, 7), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'client_sends_gateway_option_id');

    expect($p['measurement'])->toContain('nothing measured');

    // Widen the window and the same row becomes visible.
    $wide = gate(app(LegacyRemovalReadiness::class)->report(null, 90), 'legacy_payment_method_resolver');
    $pw = collect($wide['preconditions'])->firstWhere('key', 'client_sends_gateway_option_id');

    expect($pw['measurement'])->toContain('of 1 sale payment');
});

/**
 * plan-055 T1.2 (#1817) — coverage must be readable PER ORG.
 *
 * A total is not actionable: one tenant at 100% and another at 0% average into
 * a number that says neither where to backfill nor who would start refusing
 * checkouts the moment enforcement turns on.
 */
it('breaks policy revision coverage down per organization, worst first', function () {
    // Second tenant, fully uncovered, alongside the beforeEach one.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
        'name' => 'Zzz Uncovered Org',
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    Branch::factory()->count(2)->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    // Cover the first tenant's single branch.
    PaymentPolicyRevision::factory()->create([
        'branch_id' => $this->branch->id,
        'revision' => 1,
    ]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeFalse()
        // #1838 — the headline number is READY, not "has a revision". The
        // covered count is still reported beside it because the two failures
        // need different fixes.
        ->and($p['measurement'])->toContain('0/3 active branches ready')
        ->and($p['measurement'])->toContain('1 have a revision')
        // Worst tenant first, and named — not a bare uuid.
        ->and($p['measurement'])->toContain('Zzz Uncovered Org 0/2')
        ->and($p['organizations_incomplete'])->toHaveCount(2)
        ->and($p['organizations_incomplete'][0]['organization'])->toBe('Zzz Uncovered Org')
        ->and($p['organizations_incomplete'][0]['covered'])->toBe(0)
        ->and($p['organizations_incomplete'][0]['total'])->toBe(2);
});

it('#1838 refuses to call a branch ready when it has a revision but no effective option', function () {
    // This test used to assert the OPPOSITE — `met => true` on exactly this
    // fixture — and that assertion was the defect T2.3 warned about in writing:
    // publishing a revision takes coverage to N/N and flips the gate green while
    // every one of those branches still dies at checkout on
    // "No effective payment options are available for checkout".
    //
    // A gate that answers "yes, covered" without covering it is worse than no
    // gate: it is the one thing that stops anyone from looking again.
    PaymentPolicyRevision::factory()->create([
        'branch_id' => $this->branch->id,
        'revision' => 1,
    ]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeFalse()
        ->and($p['measurement'])->toContain('0/1 active branches ready')
        ->and($p['measurement'])->toContain('1 of those have 0 effective option')
        // Named, so the operator knows where to go — and the reason, because
        // "missing revision" and "no effective option" are different repairs.
        ->and($p['branches_without_effective_option'])->toHaveCount(1)
        ->and($p['branches_without_effective_option'][0]['branch_id'])->toBe((string) $this->branch->id)
        // The reason is carried, whatever it is. This fixture's branch cannot
        // even be evaluated (no ownership projection), and that is ITSELF a
        // not-ready state: a branch whose policy evaluation throws will throw at
        // checkout too. An exception must never be swallowed into "fine".
        ->and($p['branches_without_effective_option'][0]['reason'])->toBeString()
        ->and($p['branches_without_effective_option'][0]['reason'])->not->toBe('');
});

it('counts a branch once even when it has several published revisions', function () {
    // Four revision ROWS on ONE branch is 1/1 coverage, not 4/1 — the exact
    // conflation that made plans/plan-055 read "4/9" when it was really 1/9.
    PaymentPolicyRevision::factory()->count(4)->sequence(
        ['revision' => 1], ['revision' => 2], ['revision' => 3], ['revision' => 4],
    )->create(['branch_id' => $this->branch->id]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    // Assert the REVISION half — that is what dedup is about. The ready number
    // is 0 here for an unrelated reason (no effective option), so asserting on
    // it would make this test pass or fail for the wrong cause.
    expect($p['measurement'])->toContain('1 have a revision');
});

it('#1838 calls a branch ready only when it has BOTH a revision and an effective option', function () {
    // The positive side. Without it the new guard could be stuck-red — a gate
    // that can never say yes is as useless as one that always does.
    //
    // Uses the real policy fixture (connection + published revision + an option
    // the resolver actually surfaces), and deactivates this file's bare branch
    // so the count is about the ready one alone.
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    // Guard the guard, on the thing that actually matters: `currentEffectiveIdentity()`
    // reads options[0] WITHOUT filtering `effective`, so asserting on it would
    // not prove what it looks like it proves.
    $effective = collect(app(PaymentPolicyEvaluationService::class)
        ->effectiveOptions($fixtures->shop)['options'] ?? [])
        ->filter(static fn ($o): bool => is_array($o) && ($o['effective'] ?? false) === true)
        ->count();
    expect($effective)->toBeGreaterThan(0);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeTrue()
        ->and($p['branches_without_effective_option'])->toBe([])
        ->and($p['organizations_incomplete'])->toBe([]);
});

it('#1838 does not demand an effective option for a channel the branch has never used', function () {
    // Checking every channel would make this gate UNSATISFIABLE: a shop that
    // never takes kiosk payments would be blocked forever by "no effective
    // kiosk option", and a gate that can never say yes is as useless as one
    // that always does.
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    // This fixture's option is NOT offered on the kiosk channel.
    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeTrue();
});

it('#1838 DOES demand it once that branch has actually taken money on the channel', function () {
    // The other half. Same shop, same policy — but a kiosk payment exists in the
    // ledger, so a channel that dies at checkout must block the flip. Without
    // this the channel axis is unpinned and the gate reverts to POS-only.
    //
    // Keyed on OBSERVED traffic, not on a registered device: a device row is a
    // configuration proxy wrong in both directions (an idle kiosk blocks
    // forever; a deleted row hides kiosks still in the field) and it can never
    // reach `customer_web`, which has no device at all.
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    $paidOrder = $fixtures->seedCheckoutOrder(1000.0);
    OrderPayment::factory()->create([
        'customer_order_id' => $paidOrder->id,
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'channel' => 'kiosk',
    ]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeFalse()
        ->and($p['branches_without_effective_option'])->toHaveCount(1)
        ->and($p['branches_without_effective_option'][0]['reason'])->toContain('channel kiosk')
        // WHY, not just that. One platform-wide denial reading as N broken
        // branches would send the operator to investigate each of them.
        ->and($p['branches_without_effective_option'][0]['reason'])->toMatch('/denied:|no candidate options/');
});

it('#1838 sees a channel whose value lives in legacy metadata, not the column', function () {
    // `order_payments.channel` is nullable and rows written before it existed
    // carry the value in `metadata.channel` (#1058/#1059) — there was never a
    // backfill. A bare `whereNotNull('channel')` drops exactly the OLD rows,
    // and old rows are the whole point of looking all the way back: the filter
    // blinds the gate precisely where it reaches. Measured in review.
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    $paidOrder = $fixtures->seedCheckoutOrder(1000.0);
    OrderPayment::factory()->create([
        'customer_order_id' => $paidOrder->id,
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'channel' => null,
        'metadata' => ['channel' => 'kiosk'],
    ]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeFalse()
        ->and($p['branches_without_effective_option'][0]['reason'])->toContain('channel kiosk');
});

it('#1838 still counts a soft-deleted payment as evidence the channel is in use', function () {
    // A voided batch or a data cleanup must not silently stop a channel from
    // being checked — the terminal is still on the counter. Measured in review:
    // without `withTrashed()` this gate flips back to ready.
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    $paidOrder = $fixtures->seedCheckoutOrder(1000.0);
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $paidOrder->id,
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'channel' => 'kiosk',
    ]);
    $payment->delete();

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['met'])->toBeFalse()
        ->and($p['branches_without_effective_option'][0]['reason'])->toContain('channel kiosk');
});

it('#1838 counts an unresolvable payment even when its channel is an empty string', function () {
    // The shape the old second counting query missed: `channel = ''` is not
    // caught by SQL `whereNull`, but `resolveTransportFromPayment()` requires
    // `!== ''` and calls it unresolved. With the duplicated condition, the
    // combination was detected, the count returned 0, and the ⚠ VANISHED —
    // code that found a gap then reported silence. (Measured in review.)
    $this->branch->forceFill(['is_active' => false])->save();

    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $fixtures->seedConnection();
    $fixtures->publishInitialPolicyRevision();

    $order = $fixtures->seedCheckoutOrder(1000.0);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'channel' => '',
        'metadata' => null,
        'till_session_id' => null,
    ]);

    $g = gate(app(LegacyRemovalReadiness::class)->report(), 'legacy_payment_method_resolver');
    $p = collect($g['preconditions'])->firstWhere('key', 'policy_revision_coverage');

    expect($p['payments_with_unresolvable_channel'])->toHaveKey((string) $fixtures->shop->id)
        ->and($p['payments_with_unresolvable_channel'][(string) $fixtures->shop->id])->toBe(1);
});

it('#1887 class đã xoá — nhưng ĐƯỜNG legacy còn sống, và hai cổng phải nói khác nhau', function () {
    // Đây là ratchet CHỐNG CHÍNH LỖI TÔI SUÝT GHIM VÀO REPO.
    //
    // Tôi xoá `LegacyPaymentMethodResolver` bằng cách chuyển hai controller sang
    // một phương thức tương đương ở enricher. Cổng theo TÊN CLASS lập tức về
    // `already removed` — trong khi kiosk và workstation vẫn gửi y nguyên chuỗi
    // mã cũ và server vẫn tra y như cũ. Không có cuộc di trú nào. Tôi đã tick
    // task dựa trên chính con số đó.
    //
    // Nên hai cổng phải BẤT ĐỒNG cho tới khi client di trú thật: cổng class
    // đóng, cổng đường đi mở. Ngày chúng cùng đóng là ngày nợ trả xong thật.
    $report = app(LegacyRemovalReadiness::class)->report();

    $class = gate($report, 'legacy_payment_method_resolver');
    expect($class['code_present'])->toBeFalse('class đã xoá — thêm lại thì đèn sáng ở đây')
        ->and($class['call_sites'])->toBe([])
        // Câu điều kiện KHÔNG được hứa cuộc di trú mà nó không đo.
        ->and($class['condition'])->not->toContain('migrated to effective options');

    $path = gate($report, 'legacy_payment_method_code_path');
    expect($path['code_present'])->toBeTrue()
        ->and($path['condition_met'])->toBeFalse('client CHƯA di trú — nếu đã di trú, sửa test này kèm bằng chứng')
        ->and($path['call_sites'])->toEqualCanonicalizing([
            'app/Http/Controllers/Api/V1/Kiosk/KioskController.php',
            'app/Http/Controllers/Api/V1/Workstation/PaymentController.php',
        ]);
});

it('#1896/#1895 phân loại VIỆC vs HẸN GIỜ vẫn còn hiệu lực sau khi cổng scheduled bị gỡ', function () {
    // `deprecated_payment_methods_routes` là cổng `scheduled` DUY NHẤT, và route
    // của nó đã bị xoá sớm theo quyết định chủ dự án (#1895) — nên cổng đó không
    // còn. Phân loại thì PHẢI còn: cổng sau này đợi lịch phải gắn KIND_SCHEDULED
    // để không bị đếm là nợ.
    //
    // Vì sao vẫn ghim khi không còn cổng scheduled nào: mặc định an toàn nằm ở
    // hướng ngược lại — quên gắn `kind` thì rơi vào `work`, tức một cái hẹn bị
    // đếm nhầm thành việc (ồn ào, thấy ngay) chứ không phải một việc bị đếm nhầm
    // thành cái hẹn (im lặng, trôi qua).
    $report = app(LegacyRemovalReadiness::class)->report();

    $keys = array_map(static fn (array $g): string => $g['key'], $report['gates']);
    expect($keys)->not->toContain('deprecated_payment_methods_routes',
        'cổng này phải biến mất cùng route nó canh (#1895)');

    foreach ($report['gates'] as $g) {
        expect($g['kind'])->toBe(LegacyRemovalReadiness::KIND_WORK, "cổng {$g['key']}");
    }

    expect($report['scheduled_pending'])->toBe([])
        ->and($report['work_remaining'])->toEqualCanonicalizing([
            'legacy_global_stripe_connection',
            'legacy_payment_method_code_path',
            // ĐÃ ĐÓNG #2410 — `legacy_alias_reliance` rời danh sách này vì MÃ ĐÃ
            // XOÁ, không phải vì phép đo đổi. Trong DB test mẫu số vẫn bằng 0
            // (không thiết bị nào), nên `condition_met` vẫn false; thứ đưa nó ra
            // là `code_present = false` — không còn gì để gỡ thì không còn là việc.
            //
            // Bằng chứng lúc gỡ (production, 7 ngày tới 2026-08-17): 252 payment
            // qua workstation, 0 lượt alias thắng. Mẫu số khác 0, nên số 0 đó là
            // kết quả chứ không phải phép đo không chạy — đúng cái bẫy mà chính
            // cổng này viết ra để tránh.
        ], 'còn HAI việc — sửa test này kèm bằng chứng khi một trong hai đóng');
});

it('#1896 Gate 2 KHÔNG THỂ ĐẠT: nguồn ownership fail-closed nên không branch nào có effective option', function () {
    // Đây là phát hiện chặn cả plan-055 lẫn plan-047 T7.6, ghim lại để không ai
    // mất thêm một buổi đi tìm như tôi.
    //
    // `BranchManagementProjectionSource` được bind cứng sang
    // `UnavailableBranchManagementProjectionSource` (AppServiceProvider, plan-047
    // T2.5) — nó LUÔN trả `unresolved`, cố ý fail-closed. Không được suy quyền sở
    // hữu thương nhân từ cờ trên bảng `branches` cục bộ. Nguồn chân lý là
    // PLATFORM; nó còn thiếu vòng đời grant + endpoint đọc (docblock của class
    // đó ghi đủ, kèm cách tự kiểm).
    //
    // Hệ quả dây chuyền, đo trên dev 2026-08-05 với connection PayPay sandbox
    // đã `health=ready`, option `verified`, và 17/17 branch đã bật policy:
    //
    //   effectiveOptions() → decision `ownership_source_unavailable`, 0 allowed
    //   ⇒ plan-055 Gate 2 (mỗi branch ≥1 effective option) KHÔNG đạt được
    //   ⇒ Gate 6 (bật cưỡng chế) không tới được
    //   ⇒ cổng `legacy_payment_method_code_path` không đóng được
    //   ⇒ plan-047 T7.6 không đóng được
    //
    // Nói cách khác: dựng thêm dữ liệu KHÔNG mở được nút này. Nút nằm ở một hệ
    // thống khác. Test này đỏ vào đúng ngày adapter lên — và lúc đó nó là tin
    // MỪNG, hãy sửa nó kèm bằng chứng chứ đừng nới lỏng.
    $source = app(BranchManagementProjectionSource::class);

    expect($source)->toBeInstanceOf(
        UnavailableBranchManagementProjectionSource::class,
        'Nếu đây không còn là Unavailable, adapter ownership đã lên: chạy lại '
        .'`payments:legacy-removal-readiness` và mở lại bậc thang plan-055 Gate 2.',
    );
});
