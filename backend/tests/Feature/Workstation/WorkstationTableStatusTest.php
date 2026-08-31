<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->makeTable = function (string $branchId, string $status = 'free') {
        $zone = Zone::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $branchId,
        ]);

        return Table::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $branchId,
            'zone_id' => $zone->id,
            'status' => $status,
            'is_active' => true,
        ]);
    };
});

it('updates a table status in the device branch and records the change', function () {
    $table = ($this->makeTable)($this->branch->id, 'free');

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson("/api/v1/workstation/tables/{$table->id}/status", ['status' => 'cleaning'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cleaning');

    $fresh = $table->fresh()->status;
    expect($fresh instanceof BackedEnum ? $fresh->value : $fresh)->toBe('cleaning');

    expect(
        DB::table('table_status_changes')
            ->where('table_id', $table->id)
            ->where('to_status', 'cleaning')
            ->exists()
    )->toBeTrue();
});

it('rejects an invalid status', function () {
    $table = ($this->makeTable)($this->branch->id);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson("/api/v1/workstation/tables/{$table->id}/status", ['status' => 'not_a_status'])
        ->assertStatus(422);
});

it('404s a table outside the device branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $table = ($this->makeTable)($otherBranch->id);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson("/api/v1/workstation/tables/{$table->id}/status", ['status' => 'cleaning'])
        ->assertStatus(404);
});
