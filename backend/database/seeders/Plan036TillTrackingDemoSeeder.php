<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillCashDenominationCount;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use App\Models\User;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TillCashEventTypeEnum;
use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Omnify\Enums\TillTenderSystemCategoryEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Plan-036 — Manager Till Tracking demo data.
 *
 * Seeds the demo shop branch with a rich, all-cases dataset so the
 * dashboard / history / detail / Z-report UI surfaces every empty-state,
 * happy-path, and edge-case path without further intervention:
 *
 *   - 3 tills (REG1 / REG2 / REG3) — two end up "open", one stays "idle"
 *   - Open session ~3h on REG1 (fresh)
 *   - Open session ~30h on REG2 (stale_warning, before stale_critical 40h)
 *   - 6 settled sessions today on REG1/REG3 — variances: 0 / +200 / -300 /
 *     +800 / -1,000 / -3,500 (out_of_tolerance, with explicit reason)
 *   - 1 closing session (closing draft, not yet settled)
 *   - 1 force-abandoned session (manager-driven, plan-032)
 *   - 1 expired session (system scheduler, plan-032)
 *   - 30 days of historical settled sessions across REG1/REG3 so the
 *     variance trend chart, 30d activity, and CSV export all have data
 *
 * Every settled / abandoned / expired session gets denomination counts,
 * cash events, settlement details, and an audit-log trail so the detail
 * page renders fully. Z-report PDF generation has live targets.
 *
 * Idempotent: if a till at that shop already has > 5 TillSession rows, the seeder
 * assumes prior data and exits early.
 *
 * Run standalone:
 *   docker compose exec app php artisan db:seed --class=Plan036TillTrackingDemoSeeder
 */
class Plan036TillTrackingDemoSeeder extends Seeder
{
    use RefusesToRunInProduction;

    private const SHOP_SLUG = MockDataSeeder::DEMO_SHOP_SLUG;

    private const HISTORY_DAYS = 30;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $branch = Branch::where('slug', self::SHOP_SLUG)->first();
        if (! $branch) {
            $this->command->warn('Plan036TillTrackingDemoSeeder: branch "'.self::SHOP_SLUG.'" not found. Run MockDataSeeder first.');

            return;
        }

        $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();
        if (! $organization) {
            $this->command->warn('Plan036TillTrackingDemoSeeder: organization not found for '.self::SHOP_SLUG.'.');

            return;
        }

        $brandId = $branch->brand_id ?? \DB::table('brands')
            ->where('console_brand_id', $branch->console_brand_id)
            ->value('id');
        if (! $brandId) {
            $this->command->warn('Plan036TillTrackingDemoSeeder: brand resolution failed.');

            return;
        }

        // Idempotency guard.
        $existingTillSessionCount = TillSession::where('branch_id', $branch->id)->count();
        if ($existingTillSessionCount > 5) {
            $this->command->info('Plan036TillTrackingDemoSeeder: '.self::SHOP_SLUG." already has {$existingTillSessionCount} sessions, skipping.");

            return;
        }

        // Resolve manager + a cashier user so audit_log has nice actor names.
        $manager = User::where('email', 'shop-manager-sjk@famgia.com')->first()
            ?? User::factory()->create([
                'console_organization_id' => $organization->console_organization_id,
                'name' => 'Demo Shop Till Manager',
                'email' => 'demo-manager-sjk@famgia.com',
                'is_active' => true,
            ]);
        $cashier = User::where('email', 'shop-staff-sjk@famgia.com')->first()
            ?? User::factory()->create([
                'console_organization_id' => $organization->console_organization_id,
                'name' => 'Demo Shop Till Cashier',
                'email' => 'demo-cashier-sjk@famgia.com',
                'is_active' => true,
            ]);

        // 3 tills.
        $tills = collect(['REG1', 'REG2', 'REG3'])->map(fn ($code) => Till::firstOrCreate(
            ['branch_id' => $branch->id, 'till_code' => $code],
            [
                'default_currency_code' => 'JPY',
                'variance_tolerance_amount' => 1000,
                'is_active' => true,
                'brand_id' => $brandId,
                'organization_id' => $organization->id,
            ]
        ));
        [$reg1, $reg2, $reg3] = [$tills[0], $tills[1], $tills[2]];

        $denominations = Denomination::where('currency_code', 'JPY')
            ->orderByDesc('value')
            ->get();
        if ($denominations->isEmpty()) {
            $this->command->warn('Plan036TillTrackingDemoSeeder: no JPY denominations found. Run DenominationSeeder first.');

            return;
        }

