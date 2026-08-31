<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Recall;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialBatchStatusEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\ExpiryAlertService;
use App\Services\Inventory\MaterialBatchService;
use App\Services\Inventory\MaterialLotService;
use App\Services\Inventory\RecallService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Plan-017 demo seeder — drops a comprehensive demo dataset that
 * exercises every UI surface added in plan-017:
 *
 *   - Lot-grain cost (unit_cost / total_cost / currency / cost_basis)
 *     so the Cost section + production batch breakdown render
 *   - Status variety (active / quarantined / expired) so the materials
 *     list active-lots column + lot-detail filters have all states
 *   - Per-material expiry_alert_thresholds so MOD-1 "Expiring soon"
 *     badge fires + ExpiryAlert rows get populated
 *   - Temperature CCP — flagged materials with both an in-range and
 *     an out-of-range lot so the compliance Badge has both true /
 *     false to render
 *   - Demo Recall — exercises the recall list / detail flow with
 *     non-zero affected counts
 *
 * Self-sufficient: creates warehouses + lots from scratch via
 * MaterialLotService::receive (so the receive flow's audit log /
 * stock_in transaction / stock_levels rows are real, not faked).
 *
 * Run AFTER MaterialSeeder (which seeds the materials):
 *   docker compose exec -T app php artisan db:seed --class=AllergenSeeder
 *   docker compose exec -T app php artisan db:seed --class=MaterialSeeder
 *   docker compose exec -T app php artisan db:seed --class=Plan017DemoSeeder
 *
 * Idempotent — re-running skips lots/recalls that already exist.
 */
