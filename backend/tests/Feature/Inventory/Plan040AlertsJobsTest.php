<?php

/**
 * plan-040 Phase F — Alerts & scheduled jobs (Cluster F).
 *
 * Covers TESTS.md scenarios:
 *   H14        — expiry alert recipients = warehouse-manager scoped to warehouse.
 *   H15/M3     — lot-aware (material-total) alert keys: FEFO flapping does not
 *                duplicate alert rows; an unrelated lot does not resolve a
 *                still-low alert.
 *   L9         — low-stock boundary unified to `<=` across display + fire.
 *   L10        — unknown `?sort=` falls back to created_at (no 500).
 *   M17        — expires-today (daysUntil=0) fires; unified with auto-expire.
 *   M18        — per-branch timezone in the expiry sweep AND auto-expire.
 *   NEW-STK-7  — auto-created StockLevel defaults alert_enabled = true.
 *   M14 (TA.6) — two lot-less writes land on one canonical StockLevel row.
 *   L12        — ExpiryAlertService::scan eager-loads warehouse (no N+1).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ExpiryAlert;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\ExpiryAlertService;
use App\Services\Inventory\StockAlertService;
use App\Services\Inventory\StockLevelService;
use App\Services\Inventory\StockTransactionService;
use App\Services\Notification\Audience;
use App\Services\Notification\AudienceResolverService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unique top-level helper (bare `makeLot` exists elsewhere and would fatally
 * redeclare).
 *
 * @return array{org_id: string, brand: Brand, branch: Branch, warehouse: Warehouse, material: Material}
 */
function makeAlertSetup(array $branchOverrides = [], array $warehouseOverrides = []): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(array_merge([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->id,
    ], $branchOverrides));
    $warehouse = Warehouse::factory()->create(array_merge([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ], $warehouseOverrides));
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    return compact('brand', 'branch', 'warehouse', 'material') + ['org_id' => $orgId];
}

