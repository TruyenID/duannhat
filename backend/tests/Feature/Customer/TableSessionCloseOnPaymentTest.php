<?php

/**
 * plan-034 audit (test-gap MEDIUM) — "session-close-on-payment" had no test.
 *
 * OrderClosingService::close() must, in addition to closing the order, flip
 * the shared dine-in TableSession open → closed (stamping closed_at) so any
 * later scan of the same QR opens a FRESH session instead of re-joining the
 * paid one. It must:
 *   - flip the linked OPEN session to closed on payment,
 *   - be a safe no-op for orders with no session (takeaway / legacy),
 *   - only touch sessions that are still OPEN (the `where status=open` guard —
 *     a session already expired by the reaper is left untouched),
 *   - stay tenant-isolated: closing table A's order must NOT close a sibling
 *     table's still-open session.
 */

use App\Events\WorkstationSyncPoke;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TableSession;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
use Illuminate\Support\Facades\Event;
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
        'console_brand_id' => $this->brand->id,
    ]);

    $this->closingService = app(OrderClosingService::class);
});

/** A dine-in table with a fresh OPEN session. */
function closeTestSession(): TableSession
{
    $table = Table::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => 'occupied',
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);

    return TableSession::create([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'table_id' => $table->id,
        'status' => TableSession::STATUS_OPEN,
        'opened_at' => now(),
    ]);
}

/**
 * A fully-paid, closeable dine-in order pinned to $sessionId. total=paid=0 so
 * isPaidEnough() passes; a single made_to_order/no-recipe SKU means close()
 * skips all stock-out setup.
 */
function closeTestOrder(?string $sessionId): CustomerOrder
{
    $sku = ProductSku::factory()->create(['inventory_mode' => 'made_to_order', 'recipe_id' => null]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'total_amount' => 0,
        'paid_amount' => 0,
        'table_session_id' => $sessionId,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    return $order;
}

it('flips the linked open session to closed when the order is paid/closed', function () {
    $session = closeTestSession();
    $order = closeTestOrder($session->id);

    expect($session->status)->toBe(TableSession::STATUS_OPEN);

    $this->closingService->close($order->fresh());

    $session->refresh();
    expect($session->status)->toBe(TableSession::STATUS_CLOSED);
    expect($session->closed_at)->not->toBeNull();

    // Order itself closed too.
    expect(CustomerOrder::find($order->id)->status)
        ->toBeIn([CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Closed->value]);
});

it('is a safe no-op for a session-less (takeaway/legacy) order', function () {
    $order = closeTestOrder(null);

    // Must not throw despite there being no session to close.
    $this->closingService->close($order->fresh());

    expect(CustomerOrder::find($order->id)->status)
        ->toBeIn([CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Closed->value]);
    // No sessions were touched (there are none for this order).
    expect(TableSession::count())->toBe(0);
});

it('leaves an already-expired session untouched (only OPEN sessions are closed)', function () {
    $session = closeTestSession();
    // The stale-session reaper already expired this one before payment landed.
    $session->forceFill([
        'status' => TableSession::STATUS_EXPIRED,
        'closed_at' => null,
    ])->save();

    $order = closeTestOrder($session->id);

    $this->closingService->close($order->fresh());

    $session->refresh();
    // The `where status=open` guard means close() must NOT resurrect an
    // expired session into `closed`.
    expect($session->status)->toBe(TableSession::STATUS_EXPIRED);
    expect($session->closed_at)->toBeNull();
});

it('does not close a sibling table session when paying a different order (tenant isolation)', function () {
    $sessionA = closeTestSession();
    $sessionB = closeTestSession();

    $orderA = closeTestOrder($sessionA->id);

    $this->closingService->close($orderA->fresh());

    // A's session closed …
    expect($sessionA->fresh()->status)->toBe(TableSession::STATUS_CLOSED);
    // … B's is untouched.
    expect($sessionB->fresh()->status)->toBe(TableSession::STATUS_OPEN);
    expect($sessionB->fresh()->closed_at)->toBeNull();
});

it('dispatches a workstation sync poke for the order branch on close', function () {
    Event::fake([WorkstationSyncPoke::class]);

    $session = closeTestSession();
    $order = closeTestOrder($session->id);

    $this->closingService->close($order->fresh());

    Event::assertDispatched(
        WorkstationSyncPoke::class,
        fn (WorkstationSyncPoke $event): bool => $event->branchId === (string) $this->branch->id,
    );
});
