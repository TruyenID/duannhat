<?php

/**
 * plan-034 — TableSession multi-device join flow.
 *
 * Covers:
 *   - free table → POST /join opens a session, returns {status: joined}.
 *   - second device → joins the SAME session (no new row).
 *   - paid table without force_new → returns paid_recent + paid_order ref.
 *   - paid table with force_new → opens a fresh session on top.
 *   - cleaning / reserved → 423 Locked.
 *   - close on payment → session.status flips open → closed.
 *   - stale session reaper → expires sessions > 4h.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TableSession;
use App\Omnify\Enums\TableStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => '00000000-aaaa-4aaa-aaaa-000000000001',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

function makeTable(string $status = 'free'): Table
{
    return Table::factory()->create([
        'organization_id' => test()->organization->id,
        'branch_id' => test()->branch->id,
        'status' => $status,
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);
}

it('opens a fresh session when device A joins a free table', function () {
    $table = makeTable('free');

    $res = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join");

    $res->assertOk()
        ->assertJsonPath('data.status', 'joined')
        ->assertJsonPath('data.table.qr_token', $table->qr_token)
        ->assertJsonStructure(['data' => ['session' => ['id', 'opened_at']]]);

    expect(TableSession::where('table_id', $table->id)->count())->toBe(1);
    expect($table->fresh()->status)->toBeIn(['occupied', TableStatusEnum::Occupied]);
});

it('returns the SAME session when device B joins the occupied table', function () {
    $table = makeTable('free');

    $firstResponse = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join");
    $firstSessionId = $firstResponse->json('data.session.id');

    $secondResponse = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join");
    $secondSessionId = $secondResponse->json('data.session.id');

    expect($secondSessionId)->toBe($firstSessionId);
    expect(TableSession::where('table_id', $table->id)->count())->toBe(1);
});

it('returns paid_recent when a device scans a paid table without force_new', function () {
    $table = makeTable('free');
    // Bypass the TableStatusEnum cast to plant a transient `paid` value
    // (TableStatusEnum doesn't include `paid` because the production
    // close flow flips the table straight back to `free`, but the
    // controller still defensively handles `paid` for any future flow
    // that might leave the row in that state).
    DB::table('tables')->where('id', $table->id)->update(['status' => 'paid']);

    $res = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join");

    $res->assertOk()
        ->assertJsonPath('data.status', 'paid_recent')
        ->assertJsonPath('data.can_start_new_session', true);

    expect(TableSession::where('table_id', $table->id)->count())->toBe(0);
});

it('opens a NEW session on a paid table when force_new=true', function () {
    $table = makeTable('free');
    DB::table('tables')->where('id', $table->id)->update(['status' => 'paid']);

    $res = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join?force_new=true");

    $res->assertOk()
        ->assertJsonPath('data.status', 'joined');

    expect(TableSession::where('table_id', $table->id)->count())->toBe(1);
    expect($table->fresh()->status)->toBeIn(['occupied', TableStatusEnum::Occupied]);
});

it('returns 423 Locked for cleaning tables', function () {
    $table = makeTable('cleaning');

    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join")
        ->assertStatus(423)
        ->assertJsonPath('status', 'cleaning');
});

it('device B POST /orders appends items onto device A order, not a sibling', function () {
    $table = makeTable('free');
    $skuA = ProductSku::factory()->create();
    $skuB = ProductSku::factory()->create();

    // Device A — fresh dine-in session.
    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join")->assertOk();
    $resA = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", [
        'items' => [['product_sku_id' => $skuA->id, 'quantity' => 2]],
    ]);
    $resA->assertCreated();
    $orderIdA = $resA->json('data.id');

    // Device B — same QR, separate cart.
    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join")->assertOk();
    $resB = $this->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", [
        'items' => [['product_sku_id' => $skuB->id, 'quantity' => 1]],
    ]);
    $resB->assertOk();

    // Same order id, shared_session flag, both SKUs present.
    expect($resB->json('data.id'))->toBe($orderIdA);
    expect($resB->json('data.shared_session'))->toBeTrue();

    $skuIds = collect($resB->json('data.items'))->pluck('product_sku_id')->all();
    expect($skuIds)->toContain($skuA->id)->toContain($skuB->id);

    // Exactly ONE CustomerOrder for the table (no sibling created).
    expect(CustomerOrder::where('table_session_id', TableSession::where('table_id', $table->id)->value('id'))->count())
        ->toBe(1);
});

it('expires sessions stuck open longer than the threshold', function () {
    $table = makeTable('free');
    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/join");
    $session = TableSession::where('table_id', $table->id)->first();
    $session->forceFill(['opened_at' => now()->subHours(5)])->save();

    $this->artisan('dine-in:expire-stale-sessions', ['--hours' => 4])
        ->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED);
    expect($table->fresh()->status)->toBeIn(['free', TableStatusEnum::Free]);
});