/** Seed an active lot + matching stock level for alert/FEFO tests (#2416). */
function seedAlertLotStock(array $s, float $qty, array $levelOverrides = []): MaterialLot
{
    $minStock = $levelOverrides['min_stock'] ?? 10;
    unset($levelOverrides['min_stock']);

    // Material-level alert config lives on the canonical lot-less row.
    StockLevel::factory()->create(array_merge([
        'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['material']->id,
        'material_lot_id' => null,
        'quantity' => 0,
        'unit' => 'g',
        'min_stock' => $minStock,
        'alert_enabled' => true,
    ], $levelOverrides));

    $lot = MaterialLot::factory()->create([
        'organization_id' => $s['org_id'],
        'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id,
        'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => $qty,
        'qty_on_hand' => $qty,
        'expiry_date' => Carbon::today('Asia/Tokyo')->addDays(30)->toDateString(),
    ]);
    StockLevel::factory()->create([
        'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['material']->id,
        'material_lot_id' => $lot->id,
        'quantity' => $qty,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    return $lot;
}

/** Capture every NotificationService::dispatch call into $captured. */
function spyOnDispatch(array &$captured): void
{
    test()->instance(NotificationService::class, new class($captured) extends NotificationService
    {
        public function __construct(public array &$captured) {}

        public function dispatch(array $input): Notification
        {
            $this->captured[] = $input;

            return new Notification(['id' => (string) Str::uuid(), 'type' => $input['type'] ?? 'x']);
        }
    });
}

afterEach(fn () => Carbon::setTestNow());

// ── H14 ──────────────────────────────────────────────────────────────────────
it('sends expiry alerts only to warehouse-manager users scoped to the warehouse (no org-wide blast, no limit 50)', function () {
    $s = makeAlertSetup();

    $manager = User::factory()->create(['console_organization_id' => $s['org_id']]);
    WarehouseMember::factory()->create(['warehouse_id' => $s['warehouse']->id, 'user_id' => $manager->id, 'role' => 'manager']);

    // Manager of a sibling warehouse in the same org — must be excluded.
    $otherWarehouse = Warehouse::factory()->create(['organization_id' => $s['org_id'], 'branch_id' => $s['branch']->id]);
    $otherManager = User::factory()->create(['console_organization_id' => $s['org_id']]);
    WarehouseMember::factory()->create(['warehouse_id' => $otherWarehouse->id, 'user_id' => $otherManager->id, 'role' => 'manager']);

    MaterialLot::factory()->create([
        'organization_id' => $s['org_id'],
        'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id,
        'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'expiry_date' => Carbon::today('Asia/Tokyo')->addDays(7)->toDateString(),
    ]);

    $captured = [];
    spyOnDispatch($captured);

    app(ExpiryAlertService::class)->scan();

    expect($captured)->toHaveCount(1);
    $recipients = $captured[0]['recipients'];
    expect($recipients)->toBeInstanceOf(Audience::class);

    $resolved = app(AudienceResolverService::class)
        ->resolveWithTrace($recipients->toRule(), $s['brand']);
    $ids = collect($resolved['recipients'])->map(fn ($r) => $r->getKey())->all();

    expect($ids)->toContain($manager->id)
        ->and($ids)->not->toContain($otherManager->id);
});

// ── H15 / M3 ─────────────────────────────────────────────────────────────────
it('does not duplicate alert rows when one FEFO stock-out flaps a single material (lot-aware aggregation)', function () {
    $s = makeAlertSetup(warehouseOverrides: ['allow_negative_sales' => true]);

    StockLevel::factory()->create([
        'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['material']->id,
        'material_lot_id' => null,
        'quantity' => 5,
        'min_stock' => 8,
        'alert_enabled' => true,
        'unit' => 'g',
    ]);

    $service = app(StockTransactionService::class);
    $txn = $service->create([
        'organization_id' => $s['org_id'],
        'warehouse_id' => $s['warehouse']->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [['material_id' => $s['material']->id, 'quantity' => 10, 'unit' => 'g']],
    ]);
    $service->submit($txn);

    // FEFO split the single stock-out into multiple line items; exactly one
    // active alert row exists for the (warehouse, material) key.
    expect(StockAlert::where('warehouse_id', $s['warehouse']->id)
        ->where('material_id', $s['material']->id)
        ->where('status', 'active')
        ->count())->toBe(1);
});

it('keeps a low-stock alert active until the material TOTAL recovers above min (not resolved by a partial replenish)', function () {
    $s = makeAlertSetup();

    seedAlertLotStock($s, 20, ['min_stock' => 10]);

    $service = app(StockTransactionService::class);
    $stockOut = function (float $qty) use ($service, $s) {
        $txn = $service->create([
            'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
            'type' => 'stock_out', 'sub_type' => 'sales', 'created_by_id' => (string) Str::uuid(),
            'items' => [['material_id' => $s['material']->id, 'quantity' => $qty, 'unit' => 'g']],
        ]);
        $service->submit($txn);
    };
    $stockIn = function (float $qty) use ($service, $s) {
        $txn = $service->create([
            'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
            'type' => 'stock_in', 'sub_type' => 'purchase', 'created_by_id' => (string) Str::uuid(),
            'items' => [['material_id' => $s['material']->id, 'quantity' => $qty, 'unit' => 'g']],
        ]);
        $service->submit($txn);
    };

    $activeCount = fn () => StockAlert::where('warehouse_id', $s['warehouse']->id)
        ->where('material_id', $s['material']->id)->where('status', 'active')->count();

    $stockOut(15);                  // total 5 (<=10) → low alert fires
    expect($activeCount())->toBe(1);

    $stockIn(3);                    // total 8 (<=10) → still low, NOT resolved
    expect($activeCount())->toBe(1);

    $stockIn(5);                    // total 13 (>10) → resolved
    expect($activeCount())->toBe(0);
});

// ── L9 ───────────────────────────────────────────────────────────────────────
it('treats quantity == min_stock as low on both the display filter and the alert-fire path', function () {
    $s = makeAlertSetup();

    seedAlertLotStock($s, 15, ['min_stock' => 10]);

    // Fire path: stock-out down to exactly min_stock fires a low_stock alert.
    $service = app(StockTransactionService::class);
    $txn = $service->create([
        'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
        'type' => 'stock_out', 'sub_type' => 'sales', 'created_by_id' => (string) Str::uuid(),
        'items' => [['material_id' => $s['material']->id, 'quantity' => 5, 'unit' => 'g']],
    ]);
    $service->submit($txn);

    expect(StockAlert::where('warehouse_id', $s['warehouse']->id)
        ->where('material_id', $s['material']->id)
        ->where('alert_type', 'low_stock')
        ->where('status', 'active')->exists())->toBeTrue();

    // Display path: stock_status=low includes the quantity==min row.
    $page = app(StockLevelService::class)->list([
        'warehouse_id' => $s['warehouse']->id,
        'stock_status' => 'low',
    ]);
    expect($page->total())->toBe(1);
});

// ── L10 ──────────────────────────────────────────────────────────────────────
it('falls back to created_at when the alert list is sorted by an unknown column', function () {
    $s = makeAlertSetup();
    StockAlert::create([
        'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['material']->id, 'alert_type' => 'low_stock',
        'current_quantity' => 1, 'min_stock' => 10, 'unit' => 'g', 'status' => 'active',
    ]);

    $page = app(StockAlertService::class)->list([
        'organization_id' => $s['org_id'],
        'sort' => 'totally; drop table',
    ]);

    expect($page->total())->toBe(1);
});

// ── M17 ──────────────────────────────────────────────────────────────────────
it('fires an expires-today (threshold 0) alert and auto-expire flips only once past the day boundary', function () {
    $s = makeAlertSetup();
    $tz = 'Asia/Tokyo';

    $todayLot = MaterialLot::factory()->create([
        'organization_id' => $s['org_id'], 'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id, 'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'expiry_date' => Carbon::today($tz)->toDateString(),
    ]);
    $pastLot = MaterialLot::factory()->create([
        'organization_id' => $s['org_id'], 'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id, 'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'expiry_date' => Carbon::today($tz)->subDay()->toDateString(),
    ]);

    $result = app(ExpiryAlertService::class)->scan();

    // The expires-today lot fires a threshold-0 alert.
    expect(ExpiryAlert::where('material_lot_id', $todayLot->id)->where('threshold_days', 0)->exists())->toBeTrue()
        ->and($result['alerts_created'])->toBeGreaterThanOrEqual(1);

    $this->artisan('material-lots:auto-expire')->assertSuccessful();

    // Boundary unified: expires-today stays active; the day-past lot flips.
    expect($todayLot->fresh()->status)->toBe(MaterialLotStatusEnum::Active)
        ->and($pastLot->fresh()->status)->toBe(MaterialLotStatusEnum::Expired);
});

// ── M18 ──────────────────────────────────────────────────────────────────────
it('uses the branch timezone in the expiry sweep, not the app default', function () {
    // Instant where Tokyo is 2026-06-26 but Honolulu is still 2026-06-25.
    Carbon::setTestNow(Carbon::parse('2026-06-26 05:00:00', 'Asia/Tokyo'));

    $s = makeAlertSetup(branchOverrides: ['timezone' => 'Pacific/Honolulu']);
    $s['material']->update(['expiry_alert_thresholds' => [0]]);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $s['org_id'], 'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id, 'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        // "today" in Honolulu = "yesterday" in Tokyo. daysUntil is 0 only when
        // the sweep honours the branch tz; under Tokyo it is -1 (no fire).
        'expiry_date' => Carbon::today('Pacific/Honolulu')->toDateString(),
    ]);

    app(ExpiryAlertService::class)->scan();

    expect(ExpiryAlert::where('material_lot_id', $lot->id)->where('threshold_days', 0)->exists())->toBeTrue();
});

