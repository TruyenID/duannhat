<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;

/**
 * #531 — inventory document codes are sequenced PER organization, so the code
 * columns must be unique per-org (not global) and the generator must sort its
 * suffix numerically. StockTransaction is used here as the representative
 * generator: its per-org composite unique is already in place (omnify
 * 2000_02_20_000001), and its generator shares the same code shape / sort logic
 * as the other four inventory documents.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'console_brand_id' => $this->brand->id]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
});

it('#531: two orgs may mint the same daily code — the code is unique PER-ORG, not global', function () {
    $service = app(StockTransactionService::class);
    $date = now()->format('Ymd');

    // A second org with its own warehouse.
    $orgB = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgB, 'console_organization_id' => $orgB]);
    $brandB = Brand::factory()->create(['console_organization_id' => $orgB]);
    $branchB = Branch::factory()->create(['console_organization_id' => $orgB, 'console_brand_id' => $brandB->id]);
    $warehouseB = Warehouse::factory()->create(['organization_id' => $orgB, 'branch_id' => $branchB->id]);

    $payload = fn (string $org, string $wh) => [
        'organization_id' => $org,
        'warehouse_id' => $wh,
        'type' => 'stock_in',
        'sub_type' => 'purchase',
        'created_by_id' => (string) Str::uuid(),
        'items' => [],
    ];

    // Org A mints SI-<date>-001.
    $a = $service->create($payload($this->orgId, $this->warehouse->id));

    // Org B on the SAME day also mints SI-<date>-001 — before the per-org
    // composite unique this threw a deterministic cross-org duplicate-key 500.
    $b = $service->create($payload($orgB, $warehouseB->id));

    expect($a->transaction_code)->toBe("SI-{$date}-001")
        ->and($b->transaction_code)->toBe("SI-{$date}-001");
});

it('#531 sub-bug: generator skips past the 999th code instead of re-minting (numeric, not string, sort)', function () {
    $service = app(StockTransactionService::class);
    $date = now()->format('Ymd');

    // A day that already reached 1000. A plain string sort ranks "...-999" above
    // "...-1000" (char '9' > '1'), so the buggy generator would re-mint 1000 and
    // hit the per-org unique. Only the two boundary rows are needed to trip it.
    foreach (['999', '1000'] as $suffix) {
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'transaction_code' => "SI-{$date}-{$suffix}",
            'type' => 'stock_in',
            'sub_type' => 'purchase',
        ]);
    }

    $txn = $service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_in',
        'sub_type' => 'purchase',
        'created_by_id' => (string) Str::uuid(),
        'items' => [],
    ]);

    expect($txn->transaction_code)->toBe("SI-{$date}-1001");
});
