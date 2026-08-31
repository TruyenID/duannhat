<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\TableStatusChange;
use App\Models\Zone;
use App\Services\Order\Contracts\TableStatusJournal;
use App\Services\Shop\TableStatusService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(64);

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function tmsTable(array $attrs = []): Table
{
    $branch = test()->branch;
    $zone = test()->zone;
    $orgId = test()->orgId;

    return Table::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'organization_id' => $orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
    ], $attrs));
}

function changeStatus(string $tableId, string $status, string $token): TestResponse
{
    return test()
        ->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/v1/tms/tables/{$tableId}/status", ['status' => $status]);
}

// =============================================================================
// Validation
// =============================================================================

it('returns 422 when status field is missing', function () {
    $table = tmsTable(['status' => 'free']);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/tms/tables/{$table->id}/status", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when status value is not in the enum', function () {
    $table = tmsTable(['status' => 'free']);

    changeStatus($table->id, 'flying', $this->deviceToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('returns 404 for a table in another branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $otherZone = Zone::factory()->create(['branch_id' => $otherBranch->id, 'organization_id' => $this->orgId]);
    $otherTable = Table::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $otherZone->id,
        'is_active' => true,
        'status' => 'free',
    ]);

    changeStatus($otherTable->id, 'occupied', $this->deviceToken)->assertNotFound();
});

it('returns 404 for an inactive table', function () {
    $table = tmsTable(['status' => 'free', 'is_active' => false]);

    changeStatus($table->id, 'occupied', $this->deviceToken)->assertNotFound();
});

it('returns 404 for a non-existent table id', function () {
    changeStatus((string) Str::uuid(), 'occupied', $this->deviceToken)->assertNotFound();
});

// =============================================================================
// Valid transitions
// =============================================================================