it('uses the branch timezone in AutoExpireMaterialLots, not the app default', function () {
    // Instant where Tokyo is 2026-06-26 but Kiritimati is already 2026-06-27.
    Carbon::setTestNow(Carbon::parse('2026-06-26 22:00:00', 'Asia/Tokyo'));

    $s = makeAlertSetup(branchOverrides: ['timezone' => 'Pacific/Kiritimati']);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $s['org_id'], 'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id, 'warehouse_id' => $s['warehouse']->id,
        'status' => MaterialLotStatusEnum::Active->value,
        // expiry = Tokyo today (= Kiritimati yesterday). Past only in the
        // branch tz; under the app default (Tokyo) it is "today" → not expired.
        'expiry_date' => Carbon::today('Asia/Tokyo')->toDateString(),
    ]);

    $this->artisan('material-lots:auto-expire')->assertSuccessful();

    expect($lot->fresh()->status)->toBe(MaterialLotStatusEnum::Expired);
});

// ── NEW-STK-7 ────────────────────────────────────────────────────────────────
it('defaults alert_enabled to true on an auto-created StockLevel', function () {
    $s = makeAlertSetup();

    $service = app(StockTransactionService::class);
    $txn = $service->create([
        'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
        'type' => 'stock_in', 'sub_type' => 'purchase', 'created_by_id' => (string) Str::uuid(),
        'items' => [['material_id' => $s['material']->id, 'quantity' => 10, 'unit' => 'g']],
    ]);
    $service->submit($txn);

    $level = StockLevel::where('warehouse_id', $s['warehouse']->id)
        ->where('material_id', $s['material']->id)
        ->whereNull('material_lot_id')
        ->firstOrFail();

    expect((bool) $level->alert_enabled)->toBeTrue();
});

