<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransactionService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Support\Str;

/**
 * plan-040 L1 — sequence/code generators must be atomic so a tight loop of
 * creates within one org/day never mints a duplicate code. True concurrency is
 * not reproducible under the sqlite test driver, so this asserts the
 * deterministic invariant: N sequential creates yield N distinct, zero-padded,
 * monotonically-increasing codes in the existing `PREFIX-YYYYMMDD-NNN` format.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'console_brand_id' => $this->brand->id]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
});

it('L1: stock transaction codes are unique and sequential across a tight create loop', function () {
    $service = app(StockTransactionService::class);
    $date = now()->format('Ymd');

    $codes = [];
    for ($i = 0; $i < 12; $i++) {
        $txn = $service->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
            'sub_type' => 'purchase',
            'created_by_id' => (string) Str::uuid(),
            'items' => [],
        ]);
        $codes[] = $txn->transaction_code;
    }

    // No duplicates, all present, format + zero-padding preserved.
    expect($codes)->toHaveCount(12)
        ->and(array_unique($codes))->toHaveCount(12)
        ->and($codes[0])->toBe("SI-{$date}-001")
        ->and($codes[11])->toBe("SI-{$date}-012");
});

it('L1: stock transfer codes are unique and sequential across a tight create loop', function () {
    $service = app(StockTransferService::class);
    $dest = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $date = now()->format('Ymd');

    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $transfer = $service->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->warehouse->id,
            'destination_warehouse_id' => $dest->id,
            'created_by_id' => (string) Str::uuid(),
            'items' => [],
        ]);
        $codes[] = $transfer->transfer_code;
    }

    expect(array_unique($codes))->toHaveCount(5)
        ->and($codes[0])->toBe("TR-{$date}-001")
        ->and($codes[4])->toBe("TR-{$date}-005");
});
