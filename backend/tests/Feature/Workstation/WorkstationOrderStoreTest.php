<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use Illuminate\Support\Str;

/**
 * Sync UP entry point for orders created on the workstation. The
 * workstation's local CreateOrderInput now carries table_ids[] +
 * customer_id (matching POST /api/v1/pos/orders); this controller has
 * to accept the same shape or the multi-table / customer attribution
 * gets dropped on the LAN→Cloud sync.
 */
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
});

it('accepts table_ids[] for multi-table merged orders', function () {
    $tA = Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);
    $tB = Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'dine_in',
            'table_ids' => [$tA->id, $tB->id],
            'guest_count' => 4,
        ])
        ->assertCreated();

    $orderId = $resp->json('data.id');
    $order = CustomerOrder::with('tables')->find($orderId);

    // table_id column is not mass-assigned by CustomerOrder (not in
    // $fillable); the order↔table linkage lives in Table.current_order_id
    // and the order's `tables` reverse relation, which is the canonical
    // shape Cloud + pos-web both consume.
    expect($order->tables->pluck('id')->all())->toEqualCanonicalizing([$tA->id, $tB->id]);

    // Both tables marked occupied + pointed at this order.
    expect(Table::find($tA->id)->current_order_id)->toBe($orderId);
    expect(Table::find($tB->id)->current_order_id)->toBe($orderId);
});

// #484 — sync-UP must never fail on table state; table linkage is best-effort.
it('drops an occupied table on dine_in sync-up instead of aborting (order still syncs)', function () {
    // A table already occupied by a DIFFERENT order on Cloud.
    $occupied = Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);
    $other = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'order_type' => 'dine_in',
    ]);
    $occupied->update(['current_order_id' => $other->id]);

    // Previously this returned 422 "Table already occupied" and the whole
    // order was stranded (never got a cloud_id). Now it must sync.
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'dine_in',
            'table_ids' => [$occupied->id],
        ])
        ->assertCreated();

    $order = CustomerOrder::with('tables')->find($resp->json('data.id'));
    // The occupied table was dropped, not linked to the new order …
    expect($order->tables)->toHaveCount(0);
    // … and NOT stolen — it still belongs to the original order.
    expect(Table::find($occupied->id)->current_order_id)->toBe($other->id);
});

it('never holds a table on takeaway sync-up even when a table_id is sent', function () {
    $t = Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'table_id' => $t->id,
        ])
        ->assertCreated();

    $order = CustomerOrder::with('tables')->find($resp->json('data.id'));
    expect($order->tables)->toHaveCount(0);
    expect(Table::find($t->id)->current_order_id)->toBeNull();
});

it('still accepts the legacy singular table_id', function () {
    $t = Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'dine_in',
            'table_id' => $t->id,
        ])
        ->assertCreated();

    $orderId = $resp->json('data.id');
    expect(Table::find($t->id)->current_order_id)->toBe($orderId);
});

it('attaches customer_id when supplied', function () {
    $customer = Customer::factory()->create(['organization_id' => $this->orgId]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
            'customer_takeaway_phone' => '0900111222',
        ])
        ->assertCreated();

    $orderId = $resp->json('data.id');
    expect(CustomerOrder::find($orderId)->customer_id)->toBe($customer->id);
});

it('find-or-creates the customer by phone when the workstation sends a LAN-minted customer_id Cloud has never seen', function () {
    // The regression: in LAN mode the workstation stamps a locally-minted
    // customer UUID Cloud has never stored. The order must STILL sync — Cloud
    // resolves the canonical customer via the accompanying phone instead of
    // 422-ing on the unknown id.
    $lanMintedId = (string) Str::uuid();

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_id' => $lanMintedId,
            'customer_phone' => '0912345678',
        ])
        ->assertCreated();

    $order = CustomerOrder::find($resp->json('data.id'));

    // A canonical Cloud customer was created for the phone, scoped to the
    // device org+branch, and linked — NOT the unknown LAN id.
    $customer = Customer::where('organization_id', $this->orgId)
        ->where('phone', '0912345678')
        ->first();
    expect($customer)->not->toBeNull();
    expect($order->customer_id)->toBe($customer->id);
    expect($order->customer_id)->not->toBe($lanMintedId);
});