// ── M14 (TA.6) ───────────────────────────────────────────────────────────────
it('routes two lot-less writes through one canonical StockLevel row', function () {
    $s = makeAlertSetup();
    $service = app(StockTransactionService::class);

    foreach ([10, 5] as $qty) {
        $txn = $service->create([
            'organization_id' => $s['org_id'], 'warehouse_id' => $s['warehouse']->id,
            'type' => 'stock_in', 'sub_type' => 'purchase', 'created_by_id' => (string) Str::uuid(),
            'items' => [['material_id' => $s['material']->id, 'quantity' => $qty, 'unit' => 'g']],
        ]);
        $service->submit($txn);
    }

    $rows = StockLevel::where('warehouse_id', $s['warehouse']->id)
        ->where('material_id', $s['material']->id)
        ->whereNull('material_lot_id')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->quantity)->toBe(15.0);
});

// ── L12 ──────────────────────────────────────────────────────────────────────
it('eager-loads warehouse in the expiry sweep so warehouse rows are not queried per lot', function () {
    $s = makeAlertSetup();

    for ($i = 0; $i < 5; $i++) {
        $wh = Warehouse::factory()->create(['organization_id' => $s['org_id'], 'branch_id' => $s['branch']->id]);
        MaterialLot::factory()->create([
            'organization_id' => $s['org_id'], 'brand_id' => $s['brand']->id,
            'material_id' => $s['material']->id, 'warehouse_id' => $wh->id,
            'status' => MaterialLotStatusEnum::Active->value,
            'expiry_date' => Carbon::today('Asia/Tokyo')->addDays(7)->toDateString(),
        ]);
    }

    $warehouseQueries = 0;
    DB::listen(function ($query) use (&$warehouseQueries) {
        if (str_contains($query->sql, 'from `warehouses`')) {
            $warehouseQueries++;
        }
    });

    app(ExpiryAlertService::class)->scan();

    // Eager loaded: a small constant number of warehouse queries regardless of
    // the 5 lots across 5 warehouses (would be ≥5 under an N+1).
    expect($warehouseQueries)->toBeLessThanOrEqual(2);
});