class Plan017DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const SUPPLIER_POOL = ['日本製粉', 'メイトーフード', 'カゴメ', '味の素'];

    public function run(
        MaterialLotService $lotService,
        MaterialBatchService $batchService,
        ExpiryAlertService $expiryAlertService,
        RecallService $recallService,
    ): void {
        $org = Organization::first();
        if (! $org) {
            $this->command?->warn('Plan017DemoSeeder skipped: no Organization seeded.');

            return;
        }

        $materials = Material::where('organization_id', $org->id)->get();
        if ($materials->isEmpty()) {
            $this->command?->warn('Plan017DemoSeeder skipped: run MaterialSeeder first.');

            return;
        }

        // Pin authenticated user so OrderClosingService-style fallbacks
        // (auth()->id()) inside the chain don't trip on null.
        $user = User::where('console_organization_id', $org->console_organization_id)->first();
        if ($user) {
            Auth::login($user);
        }
        $userId = $user?->id;

        // Configure flags BEFORE seeding lots so receive() validates
        // against the new ranges + the lots inherit their state.
        $this->configureTemperatureCcp($materials);
        $this->configureExpiryThresholds($materials);

        $materials = $materials->fresh();

        $this->seedInboundLots($lotService, $org->id, $materials, $userId);
        $this->varyLotStatuses($materials);
        $this->runDemoBatch($org->id, $batchService);
        $this->fireExpiryAlerts($expiryAlertService);
        $this->initiateDemoRecall($org->id, $recallService);

        $this->command?->info('Plan017DemoSeeder: done — UI surfaces populated.');
    }

    /**
     * Tier 1.D — flag the first 2 materials with requires_temperature_check
     * and a cold-chain min/max range. Receive form will surface the
     * temperature card; lot detail will show compliance badge.
     */
    private function configureTemperatureCcp(Collection $materials): void
    {
        $count = 0;
        foreach ($materials->take(2) as $material) {
            if ($material->requires_temperature_check) {
                continue;
            }
            $material->update([
                'requires_temperature_check' => true,
                'temperature_min' => -2.0,
                'temperature_max' => 4.0,
            ]);
            $count++;
        }
        $this->command?->info("Plan017Demo: flagged {$count} materials with temp CCP.");
    }

    /**
     * Tier 1.C — set a tight per-material override on every 4th material
     * so the expiry alert cron has both default-threshold and override-
     * threshold cases.
     */
    private function configureExpiryThresholds(Collection $materials): void
    {
        $count = 0;
        foreach ($materials as $idx => $material) {
            if ($idx % 4 !== 0 || $material->expiry_alert_thresholds !== null) {
                continue;
            }
            $material->update(['expiry_alert_thresholds' => [10, 5, 2]]);
            $count++;
        }
        $this->command?->info("Plan017Demo: configured tight expiry thresholds on {$count} materials.");
    }

    /**
     * Use MaterialLotService::receive to mint inbound lots through the
     * real flow — so we get the auto stock_in transaction, stock_levels
     * row at lot grain, audit log, and cost stamping all consistent
     * with how production runs them.
     *
     * Each material gets:
     *   - 1 fresh lot (90d expiry, ¥cost-pool, JPY)
     *   - 1 expiring-soon lot (5d expiry, ¥cost-pool, JPY)
     *   - For every 3rd material: 1 expired lot for the disposal demo
     *   - For temp-CCP materials: 1 in-range + 1 out-of-range receipt
     */
    private function seedInboundLots(
        MaterialLotService $lotService,
        string $orgId,
        Collection $materials,
        ?string $userId,
    ): void {
        $created = 0;
        foreach ($materials as $idx => $material) {
            $existingLots = $material->lots()->count();
            if ($existingLots > 0) {
                continue;
            }

            $warehouse = $this->ensureWarehouseForBrand($orgId, (string) $material->brand_id);
            if (! $warehouse) {
                $this->command?->warn("Plan017Demo: no warehouse resolved for material {$material->sku} — skipping.");

                continue;
            }

            $supplier = self::SUPPLIER_POOL[$idx % count(self::SUPPLIER_POOL)];
            $unitCost = (float) ($material->calculated_cost ?? 100) * 0.85;
            $unit = $material->yield_unit ?? 'kg';

            $defaultPlan = [
                ['days_out' => 90, 'qty' => 5000],
                ['days_out' => 5, 'qty' => 1000],
            ];
            if ($idx % 3 === 0) {
                $defaultPlan[] = ['days_out' => -2, 'qty' => 200];
            }

            // Standard inbound lots — no temperature data unless material
            // requires it (then send in-range to keep these "normal").
            foreach ($defaultPlan as $plan) {
                $payload = [
                    'organization_id' => $orgId,
                    'material_id' => $material->id,
                    'warehouse_id' => $warehouse->id,
                    'supplier_name' => $supplier,
                    'supplier_lot_code' => sprintf('SUP-%s-%d', strtoupper(substr($material->sku ?? 'GEN', -4)), abs($plan['days_out'])),
                    'received_at' => now()->subDays(max(1, abs($plan['days_out']) + 1)),
                    'expiry_date' => now()->addDays($plan['days_out'])->format('Y-m-d'),
                    'received_qty' => (float) $plan['qty'],
                    'unit' => $unit,
                    'cost_per_unit' => $unitCost,
                    'currency' => 'JPY',
                    'created_by_id' => $userId,
                ];
                if ($material->requires_temperature_check) {
                    $payload['received_temperature'] = 2.0;
                }

                try {
                    $lotService->receive($payload);
                    $created++;
                } catch (\Throwable $e) {
                    $this->command?->warn("Plan017Demo: receive skip {$material->sku} ({$plan['days_out']}d): {$e->getMessage()}");
                }
            }

            // Tier 1.D demo — second receipt out-of-range with override
            // reason (only for temperature-flagged materials).
            if ($material->requires_temperature_check) {
                try {
                    $lotService->receive([
                        'organization_id' => $orgId,
                        'material_id' => $material->id,
                        'warehouse_id' => $warehouse->id,
                        'supplier_name' => $supplier,
                        'supplier_lot_code' => sprintf('SUP-%s-COLD-OVR', strtoupper(substr($material->sku ?? 'GEN', -4))),
                        'received_at' => now()->subDay(),
                        'expiry_date' => now()->addDays(20)->format('Y-m-d'),
                        'received_qty' => 800,
                        'unit' => $unit,
                        'cost_per_unit' => $unitCost,
                        'currency' => 'JPY',
                        'received_temperature' => 8.5,
                        'temperature_override_reason' => 'demo: cooler door open during unload (3 min)',
                        'created_by_id' => $userId,
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $this->command?->warn("Plan017Demo: temp-override receive skip: {$e->getMessage()}");
                }
            }
        }

        $this->command?->info("Plan017Demo: received {$created} demo lots (cost + currency stamped, temp data on flagged materials).");
    }

    /**
     * Spread lot status across the FEFO + lifecycle states so the lot
     * status filter + active-lots count have non-trivial inputs.
     */
    private function varyLotStatuses(Collection $materials): void
    {
        $changed = 0;
        foreach ($materials as $idx => $material) {
            $lots = $material->lots()
                ->where('status', MaterialLotStatusEnum::Active->value)
                ->orderBy('id')
                ->get();
            if ($lots->count() < 2) {
                continue;
            }

            // Every 3rd material gets one quarantined lot.
            if ($idx % 3 === 0) {
                $lots->last()->update([
                    'status' => MaterialLotStatusEnum::Quarantined->value,
                    'quarantine_reason' => 'demo: pending QA result',
                ]);
                $changed++;
            }
        }
        $this->command?->info("Plan017Demo: status-varied {$changed} lots.");
    }

    /**
     * Run a MaterialBatch through start() → complete() so the
     * production batch detail page has FEFO-stamped items + a real
     * output lot + non-empty cost breakdown to render.
     */
    private function runDemoBatch(string $orgId, MaterialBatchService $service): void
    {
        $existing = MaterialBatch::query()
            ->where('organization_id', $orgId)
            ->where('status', MaterialBatchStatusEnum::Completed->value)
            ->whereNotNull('output_lot_id')
            ->count();

        if ($existing > 0) {
            $this->command?->info("Plan017Demo: {$existing} completed batch(es) with output lots already exist — skipping.");

            return;
        }

        // Pick an output material with at least 2 other materials sharing the
        // same brand+warehouse so we can build a 2-ingredient demo batch.
        $output = Material::where('organization_id', $orgId)
            ->whereNotNull('yield_unit')
            ->skip(1)->first();
        if (! $output) {
            $this->command?->info('Plan017Demo: not enough materials for demo batch — skipping.');

            return;
        }

        $components = Material::where('organization_id', $orgId)
            ->where('id', '!=', $output->id)
            ->where('brand_id', $output->brand_id)
            ->whereHas('lots', fn ($q) => $q->where('status', MaterialLotStatusEnum::Active->value)
                ->where('qty_on_hand', '>', 0))
            ->limit(2)
            ->get();

        if ($components->count() < 2) {
            $this->command?->info('Plan017Demo: < 2 components with active lots — skipping demo batch.');

            return;
        }

        $warehouse = Warehouse::where('organization_id', $orgId)
            ->whereHas('branch', fn ($q) => $q->where('console_brand_id', $output->brand_id))
            ->where('is_active', true)
            ->first();
        if (! $warehouse) {
            $this->command?->info('Plan017Demo: no warehouse for demo batch — skipping.');

            return;
        }

        $user = Auth::user();
        $userId = (string) ($user?->id ?? '00000000-0000-0000-0000-000000000001');

        try {
            $batch = $service->create([
                'organization_id' => $orgId,
                'warehouse_id' => $warehouse->id,
                'material_id' => $output->id,
                'multiplier' => 1.0,
                'planned_yield' => 100,
                'yield_unit' => $output->yield_unit ?? 'kg',
                'note' => 'Plan-017 demo batch — exercises FEFO consumption + cost breakdown.',
                'created_by_id' => $userId,
                'items' => $components->map(fn ($m) => [
                    'component_type' => 'material',
                    'material_id' => $m->id,
                    'planned_quantity' => 10.0,
                    'unit' => $m->yield_unit ?? 'kg',
                ])->all(),
            ]);

            $batch = $service->submit($batch->fresh());
            // submit() may already have flipped to approved (auto_approve_batch).
            if ($batch->status === MaterialBatchStatusEnum::Pending) {
                $batch = $service->approve($batch->fresh(), $userId);
            }
            $batch = $service->start($batch->fresh());
            $batch = $service->complete($batch->fresh(), $userId, 95.0);

            $this->command?->info("Plan017Demo: completed demo batch {$batch->batch_code} → output_lot minted with cost breakdown.");
        } catch (\Throwable $e) {
            $this->command?->warn("Plan017Demo: demo batch run failed: {$e->getMessage()}");
        }
    }

    /**
     * Tier 1.C — run the daily expiry sweep so the lot-detail "Until
     * expiry" Badge has matching ExpiryAlert rows.
     */
    private function fireExpiryAlerts(ExpiryAlertService $service): void
    {
        // Sweep across 11 days so all common thresholds (10 / 7 / 5 / 3 / 2 / 1d)
        // have a chance to fire on at least one lot.
        $today = Carbon::today();
        $totalCreated = 0;
        for ($daysAhead = 0; $daysAhead < 11; $daysAhead++) {
            $result = $service->scan($today->copy()->addDays($daysAhead));
            $totalCreated += $result['alerts_created'];
        }
        $this->command?->info("Plan017Demo: fired {$totalCreated} ExpiryAlert rows across the next-11-day sweep.");
    }

    /**
     * Tier 1.A — initiate one demo Recall on a lot. Picks a recent
     * active inbound lot so the genealogy walk is non-trivial.
     */
    private function initiateDemoRecall(string $orgId, RecallService $service): void
    {
        $existing = Recall::where('organization_id', $orgId)->count();
        if ($existing > 0) {
            $this->command?->info("Plan017Demo: {$existing} recall(s) already present — skipping.");

            return;
        }

        $rootLot = MaterialLot::query()
            ->where('organization_id', $orgId)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('source', 'inbound')
            ->orderByDesc('received_at')
            ->first();

        if (! $rootLot) {
            $this->command?->info('Plan017Demo: no active inbound lot for demo recall — skipping.');

            return;
        }

        $user = Auth::user();

        try {
            $recall = $service->initiate([
                'organization_id' => $orgId,
                'root_lot_id' => $rootLot->id,
                'reason' => 'Demo recall — supplier QA flagged Salmonella positive on a parallel lot from this supplier; precautionary withdrawal.',
                'scope_type' => 'lot',
                'initiated_by_id' => $user?->id,
            ]);
            $this->command?->info("Plan017Demo: initiated demo recall {$recall->recall_code} ({$recall->affected_lots_count} lots, {$recall->affected_orders_count} orders).");
        } catch (\Throwable $e) {
            $this->command?->warn("Plan017Demo: demo recall failed: {$e->getMessage()}");
        }
    }

    /**
     * Resolve (or create) a warehouse whose branch belongs to the
     * material's brand — receive() rejects cross-brand inputs. Creates
     * the missing branch if needed (BranchSeeder uses placeholder
     * console_brand_id values that don't match materials seeded from
     * Console).
     */
    private function ensureWarehouseForBrand(string $orgId, string $brandId): ?Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($q) => $q->where('console_brand_id', $brandId))
            ->first();
        if ($warehouse) {
            return $warehouse;
        }

        $branch = Branch::query()
            ->where('console_brand_id', $brandId)
            ->first();
        if (! $branch) {
            $org = Organization::find($orgId);
            if (! $org) {
                return null;
            }
            $branch = Branch::create([
                'console_organization_id' => $org->console_organization_id,
                'console_branch_id' => (string) Str::uuid(),
                'console_brand_id' => $brandId,
                'name' => 'Plan-017 Demo Branch',
                'slug' => 'plan017-demo-'.substr($brandId, 0, 8),
                'is_active' => true,
                'is_headquarters' => false,
            ]);
        }

        return Warehouse::create([
            'organization_id' => $orgId,
            'branch_id' => $branch->id,
            'code' => 'PLAN017-DEMO',
            'name' => 'Plan-017 Demo Warehouse',
            'type' => 'main',
            'is_active' => true,
            'auto_approve_stock_in' => true,
            'auto_approve_stock_out' => true,
        ]);
    }
}