it('reuses an existing customer by phone instead of creating a duplicate', function () {
    $existing = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'phone' => '0900555777',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_id' => (string) Str::uuid(), // unknown LAN id
            'customer_phone' => '0900555777',
        ])
        ->assertCreated();

    expect(CustomerOrder::find($resp->json('data.id'))->customer_id)->toBe($existing->id);
    expect(Customer::where('phone', '0900555777')->count())->toBe(1);
});

it('prefers an already-existing customer_id over the phone', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'phone' => '0900555999',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
            // A different phone must NOT spawn a second customer when the id
            // already resolves.
            'customer_phone' => '0900000001',
        ])
        ->assertCreated();

    expect(CustomerOrder::find($resp->json('data.id'))->customer_id)->toBe($customer->id);
    expect(Customer::where('phone', '0900000001')->exists())->toBeFalse();
});

it('leaves the order unlinked when neither a resolvable id nor a phone is given', function () {
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_id' => (string) Str::uuid(), // unknown, no phone fallback
        ])
        ->assertCreated();

    expect(CustomerOrder::find($resp->json('data.id'))->customer_id)->toBeNull();
});

it('rejects table_ids referencing tables that do not exist', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'dine_in',
            'table_ids' => [(string) Str::uuid()],
        ])
        ->assertStatus(422);
});

it('initializes takeaway as pending matching the Shop controller', function () {
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'customer_takeaway_phone' => '0900111222',
        ])
        ->assertCreated();

    expect($resp->json('data.status'))->toBe('pending');
});

it('honors an explicit open status on a staff-created (POS) takeaway sync-up', function () {
    // The workstation POS handler sends status=open so a counter takeaway
    // opens directly instead of the self-service `pending` default.
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'status' => 'open',
            'customer_takeaway_phone' => '0900111222',
        ])
        ->assertCreated();

    expect($resp->json('data.status'))->toBe('open');
});

it('rejects a status outside the creation-safe set', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'takeaway',
            'status' => 'closed',
        ])
        ->assertStatus(422);
});

it('persists the workstation-provided order_code verbatim', function () {
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_code' => 'WS-019e-20260622-007',
            'order_type' => 'dine_in',
            'guest_count' => 1,
        ])
        ->assertCreated();

    // The order is findable in Cloud by the exact code the cashier sees on
    // the slip — no silent ORD-#### substitution.
    expect($resp->json('data.order_code'))->toBe('WS-019e-20260622-007');
    expect(CustomerOrder::find($resp->json('data.id'))->order_code)
        ->toBe('WS-019e-20260622-007');
});

it('generates an ORD-#### order_code when the workstation sends none', function () {
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'order_type' => 'dine_in',
        ])
        ->assertCreated();

    expect(CustomerOrder::find($resp->json('data.id'))->order_code)
        ->toStartWith('ORD-');
});

it('returns the existing order when the same order_code is re-synced', function () {
    $payload = ['order_code' => 'WS-019e-20260622-099', 'order_type' => 'dine_in'];

    $first = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    // Re-sync the SAME order_code under a DIFFERENT idempotency key (e.g. the
    // workstation re-enqueued after a restart). The order_code unique
    // constraint must resolve to the existing row, not a 500 or a duplicate.
    $second = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(CustomerOrder::where('order_code', 'WS-019e-20260622-099')->count())->toBe(1);
});

// ── plan-041 — Cloud-authoritative ORD code + durable client_order_id idempotency ──