it('transitions free → occupied', function () {
    $table = tmsTable(['status' => 'free']);
    changeStatus($table->id, 'occupied', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('occupied');
});

it('transitions free → reserved', function () {
    $table = tmsTable(['status' => 'free']);
    changeStatus($table->id, 'reserved', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('reserved');
});

it('transitions free → out_of_service', function () {
    $table = tmsTable(['status' => 'free']);
    changeStatus($table->id, 'out_of_service', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('out_of_service');
});

it('transitions occupied → cleaning', function () {
    $table = tmsTable(['status' => 'occupied']);
    changeStatus($table->id, 'cleaning', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('cleaning');
});

it('transitions occupied → free', function () {
    $table = tmsTable(['status' => 'occupied']);
    changeStatus($table->id, 'free', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('free');
});

it('transitions reserved → occupied', function () {
    $table = tmsTable(['status' => 'reserved']);
    changeStatus($table->id, 'occupied', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('occupied');
});

it('transitions reserved → free', function () {
    $table = tmsTable(['status' => 'reserved']);
    changeStatus($table->id, 'free', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('free');
});

it('transitions cleaning → free', function () {
    $table = tmsTable(['status' => 'cleaning']);
    changeStatus($table->id, 'free', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('free');
});

it('transitions out_of_service → free', function () {
    $table = tmsTable(['status' => 'out_of_service']);
    changeStatus($table->id, 'free', $this->deviceToken)->assertOk();
    expect($table->fresh()->status->value)->toBe('free');
});

// =============================================================================
// Invalid transitions
// =============================================================================

it('rejects free → cleaning and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'free']);
    changeStatus($table->id, 'cleaning', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('free');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects free → free (same status) and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'free']);
    changeStatus($table->id, 'free', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('free');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects occupied → reserved and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'occupied']);
    changeStatus($table->id, 'reserved', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('occupied');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects occupied → out_of_service and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'occupied']);
    changeStatus($table->id, 'out_of_service', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('occupied');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects reserved → cleaning and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'reserved']);
    changeStatus($table->id, 'cleaning', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('reserved');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects cleaning → occupied and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'cleaning']);
    changeStatus($table->id, 'occupied', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('cleaning');
    $this->assertDatabaseCount('table_status_changes', 0);
});

it('rejects out_of_service → occupied and leaves DB unchanged', function () {
    $table = tmsTable(['status' => 'out_of_service']);
    changeStatus($table->id, 'occupied', $this->deviceToken)->assertUnprocessable();
    expect($table->fresh()->status->value)->toBe('out_of_service');
    $this->assertDatabaseCount('table_status_changes', 0);
});

// =============================================================================
// Side effects
// =============================================================================

it('creates a table_status_changes record on valid transition', function () {
    $table = tmsTable(['status' => 'free']);

    changeStatus($table->id, 'occupied', $this->deviceToken)->assertOk();

    $this->assertDatabaseHas('table_status_changes', [
        'table_id' => $table->id,
        'from_status' => 'free',
        'to_status' => 'occupied',
        'changed_by_id' => $this->device->id,
    ]);
});

it('records changed_at ≈ now on valid transition', function () {
    $table = tmsTable(['status' => 'free']);
    $before = now()->subSecond();

    changeStatus($table->id, 'occupied', $this->deviceToken)->assertOk();

    $change = $table->statusChanges()->latest('changed_at')->first();
    expect($change)->not->toBeNull()
        ->and($change->changed_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($change->changed_at->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();
});

it('error message on invalid transition includes from/to labels', function () {
    $table = tmsTable(['status' => 'free']);

    $response = changeStatus($table->id, 'cleaning', $this->deviceToken)->assertUnprocessable();

    $errorMessage = $response->json('errors.status.0') ?? '';
    expect($errorMessage)->toContain('Free')->toContain('Cleaning');
});

it('returns data with fresh table and zone after status change', function () {
    $table = tmsTable(['status' => 'free']);

    $response = changeStatus($table->id, 'occupied', $this->deviceToken);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id', 'status']]);
    expect($response->json('data.status'))->toBe('occupied');
});

it('returns 401 without auth token', function () {
    $table = tmsTable(['status' => 'free']);

    $this->postJson("/api/v1/tms/tables/{$table->id}/status", ['status' => 'occupied'])
        ->assertUnauthorized();
});

// =============================================================================
// #901 — freeing a table with an open order
// =============================================================================

function tmsTableWithOpenOrder(string $status): Table
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => 'open',
    ]);

    return tmsTable(['status' => $status, 'current_order_id' => $order->id]);
}

it('rejects occupied → free while the table still has an open order', function () {
    $table = tmsTableWithOpenOrder('occupied');

    changeStatus($table->id, 'free', $this->deviceToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($table->fresh()->status->value)->toBe('occupied');
});

it('rejects cleaning → free while the table still has an open order', function () {
    // Occupied → Cleaning → Free must not bypass the open-order guard.
    $table = tmsTableWithOpenOrder('cleaning');

    changeStatus($table->id, 'free', $this->deviceToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($table->fresh()->status->value)->toBe('cleaning');
});

it('allows occupied → cleaning while the order is still open', function () {
    $table = tmsTableWithOpenOrder('occupied');

    changeStatus($table->id, 'cleaning', $this->deviceToken)->assertOk();

    expect($table->fresh()->status->value)->toBe('cleaning');
});

it('allows occupied → free once the order binding is cleared', function () {
    $table = tmsTableWithOpenOrder('occupied');
    $table->update(['current_order_id' => null]);

    changeStatus($table->id, 'free', $this->deviceToken)->assertOk();

    expect($table->fresh()->status->value)->toBe('free');
});

// =============================================================================
// Atomicity (#1668)
// =============================================================================

it('writes the history row for every transition', function () {
    $table = tmsTable(['status' => 'free']);

    changeStatus($table->id, 'occupied', $this->deviceToken)->assertOk();

    $entry = TableStatusChange::query()->where('table_id', $table->id)->sole();

    $value = fn ($status) => $status instanceof BackedEnum ? $status->value : $status;

    expect($value($entry->from_status))->toBe('free')
        ->and($value($entry->to_status))->toBe('occupied')
        ->and($entry->changed_by_id)->toBe($this->device->id);
});

/**
 * #1668 — the status change and its history row ran as two loose writes, so a
 * failure between them left the table moved with no trace of who moved it or
 * from where. `from_status` is only readable BEFORE the write, which is what
 * makes the loss permanent: nothing afterwards can reconstruct it.
 *
 * Driven at the journal seam rather than over HTTP, because the fault has to
 * land BETWEEN the two writes and that is the only point a test can choose.
 */
it('leaves the table on its old status when the history write fails', function () {
    $table = tmsTable(['status' => 'free']);

    app()->bind(TableStatusJournal::class, fn () => new class implements TableStatusJournal
    {
        public function record(
            string $tableId,
            ?string $fromStatus,
            string $toStatus,
            string $changedById,
            ?string $note = null,
        ): void {
            throw new RuntimeException('journal unavailable');
        }
    });

    expect(fn () => app(TableStatusService::class)->changeStatus(
        $table,
        'occupied',
        (string) $this->device->id,
    ))->toThrow(RuntimeException::class);

    // Rolled back together: a table nobody can account for is worse than a
    // table that never moved.
    expect($table->fresh()->status->value)->toBe('free')
        ->and(TableStatusChange::query()->where('table_id', $table->id)->count())->toBe(0);
});
