<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\GenealogyLink;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use App\Models\RecallDrill;
use App\Models\User;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recall + Trace Demo Seeder — COMPREHENSIVE EDITION
 *
 * Goal: every code path in TraceService / RecallService / RecallDrillService
 * has demo data to exercise on a fresh `migrate:fresh --seed`.
 *
 * Trace graph topology covered:
 *
 *   ──────────────────────────────────────────────────────────────────
 *   SCENARIO A — Deep multi-level production chain (3 levels)
 *
 *     supplier_A1 ─┐
 *                  ├──► intermediate_P1 ─┐
 *     supplier_A2 ─┘                      ├──► final_F1 ──► order #1
 *     supplier_A3 ──► intermediate_P2 ────┘            ──► order #2
 *                                                       ──► order #3
 *
 *   SCENARIO B — Direct supplier → sale (no batch in between)
 *     supplier_B ──► order #4
 *                ──► order #5
 *
 *   SCENARIO C — Disposal terminal leaf (waste / expired)
 *     supplier_C ──► disposal
 *
 *   SCENARIO D — Reversal edge (cancelled batch — must be filtered by Trace)
 *     supplier_D ──► reversal_lot   [source_event_type=reversal]
 *
 *   SCENARIO E — Fan-out: one supplier feeds many produced lots
 *     supplier_E ──► produced_E1
 *                ──► produced_E2
 *                ──► produced_E3
 *
 *   SCENARIO F — Fan-in to a single customer order from 2 lots
 *     supplier_F1 ─┐
 *                  ├──► order #6
 *     supplier_F2 ─┘
 *
 *   SCENARIO G — High fan-out sales (15+ orders from one produced lot)
 *     produced_G ──► orders #7 … #N    (exercises near-truncation rendering)
 *
 *   SCENARIO H — Cross-warehouse production (cold → main)
 *     cold_supplier ──► main_produced ──► order #last
 *
 *   ──────────────────────────────────────────────────────────────────
 *
 * Recall coverage:
 *   • 1 × draft   (no initiated_at, awaiting QA decision)
 *   • 3 × active  (scope_type ∈ {lot, supplier_lot, material})
 *   • 2 × completed (one notified in_app, one notified email)
 *   • 1 × cancelled (with cancellation_reason + lots released back to active)
 *   • RecallAffectedOrder rows in 3 states: pending (no notified_at),
 *     in_app notified, email notified.
 *
 * RecallDrill coverage:
 *   • 1 × in-progress  (started_at only, no completed_at — exercises the
 *                      "drill running" badge)
 *   • 2 × completed under SLA (≤ 120s, 100% completeness)
 *   • 1 × completed over SLA (> 120s, partial completeness — FSMA-204 fail)
 *   • 1 × ancient (90+ days old, archival history)
 *   • 1 × low-completeness failure (≈ 40%) — drill identified missing edges
 *
 * Idempotent — skips if at least one Recall already exists for the brand.
 * Wipe with `migrate:fresh --seed` to reset.
 *
 * Standalone run:
 *
 *   docker compose exec -T app php artisan db:seed --class=RecallTraceDemoSeeder
 */
class RecallTraceDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const SCENARIO_G_FAN_OUT = 15;

    public function run(): void
    {
        $org = Organization::first();
        if (! $org) {
            $this->command?->warn('RecallTraceDemo skipped: no Organization — run MockDataSeeder first.');

            return;
        }

        $brand = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();
        if (! $brand) {
            $this->command?->warn('RecallTraceDemo skipped: no active Brand for the seeded Organization.');

            return;
        }

        if (Recall::where('brand_id', $brand->id)->exists()) {
            $this->command?->info('RecallTraceDemo: recalls already exist for this brand — skipping.');

            return;
        }

        $actor = User::where('console_organization_id', $org->console_organization_id)->first();
        if (! $actor) {
            $this->command?->warn('RecallTraceDemo skipped: no User belongs to the seeded Organization.');

            return;
        }

        $supplierLots = MaterialLot::query()
            ->where('organization_id', $org->id)
            ->where('brand_id', $brand->id)
            ->where('source', 'inbound')
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->orderBy('received_at')
            ->get();

        if ($supplierLots->count() < 4) {
            $this->command?->warn('RecallTraceDemo skipped: need >= 4 active inbound lots — run Plan022ComprehensiveDemoSeeder first.');

            return;
        }

        $customerOrders = CustomerOrder::query()
            ->where('brand_id', $brand->id)
            ->orderBy('created_at')
            ->get();

        if ($customerOrders->count() < 3) {
            $this->command?->warn('RecallTraceDemo: < 3 customer orders for brand — sales-edge scenarios will be skipped.');
        }

        $producedLots = $this->buildScenarios($brand, $supplierLots, $customerOrders);

        $this->seedRecalls($org->id, $brand->id, $actor->id, $supplierLots, $producedLots);
        $this->seedRecallDrills($org->id, $brand->id, $actor->id, $supplierLots, $producedLots);

        $this->printSummary($brand, $supplierLots, $producedLots, $customerOrders);
    }

    // =========================================================================
    //  Scenario builder — populates genealogy_links + synthesizes intermediate
    //  produced lots so every Trace code path has data.
    //
    //  @return array<string, MaterialLot>  scenario key → representative lot
    // =========================================================================

    /**
     * @return array<string, MaterialLot>
     */
    private function buildScenarios(Brand $brand, Collection $supplierLots, Collection $customerOrders): array
    {
        $lots = [];

        // The pool: at minimum 4 lots (already validated above). We cycle
        // through them and lean on the production lot factory to mint
        // intermediate nodes so every scenario has a unique pivot.
        $pool = $supplierLots->values();
        $get = fn (int $i) => $pool[$i % $pool->count()];

        // ── SCENARIO A — 3-level production chain ────────────────────────
        $a1 = $get(0);
        $a2 = $get(1);
        $a3 = $get(2);
        $intermediateP1 = $this->resolveProducedLot($brand->id, $a1, 'INT-P1', 800, 600);
        $intermediateP2 = $this->mintProducedLot($brand->id, $a3, 'INT-P2', 600, 450);
        $finalF1 = $this->mintProducedLot($brand->id, $a1, 'FIN-F1', 500, 380);

        // Level 1 — supplier_A{1,2} → intermediate_P1
        $batchAB = (string) Str::uuid();
        $this->edge($a1->id, $intermediateP1->id, null, 300, $a1->unit, now()->subDays(6), 'material_batch', $batchAB);
        $this->edge($a2->id, $intermediateP1->id, null, 200, $a2->unit, now()->subDays(6), 'material_batch', $batchAB);

        // Level 1 — supplier_A3 → intermediate_P2
        $batchA3 = (string) Str::uuid();
        $this->edge($a3->id, $intermediateP2->id, null, 250, $a3->unit, now()->subDays(5), 'material_batch', $batchA3);

        // Level 2 — intermediates → final
        $batchFin = (string) Str::uuid();
        $this->edge($intermediateP1->id, $finalF1->id, null, 350, $intermediateP1->unit, now()->subDays(3), 'material_batch', $batchFin);
        $this->edge($intermediateP2->id, $finalF1->id, null, 220, $intermediateP2->unit, now()->subDays(3), 'material_batch', $batchFin);

        // Level 3 — final → 3 customer orders
        $scenarioASales = $customerOrders->take(3)->values();
        foreach ($scenarioASales as $idx => $order) {
            $this->edge(
                $finalF1->id,
                null,
                (string) $order->id,
                60 + ($idx * 5),
                $finalF1->unit,
                now()->subDay(),
                'customer_order',
                (string) Str::uuid(),
            );
        }
        $lots['scenario_a_root'] = $a1;
        $lots['scenario_a_intermediate'] = $intermediateP1;
        $lots['scenario_a_final'] = $finalF1;

        // ── SCENARIO B — Direct supplier → 2 sales (no batch) ────────────
        if ($pool->count() >= 4 && $customerOrders->count() >= 5) {
            $b = $get(3);
            foreach ($customerOrders->slice(3, 2)->values() as $idx => $order) {
                $this->edge(
                    $b->id, null, (string) $order->id,
                    30 + $idx * 10, $b->unit,
                    now()->subDay(), 'customer_order', (string) Str::uuid(),
                );
            }
            $lots['scenario_b_supplier'] = $b;
        }

        // ── SCENARIO C — Disposal terminal ───────────────────────────────
        if ($pool->count() >= 5) {
            $c = $get(4);
            $this->edge(
                $c->id, null, null, 25, $c->unit,
                now()->subDays(2), 'disposal', (string) Str::uuid(),
            );
            $lots['scenario_c_disposal_root'] = $c;
        }

        // ── SCENARIO D — Reversal edge (cancelled batch) ─────────────────
        if ($pool->count() >= 6) {
            $d = $get(5);
            $reversalLot = $this->mintProducedLot($brand->id, $d, 'REV-D', 100, 0);
            $reversalLot->update(['status' => MaterialLotStatusEnum::Disposed->value]);
            $this->edge(
                $d->id, $reversalLot->id, null, 80, $d->unit,
                now()->subDays(4), 'reversal', (string) Str::uuid(),
            );
            $lots['scenario_d_reversal_root'] = $d;
            $lots['scenario_d_reversal_child'] = $reversalLot;
        }

        // ── SCENARIO E — Fan-out: one supplier → 3 produced lots ─────────
        if ($pool->count() >= 7) {
            $e = $get(6);
            $batchE = (string) Str::uuid();
            for ($i = 1; $i <= 3; $i++) {
                $child = $this->mintProducedLot($brand->id, $e, "FAN-E{$i}", 200, 150);
                $this->edge(
                    $e->id, $child->id, null, 50, $e->unit,
                    now()->subDays(2), 'material_batch', $batchE,
                );
            }
            $lots['scenario_e_fanout_root'] = $e;
        }

        // ── SCENARIO F — Fan-in: 2 suppliers → 1 customer order ──────────
        if ($pool->count() >= 9 && $customerOrders->count() >= 6) {
            $f1 = $get(7);
            $f2 = $get(8);
            $orderF = $customerOrders[5];
            $eventF = (string) Str::uuid();
            $this->edge($f1->id, null, (string) $orderF->id, 20, $f1->unit, now()->subHours(18), 'customer_order', $eventF);
            $this->edge($f2->id, null, (string) $orderF->id, 15, $f2->unit, now()->subHours(18), 'customer_order', $eventF);
            $lots['scenario_f_fanin_a'] = $f1;
            $lots['scenario_f_fanin_b'] = $f2;
        }

        // ── SCENARIO G — Same lot consumed by many orders (15+) ──────────
        if ($customerOrders->count() >= 7) {
            $gSupplier = $get(0);
            $gProduced = $this->mintProducedLot($brand->id, $gSupplier, 'FANOUT-G', 1500, 900);
            $batchG = (string) Str::uuid();
            $this->edge($gSupplier->id, $gProduced->id, null, 600, $gSupplier->unit, now()->subDays(2), 'material_batch', $batchG);

            $available = $customerOrders->slice(6)->values();
            $count = min(self::SCENARIO_G_FAN_OUT, $available->count());
            for ($i = 0; $i < $count; $i++) {
                $this->edge(
                    $gProduced->id, null, (string) $available[$i]->id,
                    20 + ($i % 5) * 2, $gProduced->unit,
                    now()->subHours(6 + $i), 'customer_order', (string) Str::uuid(),
                );
            }
            $lots['scenario_g_high_fanout'] = $gProduced;
        }

        // ── SCENARIO H — Cross-warehouse production ──────────────────────
        // Pick any supplier from a *different* warehouse to form a cross-WH
        // edge when one is available.
        $coldSupplier = $pool->firstWhere('warehouse_id', '!=', $pool[0]->warehouse_id);
        if ($coldSupplier) {
            $crossLot = $this->mintProducedLot($brand->id, $pool[0], 'CROSS-H', 400, 300);
            $batchH = (string) Str::uuid();
            $this->edge($coldSupplier->id, $crossLot->id, null, 180, $coldSupplier->unit, now()->subDays(3), 'material_batch', $batchH);
            if ($customerOrders->isNotEmpty()) {
                $this->edge(
                    $crossLot->id, null, (string) $customerOrders->last()->id,
                    45, $crossLot->unit, now()->subHours(4),
                    'customer_order', (string) Str::uuid(),
                );
            }
            $lots['scenario_h_cross_wh_root'] = $coldSupplier;
            $lots['scenario_h_cross_wh_child'] = $crossLot;
        }

        $this->command?->info('RecallTraceDemo: genealogy scenarios A–H seeded.');

        return $lots;
    }

    // =========================================================================
    //  Produced-lot helpers
    // =========================================================================

    /**
     * Reuse Plan-022's completed-batch output lot if present, otherwise
     * synthesize one tied to the supplier lot's material.
     */
    private function resolveProducedLot(string $brandId, MaterialLot $fallbackSupplier, string $codeHint, float $received, float $onHand): MaterialLot
    {
        $completedBatch = MaterialBatch::query()
            ->where('organization_id', $fallbackSupplier->organization_id)
            ->where('status', 'completed')
            ->whereNotNull('output_lot_id')
            ->orderByDesc('completed_at')
            ->first();

        if ($completedBatch) {
            $lot = MaterialLot::find($completedBatch->output_lot_id);
            if ($lot) {
                return $lot;
            }
        }

        return $this->mintProducedLot($brandId, $fallbackSupplier, $codeHint, $received, $onHand);
    }

    private function mintProducedLot(string $brandId, MaterialLot $supplier, string $codeHint, float $received, float $onHand): MaterialLot
    {
        return MaterialLot::create([
            'lot_code' => "DEMO-{$codeHint}-".now()->format('Ymd').'-'.strtoupper(Str::random(4)),
            'material_id' => $supplier->material_id,
            'warehouse_id' => $supplier->warehouse_id,
            'source' => 'production',
            'status' => MaterialLotStatusEnum::Active->value,
            'supplier_name' => null,
            'supplier_lot_code' => null,
            'received_at' => now()->subDays(3),
            'expiry_date' => now()->addDays(30)->format('Y-m-d'),
            'received_qty' => $received,
            'qty_on_hand' => $onHand,
            'unit' => $supplier->unit,
            'cost_per_unit' => $supplier->cost_per_unit,
            'organization_id' => $supplier->organization_id,
            'brand_id' => $brandId,
        ]);
    }

    private function edge(
        string $parentLotId,
        ?string $childLotId,
        ?string $customerOrderId,
        float $qty,
        ?string $unit,
        \DateTimeInterface $consumedAt,
        string $sourceEventType,
        string $sourceEventId,
    ): void {
        GenealogyLink::create([
            'parent_lot_id' => $parentLotId,
            'child_lot_id' => $childLotId,
            'customer_order_id' => $customerOrderId,
            'qty_consumed' => $qty,
            'unit' => $unit ?? 'kg',
            'consumed_at' => $consumedAt,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $sourceEventId,
        ]);
    }

    // =========================================================================
    //  Recalls — draft / active×3 (every scope_type) / completed×2 / cancelled
    // =========================================================================

    /**
     * @param  array<string, MaterialLot>  $producedLots
     */
    private function seedRecalls(string $orgId, string $brandId, string $actorId, Collection $supplierLots, array $producedLots): void
    {
        $codeSeq = $this->resolveCodeSeq();
        $nextCode = function () use (&$codeSeq) {
            return sprintf('RC-%s-%03d', Carbon::now()->format('Ymd'), $codeSeq++);
        };

        // ── DRAFT — produced lot pivot, not yet initiated ────────────────
        Recall::create([
            'recall_code' => $nextCode(),
            'root_lot_id' => ($producedLots['scenario_a_final'] ?? $supplierLots[0])->id,
            'scope_type' => 'lot',
            'status' => 'draft',
            'reason' => 'demo: pending QA decision on aroma drift in produced batch',
            'affected_lots_count' => 0,
            'affected_orders_count' => 0,
            'initiated_by_id' => $actorId,
            'initiated_at' => null,
            'organization_id' => $orgId,
            'brand_id' => $brandId,
        ]);

        // ── ACTIVE × 3 (every scope_type) ────────────────────────────────
        // (a) scope_type = lot
        $rootLot = $supplierLots[0];
        $activeLot = $this->createActiveRecall(
            $orgId, $brandId, $actorId, $rootLot, 'lot',
            'demo: supplier issued allergen advisory — single inbound lot affected',
            $nextCode(),
        );
        $this->snapshotAffectedOrders($activeLot, /* notify channel */ null);

        // (b) scope_type = supplier_lot — pivot the wider supplier reach
        if ($supplierLots->count() >= 2) {
            $rootSupplierLot = $supplierLots[1];
            $activeSupplier = $this->createActiveRecall(
                $orgId, $brandId, $actorId, $rootSupplierLot, 'supplier_lot',
                'demo: contaminated supplier lot — withdraw whole supplier shipment',
                $nextCode(),
            );
            $this->snapshotAffectedOrders($activeSupplier, /* in_app pending */ null);
        }

        // (c) scope_type = material — broadest scope, pivots on a produced lot
        if (isset($producedLots['scenario_g_high_fanout'])) {
            $rootMaterial = $producedLots['scenario_g_high_fanout'];
            $activeMaterial = $this->createActiveRecall(
                $orgId, $brandId, $actorId, $rootMaterial, 'material',
                'demo: precautionary material-wide recall — large fan-out',
                $nextCode(),
            );
            $this->snapshotAffectedOrders($activeMaterial, /* email partial-notified */ 'in_app');
        }

        // ── COMPLETED × 2 ───────────────────────────────────────────────
        // (a) Notified in_app
        if ($supplierLots->count() >= 3) {
            $completedInApp = Recall::create([
                'recall_code' => $nextCode(),
                'root_lot_id' => $supplierLots[2]->id,
                'scope_type' => 'lot',
                'status' => 'completed',
                'reason' => 'demo: contamination confirmed, full lot withdrawn',
                'affected_lots_count' => 1,
                'affected_orders_count' => 0,
                'initiated_by_id' => $actorId,
                'initiated_at' => now()->subDays(10),
                'completed_at' => now()->subDays(8),
                'organization_id' => $orgId,
                'brand_id' => $brandId,
            ]);
            $this->snapshotAffectedOrders($completedInApp, 'in_app');
        }

        // (b) Notified email
        if ($supplierLots->count() >= 4) {
            $completedEmail = Recall::create([
                'recall_code' => $nextCode(),
                'root_lot_id' => $supplierLots[3]->id,
                'scope_type' => 'supplier_lot',
                'status' => 'completed',
                'reason' => 'demo: cold-chain breach — full withdraw + customer email blast',
                'affected_lots_count' => 1,
                'affected_orders_count' => 0,
                'initiated_by_id' => $actorId,
                'initiated_at' => now()->subDays(30),
                'completed_at' => now()->subDays(28),
                'organization_id' => $orgId,
                'brand_id' => $brandId,
            ]);
            $this->snapshotAffectedOrders($completedEmail, 'email');
        }

        // ── CANCELLED — auto-quarantined lots released back to active ────
        if ($supplierLots->count() >= 5) {
            $cancelledRoot = $supplierLots[4];
            Recall::create([
                'recall_code' => $nextCode(),
                'root_lot_id' => $cancelledRoot->id,
                'scope_type' => 'lot',
                'status' => 'cancelled',
                'reason' => 'demo: precautionary recall opened by night shift',
                'affected_lots_count' => 1,
                'affected_orders_count' => 0,
                'initiated_by_id' => $actorId,
                'initiated_at' => now()->subDays(3),
                'cancelled_at' => now()->subDays(2),
                'cancellation_reason' => 'demo: lab re-test came back clean, withdrawal cancelled',
                'organization_id' => $orgId,
                'brand_id' => $brandId,
            ]);
        }

        $this->command?->info('RecallTraceDemo: 7 recalls (1 draft + 3 active + 2 completed + 1 cancelled) across every scope_type.');
    }

    /**
     * Mirror RecallService::initiate: walk downstream, snapshot counts,
     * auto-quarantine reachable Active lots.
     */
    private function createActiveRecall(string $orgId, string $brandId, string $actorId, MaterialLot $root, string $scopeType, string $reason, string $code): Recall
    {
        $affectedLots = array_unique(array_merge([$root->id], $this->walkDownstream($root->id)));
        $affectedOrders = GenealogyLink::query()
            ->whereIn('parent_lot_id', $affectedLots)
            ->where('source_event_type', 'customer_order')
            ->whereNotNull('customer_order_id')
            ->distinct()
            ->pluck('customer_order_id')
            ->all();

        MaterialLot::whereIn('id', $affectedLots)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->update([
                'status' => MaterialLotStatusEnum::Quarantined->value,
                'quarantine_reason' => 'recall:'.$reason,
            ]);

        $recall = Recall::create([
            'recall_code' => $code,
            'root_lot_id' => $root->id,
            'scope_type' => $scopeType,
            'status' => 'active',
            'reason' => $reason,
            'affected_lots_count' => count($affectedLots),
            'affected_orders_count' => count($affectedOrders),
            'initiated_by_id' => $actorId,
            'initiated_at' => now()->subHours(6),
            'organization_id' => $orgId,
            'brand_id' => $brandId,
        ]);

        foreach ($affectedOrders as $orderId) {
            RecallAffectedOrder::firstOrCreate([
                'recall_id' => $recall->id,
                'customer_order_id' => $orderId,
                'notification_channel' => null,
            ]);
        }

        return $recall;
    }

    /**
     * Re-walk for the given recall, then add a RecallAffectedOrder row per
     * downstream order tagged with the requested channel + notified_at.
     */
    private function snapshotAffectedOrders(Recall $recall, ?string $channel): void
    {
        if ($channel === null) {
            return; // Pending channels already created by createActiveRecall.
        }

        $affectedLots = array_unique(array_merge(
            [$recall->root_lot_id],
            $this->walkDownstream($recall->root_lot_id),
        ));
        $orderIds = GenealogyLink::query()
            ->whereIn('parent_lot_id', $affectedLots)
            ->where('source_event_type', 'customer_order')
            ->whereNotNull('customer_order_id')
            ->distinct()
            ->pluck('customer_order_id')
            ->all();

        foreach ($orderIds as $orderId) {
            RecallAffectedOrder::firstOrCreate([
                'recall_id' => $recall->id,
                'customer_order_id' => $orderId,
                'notification_channel' => $channel,
            ], [
                'notified_at' => now()->subDays(2),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function walkDownstream(string $rootLotId): array
    {
        $visited = [];
        $queue = [$rootLotId];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $children = GenealogyLink::query()
                ->where('parent_lot_id', $current)
                ->whereNotNull('child_lot_id')
                ->where('source_event_type', '!=', 'reversal')
                ->pluck('child_lot_id')
                ->all();
            foreach ($children as $childId) {
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }
        unset($visited[$rootLotId]);

        return array_keys($visited);
    }

    private function resolveCodeSeq(): int
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "RC-{$today}-";
        $last = Recall::where('recall_code', 'like', $prefix.'%')
            ->orderByDesc('recall_code')
            ->value('recall_code');

        return $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
    }

    // =========================================================================
    //  Recall drills — exercise every UI badge state
    // =========================================================================

    /**
     * @param  array<string, MaterialLot>  $producedLots
     */
    private function seedRecallDrills(string $orgId, string $brandId, string $actorId, Collection $supplierLots, array $producedLots): void
    {
        $pool = $supplierLots->values();
        $get = fn (int $i) => $pool[$i % $pool->count()];

        $defs = [
            // 1. In-progress drill — started_at only, no completed_at.
            [
                'lot' => $get(0),
                'started_at' => now()->subMinutes(2),
                'affected_lots_at' => null,
                'affected_orders_at' => null,
                'completed_at' => null,
                'elapsed' => null,
                'affected_lots' => 0,
                'affected_orders' => 0,
                'completeness' => 0.00,
                'notes' => 'in-progress drill — staff currently running',
            ],
            // 2. SLA-passing quarterly drill, 100% completeness.
            [
                'lot' => $get(0),
                'started_at' => now()->subDays(14),
                'affected_lots_at' => null,
                'affected_orders_at' => null,
                'completed_at' => null,
                'elapsed' => 87,
                'affected_lots' => 3,
                'affected_orders' => 12,
                'completeness' => 100.00,
                'notes' => 'quarterly FSMA-204 drill — full chain traced under SLA',
            ],
            // 3. SLA-passing fast warm-up drill.
            [
                'lot' => $get(1),
                'started_at' => now()->subHours(36),
                'completed_at' => null,
                'elapsed' => 42,
                'affected_lots' => 1,
                'affected_orders' => 1,
                'completeness' => 100.00,
                'notes' => 'daily warm-up drill — sub-minute trace',
            ],
            // 4. SLA fail — over 120s, partial completeness.
            [
                'lot' => $get(2),
                'started_at' => now()->subDays(7),
                'elapsed' => 145,
                'affected_lots' => 2,
                'affected_orders' => 4,
                'completeness' => 75.00,
                'notes' => 'spot-check drill — exceeded 120s SLA; one missing sales edge surfaced',
            ],
            // 5. Ancient archival drill — 90+ days old.
            [
                'lot' => $get(3 % max($pool->count(), 1)),
                'started_at' => now()->subDays(95),
                'elapsed' => 110,
                'affected_lots' => 4,
                'affected_orders' => 18,
                'completeness' => 100.00,
                'notes' => 'archival — pre-quarter audit drill',
            ],
            // 6. Failed drill — low completeness (~40%).
            [
                'lot' => $get(2 % max($pool->count(), 1)),
                'started_at' => now()->subDays(21),
                'elapsed' => 200,
                'affected_lots' => 5,
                'affected_orders' => 8,
                'completeness' => 40.00,
                'notes' => 'FAILED drill — multiple missing sales edges; remediation ticket opened',
            ],
        ];

        foreach ($defs as $def) {
            $started = $def['started_at'];
            $elapsed = $def['elapsed'];
            $completedAt = $elapsed === null ? null : (clone $started)->addSeconds($elapsed);
            $affectedLotsAt = $elapsed === null ? null : (clone $started)->addSeconds((int) ($elapsed * 0.3));
            $affectedOrdersAt = $elapsed === null ? null : (clone $started)->addSeconds((int) ($elapsed * 0.7));

            RecallDrill::create([
                'triggered_by_id' => $actorId,
                'selected_lot_id' => $def['lot']->id,
                'started_at' => $started,
                'affected_lots_at' => $affectedLotsAt,
                'affected_orders_at' => $affectedOrdersAt,
                'completed_at' => $completedAt,
                'elapsed_seconds' => $elapsed,
                'affected_lots_count' => $def['affected_lots'],
                'affected_orders_count' => $def['affected_orders'],
                'completeness_percent' => $def['completeness'],
                'notes' => $def['notes'],
                'organization_id' => $orgId,
                'brand_id' => $brandId,
            ]);
        }

        $this->command?->info('RecallTraceDemo: 6 recall drills (in-progress / SLA pass×2 / SLA fail / ancient / failed-low-completeness).');
    }

    // =========================================================================
    //  Summary
    // =========================================================================

    /**
     * @param  array<string, MaterialLot>  $producedLots
     */
    private function printSummary(Brand $brand, Collection $supplierLots, array $producedLots, Collection $customerOrders): void
    {
        $this->command?->info('────────── Recall + Trace Demo Summary ──────────');
        $this->command?->info("Brand: {$brand->slug}  (use this as {brandSlug} in URLs)");

        $sampleSupplier = $supplierLots[0];
        $sampleFinal = $producedLots['scenario_a_final'] ?? null;
        $sampleFanout = $producedLots['scenario_g_high_fanout'] ?? null;

        $this->command?->info('Trace endpoints to try:');
        $this->command?->info("  GET /api/v1/hq/{$brand->slug}/trace/lot/{$sampleSupplier->id}?direction=forward   # 3-level chain forward");
        if ($sampleFinal) {
            $this->command?->info("  GET /api/v1/hq/{$brand->slug}/trace/lot/{$sampleFinal->id}?direction=backward  # multi-parent traversal");
        }
        if ($sampleFanout) {
            $this->command?->info("  GET /api/v1/hq/{$brand->slug}/trace/lot/{$sampleFanout->id}?direction=forward   # high fan-out (15+ sales)");
        }
        if ($customerOrders->isNotEmpty()) {
            $this->command?->info("  GET /api/v1/hq/{$brand->slug}/trace/customer-order/{$customerOrders[0]->id}");
        }

        $this->command?->info('Recall endpoints to try:');
        $this->command?->info("  POST /api/v1/hq/{$brand->slug}/recalls/preview   body: { root_lot_id: \"{$sampleSupplier->id}\" }");
        $this->command?->info("  GET  /api/v1/hq/{$brand->slug}/recalls");
        $this->command?->info("  GET  /api/v1/hq/{$brand->slug}/recalls?status=active");
        $this->command?->info("  GET  /api/v1/hq/{$brand->slug}/recalls?status=completed");
        $this->command?->info("  GET  /api/v1/hq/{$brand->slug}/recalls/drills");
        $this->command?->info('──────────────────────────────────────────────────');
    }
}