it('mints a Cloud ORD code (not the provisional) when client_order_id is sent', function () {
    $clientId = (string) Str::uuid();

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [
            'client_order_id' => $clientId,
            'order_type' => 'dine_in',
        ])
        ->assertCreated();

    // Cloud is the single authority: the response carries a freshly-minted
    // ORD-#### code, and the workstation's local UUID is persisted as the
    // durable idempotency key.
    expect($resp->json('data.order_code'))->toStartWith('ORD-'.now()->year.'-');
    expect(CustomerOrder::find($resp->json('data.id'))->client_order_id)->toBe($clientId);
});

it('replays the same Cloud order (no new number) when client_order_id repeats', function () {
    $clientId = (string) Str::uuid();
    $payload = ['client_order_id' => $clientId, 'order_type' => 'dine_in'];

    $first = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    // Re-sync under a DIFFERENT idempotency key (workstation restart re-enqueue).
    // client_order_id must resolve to the same row — and crucially NOT consume
    // a second sequence number.
    $code = $first->json('data.order_code');
    $second = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect($second->json('data.order_code'))->toBe($code);
    expect(CustomerOrder::where('client_order_id', $clientId)->count())->toBe(1);
});

it('replays a since-cancelled (soft-deleted) order idempotently instead of 500-ing', function () {
    // plan-041 idempotency crux: the DB unique(client_order_id) index counts
    // soft-deleted rows. A workstation retry that lands AFTER the order was
    // cancelled (soft-deleted) must resolve to the existing trashed row — NOT
    // miss the lookup, collide on the unique index, and blow up with a 500.
    $clientId = (string) Str::uuid();
    $payload = ['client_order_id' => $clientId, 'order_type' => 'dine_in'];

    $first = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    $orderId = $first->json('data.id');
    $code = $first->json('data.order_code');

    // Cancel the order (soft delete) — deleted_at set, unique index still holds.
    CustomerOrder::findOrFail($orderId)->delete();
    expect(CustomerOrder::withTrashed()->find($orderId)->trashed())->toBeTrue();

    // Retry the very same local order under a fresh idempotency key.
    $second = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])
        ->postJson('/api/v1/workstation/orders', $payload)
        ->assertCreated();

    // Resolves to the SAME (trashed) order, same code, no second row, no new number.
    expect($second->json('data.id'))->toBe($orderId);
    expect($second->json('data.order_code'))->toBe($code);
    expect(CustomerOrder::withTrashed()->where('client_order_id', $clientId)->count())->toBe(1);
});

it('dedupes via the Idempotency-Key header for legacy builds without client_order_id', function () {
    $key = (string) Str::uuid();
    $headers = ['Authorization' => "Bearer {$this->wsToken}", 'Idempotency-Key' => $key];

    // No client_order_id, no order_code (old build): Cloud mints an ORD code,
    // and the secondary Idempotency-Key cache must collapse a retry under the
    // same key onto the same order.
    $first = $this->withHeaders($headers)
        ->postJson('/api/v1/workstation/orders', ['order_type' => 'dine_in'])
        ->assertCreated();

    $second = $this->withHeaders($headers)
        ->postJson('/api/v1/workstation/orders', ['order_type' => 'dine_in'])
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect($second->json('data.order_code'))->toBe($first->json('data.order_code'));
});

it('hands out gapless sequential ORD codes', function () {
    $year = now()->year;

    $codes = collect(range(1, 3))->map(function () {
        return $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
            ->postJson('/api/v1/workstation/orders', [
                'client_order_id' => (string) Str::uuid(),
                'order_type' => 'dine_in',
            ])
            ->assertCreated()
            ->json('data.order_code');
    });

    // Whatever the starting offset, the three codes are strictly consecutive.
    $nums = $codes->map(fn ($c) => (int) substr($c, strlen("ORD-{$year}-")))->values();
    expect($nums[1])->toBe($nums[0] + 1);
    expect($nums[2])->toBe($nums[1] + 1);
});