        $tenderTypes = TillTenderType::query()
            ->where('is_active', true)
            ->where(function ($q) use ($branch) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->get()
            ->keyBy('tender_key');
        if ($tenderTypes->isEmpty()) {
            $this->command->warn('Plan036TillTrackingDemoSeeder: no TillTenderType rows. Run TillTenderTypeSeeder first.');

            return;
        }

        $ctx = [
            'branch' => $branch,
            'organization' => $organization,
            'brand_id' => $brandId,
            'manager' => $manager,
            'cashier' => $cashier,
            'denominations' => $denominations,
            'tender_types' => $tenderTypes,
        ];

        // 1. Today's settled sessions on REG1 — variance buckets.
        $variancePairs = [
            ['variance' => 0, 'reason' => null],
            ['variance' => 200, 'reason' => null],
            ['variance' => -300, 'reason' => 'small rounding'],
            ['variance' => 800, 'reason' => null],
            ['variance' => -1_000, 'reason' => 'change error'],
            ['variance' => -3_500, 'reason' => 'over-tolerance — pending investigation'],
        ];
        foreach ($variancePairs as $i => $row) {
            $this->createSettledSession($ctx, $reg1, $i + 1, $row['variance'], $row['reason']);
        }

        // 2. Open sessions.
        $this->createOpenSession($ctx, $reg1, hoursAgo: 3, sessionSuffix: 'OPEN1');
        $this->createOpenSession($ctx, $reg2, hoursAgo: 30, sessionSuffix: 'OPEN2');
        // REG3 stays idle (no current session).

        // 3. Closing draft (no variance yet).
        $this->createClosingSession($ctx, $reg3);

        // 4. Force-abandoned (manager exit door, plan-032).
        $this->createForceAbandonedSession($ctx, $reg3);

        // 5. Expired (system-driven exit door, plan-032).
        $this->createExpiredSession($ctx, $reg3);

        // 6. 30 days of history across REG1 / REG3.
        for ($d = 1; $d <= self::HISTORY_DAYS; $d++) {
            $tillForDay = $d % 2 === 0 ? $reg1 : $reg3;
            $variance = $this->randomVariance($d);
            $this->createSettledSession(
                $ctx,
                $tillForDay,
                index: 100 + $d,
                varianceAmount: $variance,
                varianceReason: abs($variance) > 1_000 ? 'periodic over-tolerance' : null,
                businessDateOffsetDays: -$d,
            );
        }

        $this->command->info('Plan036TillTrackingDemoSeeder: seeded '.self::SHOP_SLUG.' with rich plan-036 demo data.');
        $this->command->info('  → Sign in as manager: shop-manager-sjk@famgia.com / password');
        $this->command->info('  → Visit /shop/'.self::SHOP_SLUG.'/till for the dashboard');
    }

