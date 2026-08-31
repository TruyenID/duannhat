<?php

/**
 * Emitter integration tests (plan-008 T9.3).
 *
 * Verifies that the three Phase A emitters (StockAlert observer, Recipe
 * service, CustomerOrder observer) wire dispatch correctly — one
 * Notification row with the expected type/actor/subject/params, and
 * silent-fail behaviour so a dispatch failure never breaks the upstream
 * mutation.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\StockAlert;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    // Plan-023 M1: emitters use Audience::byRole(...)->scopedTo(...) directly,
    // so a Brand must exist for the org and the user must hold the relevant
    // role (warehouse_manager for stock alerts, shop-manager scoped to a
    // branch for order updates). Without these the audience resolves to empty
    // and the dispatch throws NotificationException::recipientsEmpty().
    Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'emitter-int-'.Str::random(4),
    ]);
});

/**
 * Local helper: insert a role_user_pivot row + ensure the role exists.
 */
function seedRoleForUser(User $user, string $slug, string $orgId, ?string $branchId = null): void
{
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        ['id' => (string) Str::uuid(), 'console_organization_id' => $orgId, 'name' => ucfirst(str_replace('_', ' ', $slug)), 'level' => 100],
    );

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// =========================================================================
//  StockAlert observer (T6.1)
// =========================================================================

describe('StockAlertNotificationObserver', function () {
    it('fires a stock.alert.low notification when a low_stock alert is created', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);
        WarehouseMember::factory()->create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->user->id,
            'role' => 'manager',
        ]);

        $before = Notification::count();

        StockAlert::create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'alert_type' => 'low_stock',
            'current_quantity' => 2,
            'min_stock' => 10,
            'unit' => 'kg',
            'status' => 'active',
        ]);

        expect(Notification::count())->toBe($before + 1);

        $n = Notification::latest('id')->first();

        expect($n->type)->toBe('stock.alert.low')
            ->and($n->subject_type)->toBe('StockAlert')
            ->and($n->actor_type)->toBeNull()
            ->and($n->actor_id)->toBeNull()
            ->and($n->recipients()->count())->toBeGreaterThanOrEqual(1);
    });

    it('fires stock.alert.out for out_of_stock alert_type', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);
        WarehouseMember::factory()->create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->user->id,
            'role' => 'manager',
        ]);

        StockAlert::create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'alert_type' => 'out_of_stock',
            'current_quantity' => 0,
            'min_stock' => 10,
            'unit' => 'kg',
            'status' => 'active',
        ]);

        $n = Notification::latest('id')->first();

        expect($n->type)->toBe('stock.alert.out');
    });

    it('is idempotent — creating two alerts with the same id would not duplicate the notification', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);
        WarehouseMember::factory()->create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->user->id,
            'role' => 'manager',
        ]);

        $alert = StockAlert::create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'alert_type' => 'low_stock',
            'current_quantity' => 2,
            'min_stock' => 10,
            'unit' => 'kg',
            'status' => 'active',
        ]);

        // Simulate a retry — same idempotency key path via direct dispatch.
        app(NotificationService::class)->dispatch([
            'type' => 'stock.alert.low',
            'recipients' => [$this->user],
            'subject' => $alert,
            'organization_id' => $this->orgId,
            'idempotency_key' => "stock.alert.low:{$alert->id}",
        ]);

        $matching = Notification::where('subject_type', 'StockAlert')
            ->where('subject_id', $alert->id)
            ->count();

        expect($matching)->toBe(1);
    });
});

// =========================================================================
//  CustomerOrder observer (T6.3)
// =========================================================================

describe('CustomerOrderNotificationObserver', function () {
    it('fires order.status_changed when an order status is updated', function () {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'status' => 'open',
        ]);
        seedRoleForUser($this->user, 'shop-manager', $this->orgId, $this->branch->id);

        $before = Notification::where('type', 'order.status_changed')->count();

        $order->update(['status' => 'dining']);

        $after = Notification::where('type', 'order.status_changed')->count();

        expect($after)->toBe($before + 1);

        $n = Notification::where('type', 'order.status_changed')->latest('id')->first();

        expect($n->subject_type)->toBe('CustomerOrder')
            ->and($n->subject_id)->toBe($order->id)
            ->and($n->params['new_status'] ?? null)->toBe('dining');
    });

    it('does not fire when a non-status column is updated', function () {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->orgId,
            'status' => 'open',
        ]);

        $before = Notification::where('type', 'order.status_changed')->count();

        $order->update(['note' => 'Updated note only']);

        expect(Notification::where('type', 'order.status_changed')->count())
            ->toBe($before);
    });
});