    // =========================================================================
    //  Session builders
    // =========================================================================

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createOpenSession(array $ctx, Till $till, int $hoursAgo, string $sessionSuffix): void
    {
        $openedAt = now()->subHours($hoursAgo);
        $session = TillSession::create([
            'session_code' => 'SHIFT-'.$openedAt->format('Ymd').'-'.$sessionSuffix,
            'status' => TillSessionStatusEnum::Open->value,
            'business_date' => $openedAt->copy()->startOfDay(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 30_000,
            'opening_note' => 'Demo open shift seeded by Plan036TillTrackingDemoSeeder.',
            'opened_by_id' => $ctx['cashier']->id,
            'opener_name' => 'Demo Cashier',
            'opened_at' => $openedAt,
            'till_id' => $till->id,
            'branch_id' => $ctx['branch']->id,
            'brand_id' => $ctx['brand_id'],
            'organization_id' => $ctx['organization']->id,
        ]);
        $till->update(['current_session_id' => $session->id]);

        $this->seedOpeningCounts($session, $ctx['denominations'], 30_000);
        $this->logAudit($session, 'till_session_opened', $ctx['cashier']->id, [
            'session_code' => $session->session_code,
            'opening_float_amount' => 30_000,
        ], $openedAt);

        // A few cash events so the activity timeline isn't empty.
        $this->createCashEvent($session, $ctx['cashier'], TillCashEventTypeEnum::PaidOut, 1_500, 'tip out', $openedAt->copy()->addMinutes(45));
        $this->createCashEvent($session, $ctx['cashier'], TillCashEventTypeEnum::PaidIn, 500, 'employee meal change', $openedAt->copy()->addHours(1));
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createClosingSession(array $ctx, Till $till): void
    {
        $openedAt = now()->subHours(8);
        $session = TillSession::create([
            'session_code' => 'SHIFT-'.$openedAt->format('Ymd').'-CLOSING',
            'status' => TillSessionStatusEnum::Closing->value,
            'business_date' => $openedAt->copy()->startOfDay(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 30_000,
            'opening_note' => 'Demo closing draft.',
            'closing_note' => 'Counted ¥ but not yet final.',
            'opened_by_id' => $ctx['cashier']->id,
            'opener_name' => 'Demo Cashier',
            'opened_at' => $openedAt,
            'till_id' => $till->id,
            'branch_id' => $ctx['branch']->id,
            'brand_id' => $ctx['brand_id'],
            'organization_id' => $ctx['organization']->id,
        ]);

        $this->seedOpeningCounts($session, $ctx['denominations'], 30_000);
        $this->seedClosingCounts($session, $ctx['denominations'], 124_300);
        $this->logAudit($session, 'till_session_opened', $ctx['cashier']->id, [], $openedAt);
        $this->logAudit($session, 'till_session_draft_saved', $ctx['cashier']->id, [], $openedAt->copy()->addHours(7));
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createForceAbandonedSession(array $ctx, Till $till): void
    {
        $openedAt = now()->subDays(2);
        $abandonedAt = now()->subDays(2)->addHours(28);
        $session = TillSession::create([
            'session_code' => 'SHIFT-'.$openedAt->format('Ymd').'-FORCE',
            'status' => TillSessionStatusEnum::Abandoned->value,
            'business_date' => $openedAt->copy()->startOfDay(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 30_000,
            'opening_note' => 'Cashier left mid-shift.',
            'opened_by_id' => $ctx['cashier']->id,
            'opener_name' => 'Demo Cashier',
            'opened_at' => $openedAt,
            'abandoned_at' => $abandonedAt,
            'closed_by_id' => $ctx['manager']->id,
            'force_abandoned' => true,
            'force_abandoned_by_id' => $ctx['manager']->id,
            'force_abandon_reason_code' => 'pos_device_failure',
            'force_abandon_reason_detail' => 'POS frozen ~21:00 JST; cashier rebooted and reopened a fresh shift on REG3.',
            'till_id' => $till->id,
            'branch_id' => $ctx['branch']->id,
            'brand_id' => $ctx['brand_id'],
            'organization_id' => $ctx['organization']->id,
        ]);

        $this->seedOpeningCounts($session, $ctx['denominations'], 30_000);
        $this->logAudit($session, 'till_session_opened', $ctx['cashier']->id, [], $openedAt);
        $this->logAudit($session, 'till_session_force_abandoned', $ctx['manager']->id, [
            'reason_code' => 'pos_device_failure',
        ], $abandonedAt);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createExpiredSession(array $ctx, Till $till): void
    {
        $openedAt = now()->subDays(3);
        $expiredAt = now()->subDays(3)->addHours(50);
        $session = TillSession::create([
            'session_code' => 'SHIFT-'.$openedAt->format('Ymd').'-EXPIRED',
            'status' => TillSessionStatusEnum::Expired->value,
            'business_date' => $openedAt->copy()->startOfDay(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 30_000,
            'opening_note' => 'Closed automatically by scheduler — no activity.',
            'opened_by_id' => $ctx['cashier']->id,
            'opener_name' => 'Demo Cashier',
            'opened_at' => $openedAt,
            'expired_at' => $expiredAt,
            'expire_reason' => 'no_activity',
            'expire_threshold_hours' => 48,
            'till_id' => $till->id,
            'branch_id' => $ctx['branch']->id,
            'brand_id' => $ctx['brand_id'],
            'organization_id' => $ctx['organization']->id,
        ]);

        $this->seedOpeningCounts($session, $ctx['denominations'], 30_000);
        $this->logAudit($session, 'till_session_opened', $ctx['cashier']->id, [], $openedAt);
        $this->logAudit($session, 'till_session_expired', null, [
            'reason' => 'no_activity',
            'threshold_hours' => 48,
        ], $expiredAt);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createSettledSession(
        array $ctx,
        Till $till,
        int $index,
        float $varianceAmount,
        ?string $varianceReason,
        int $businessDateOffsetDays = 0,
    ): TillSession {
        $businessDate = now()->addDays($businessDateOffsetDays);
        $openedAt = $businessDate->copy()->setTime(9, 0)->addMinutes(($index % 8) * 10);
        $closedAt = $openedAt->copy()->addHours(8)->addMinutes(($index % 5) * 7);

        $expected = 140_000 + ($index * 1_000) % 50_000;
        $counted = $expected + (int) $varianceAmount;
        $code = 'SHIFT-'.$businessDate->format('Ymd').'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);

        $session = TillSession::create([
            'session_code' => $code,
            'status' => TillSessionStatusEnum::Settled->value,
            'business_date' => $businessDate->copy()->startOfDay(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 30_000,
            'opening_note' => null,
            'closing_note' => $varianceReason ? ('Variance reason: '.$varianceReason) : null,
            'opened_by_id' => $ctx['cashier']->id,
            'opener_name' => 'Demo Cashier '.($index % 3 === 0 ? '佐藤' : ($index % 2 === 0 ? '田中' : '鈴木')),
            'closed_by_id' => $ctx['cashier']->id,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'expected_cash_amount' => $expected,
            'counted_cash_amount' => $counted,
            'cash_variance_amount' => $counted - $expected,
            'till_id' => $till->id,
            'branch_id' => $ctx['branch']->id,
            'brand_id' => $ctx['brand_id'],
            'organization_id' => $ctx['organization']->id,
        ]);

        $this->seedOpeningCounts($session, $ctx['denominations'], 30_000);
        $this->seedClosingCounts($session, $ctx['denominations'], $counted);

        // 1–3 cash events.
        $this->createCashEvent($session, $ctx['cashier'], TillCashEventTypeEnum::PaidOut, 1_500, 'tip out', $openedAt->copy()->addHours(2));
        if ($index % 2 === 0) {
            $this->createCashEvent($session, $ctx['cashier'], TillCashEventTypeEnum::PaidIn, 800, 'employee meal change', $openedAt->copy()->addHours(4));
        }

        // Per-tender settlement detail rows so the detail page has a full
        // reconciliation table.
        $this->seedSettlementDetails($session, $ctx['tender_types'], $expected, $counted, $varianceReason);

        // OrderPayments so gross_revenue rollups have data (only for the
        // first few "today" sessions — keeps the seed cheap on the order
        // table). Skip for historical to keep cost down.
        if ($businessDateOffsetDays === 0 && $index <= 6) {
            $this->seedOrderPayments($session, $ctx);
        }

        $this->logAudit($session, 'till_session_opened', $ctx['cashier']->id, [
            'session_code' => $code,
        ], $openedAt);
        $this->logAudit($session, 'till_session_settled', $ctx['cashier']->id, [
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'cash_variance' => $counted - $expected,
        ], $closedAt);

        return $session;
    }

    // =========================================================================
    //  Row builders
    // =========================================================================

    /**
     * @param  Collection<int, Denomination>  $denominations
     */
    private function seedOpeningCounts(TillSession $session, $denominations, float $totalTarget): void
    {
        $this->seedDenominationCounts($session, $denominations, TillCountPhaseEnum::Opening, $totalTarget);
    }

    /**
     * @param  Collection<int, Denomination>  $denominations
     */
    private function seedClosingCounts(TillSession $session, $denominations, float $totalTarget): void
    {
        $this->seedDenominationCounts($session, $denominations, TillCountPhaseEnum::Closing, $totalTarget);
    }

    /**
     * Distribute the target amount across the denomination grid, largest
     * first. The result approximates the target — leftover residue lands in
     * the smallest coin so the closing total exactly equals counted_cash.
     *
     * @param  Collection<int, Denomination>  $denominations
     */
    private function seedDenominationCounts(
        TillSession $session,
        $denominations,
        TillCountPhaseEnum $phase,
        float $totalTarget,
    ): void {
        $remaining = (int) $totalTarget;
        foreach ($denominations as $i => $denom) {
            $value = (int) $denom->value;
            if ($value <= 0) {
                continue;
            }
            $isLast = $i === $denominations->count() - 1;
            $qty = $isLast
                ? (int) max(0, floor($remaining / max(1, $value)))
                : (int) max(0, floor($remaining / $value / 2));
            if ($qty === 0 && ! $isLast) {
                continue;
            }
            $subtotal = $value * $qty;
            $remaining -= $subtotal;
            TillCashDenominationCount::create([
                'session_id' => $session->id,
                'count_phase' => $phase->value,
                'quantity' => $qty,
                'subtotal_amount' => $subtotal,
                'currency_code' => 'JPY',
                'denomination_value' => $value,
                'denomination_kind' => $denom->kind instanceof \BackedEnum ? $denom->kind->value : $denom->kind,
                'denomination_id' => $denom->id,
            ]);
            if ($remaining <= 0) {
                break;
            }
        }
    }

    private function createCashEvent(
        TillSession $session,
        User $actor,
        TillCashEventTypeEnum $type,
        float $amount,
        string $reason,
        Carbon $occurredAt,
    ): void {
        TillCashEvent::create([
            'session_id' => $session->id,
            'event_type' => $type->value,
            'amount' => $amount,
            'currency_code' => 'JPY',
            'reason' => $reason,
            'performed_by_id' => $actor->id,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  Collection<string, TillTenderType>  $tenderTypes
     */
    private function seedSettlementDetails(
        TillSession $session,
        $tenderTypes,
        float $expectedCash,
        float $countedCash,
        ?string $varianceReason,
    ): void {
        // Synthetic split: 60% cash, 25% card, 10% qr, 5% emoney.
        $gross = (float) ($expectedCash + 95_000); // cash + card + qr + emoney total
        $splits = [
            'cash' => $expectedCash,
            'card' => round($gross * 0.25, 0),
            'qr' => round($gross * 0.10, 0),
            'emoney' => round($gross * 0.05, 0),
        ];

        foreach ($tenderTypes as $key => $type) {
            $category = $type->category instanceof TillTenderSystemCategoryEnum
                ? $type->category->value
                : (string) $type->category;
            $expected = (float) ($splits[$category] ?? 0);
            $declared = $category === 'cash'
                ? (float) $countedCash
                : $expected; // non-cash declared == expected — variance only on cash
            $variance = round($declared - $expected, 2);

            TillSettlementTenderDetail::create([
                'session_id' => $session->id,
                'tender_key' => $key,
                'category' => $category,
                'currency_code' => 'JPY',
                'expected_amount' => $expected,
                'declared_gross_amount' => $declared,
                'declared_cancel_amount' => 0,
                'declared_amount' => $declared,
                'terminal_batch_total' => null,
                'variance_amount' => $variance,
                'variance_reason' => $category === 'cash' ? $varianceReason : null,
                'tender_type_id' => $type->id,
            ]);
        }
    }

    /**
     * Stamp a handful of OrderPayment rows so the dashboard's
     * gross_total_amount KPI and the history's gross_revenue column have
     * non-zero data.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function seedOrderPayments(TillSession $session, array $ctx): void
    {
        $methods = PaymentMethod::query()
            ->whereIn('code', ['cash', 'card', 'e_wallet'])
            ->get()
            ->keyBy('code');
        if ($methods->isEmpty()) {
            return; // No payment methods seeded — skip.
        }

        // Find a real customer order to anchor the payment to (the columns
        // are NOT NULL). Pull any order for the same branch.
        $order = CustomerOrder::query()
            ->where('branch_id', $session->branch_id)
            ->orderBy('created_at')
            ->first();
        if (! $order) {
            return;
        }

        $entries = [
            ['code' => 'cash', 'amount' => 84_000],
            ['code' => 'card', 'amount' => 41_300],
            ['code' => 'e_wallet', 'amount' => 16_000],
        ];

        foreach ($entries as $entry) {
            $method = $methods->get($entry['code']);
            if (! $method) {
                continue;
            }
            \DB::table('order_payments')->insert([
                'id' => (string) Str::uuid(),
                'payment_code' => 'PAY-'.strtoupper(Str::random(8)),
                'amount' => $entry['amount'],
                'tip_amount' => 0,
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => $session->closed_at ?? $session->opened_at,
                'till_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'customer_order_id' => $order->id,
                'received_by_id' => $ctx['cashier']->id,
                'branch_id' => $session->branch_id,
                'brand_id' => $session->brand_id,
                'organization_id' => $session->organization_id,
                'created_at' => $session->closed_at ?? $session->opened_at,
                'updated_at' => $session->closed_at ?? $session->opened_at,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function logAudit(
        TillSession $session,
        string $action,
        ?string $userId,
        array $metadata,
        Carbon $when,
    ): void {
        AuditLog::create([
            'auditable_type' => 'TillSession',
            'auditable_id' => $session->id,
            'action' => $action,
            'user_id' => $userId,
            'metadata' => $metadata,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /**
     * Deterministic pseudo-random variance distribution for the 30-day
     * history rows — gives the trend chart a non-trivial shape without
     * depending on Math.random / Date.now (avoids resume-time flake).
     */
    private function randomVariance(int $dayOffset): float
    {
        $pool = [0, 0, 100, -200, 300, -400, 800, -1_200, 0, 500];

        return (float) $pool[$dayOffset % count($pool)];
    }
}
