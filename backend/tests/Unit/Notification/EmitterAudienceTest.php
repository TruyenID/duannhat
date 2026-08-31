<?php

/**
 * Plan-023 M1 T1.3 — emitter recipient resolution (post-flag).
 *
 * Pre-plan-023 this file was `EmitterAudienceFlagTest.php` and exercised
 * the `NOTIFICATION_USE_AUDIENCE` config toggle in both directions. T1.3
 * removed the flag — every emitter now unconditionally uses the audience
 * engine. This file is the trimmed-down version that asserts only the
 * post-flip behaviour.
 *
 * Legacy cap-N behaviour is no longer covered anywhere: the frozen resolver
 * and its unit test went with #2413 once production showed the engine was
 * the correct side of every remaining divergence. The point of M1 was that
 * the cap path is gone from production; it is now gone from the tree too.
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'flag-'.Str::random(4),
    ]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId]);
});

function seedManagerAndStaff(string $orgId, Warehouse $warehouse): array
{
    $manager = User::factory()->create(['console_organization_id' => $orgId]);
    $staff = User::factory()->create(['console_organization_id' => $orgId]);
    $noise = User::factory()->create(['console_organization_id' => $orgId]);

    WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $manager->id, 'role' => 'manager']);
    WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $staff->id, 'role' => 'staff']);

    // Pivot rows kept so other fixtures sharing this org have org-admin
    // coverage; not used to drive the emitter recipient set any more.
    $orgAdmin = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    foreach ([$manager, $staff, $noise] as $u) {
        DB::table('role_user_pivots')->insert([
            'user_id' => $u->id,
            'role_id' => $orgAdmin->id,
            'organization_id' => $orgId,
            'branch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$manager, $staff, $noise];
}

describe('StockAlertNotificationObserver', function () {
    it('resolves to warehouse_manager scope unconditionally (M1 post-flag)', function () {
        [$manager] = seedManagerAndStaff($this->orgId, $this->warehouse);

        StockAlert::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->orgId,
            'alert_type' => 'low_stock',
        ]);

        $notification = Notification::query()
            ->where('organization_id', $this->orgId)
            ->where('type', 'stock.alert.low')
            ->firstOrFail();

        $recipientIds = $notification->recipients->pluck('recipient_id')->values();
        expect($recipientIds->all())->toBe([$manager->id]);
    });

});

describe('CustomerOrderNotificationObserver', function () {
    it('resolves to shop-manager scoped to branch (M1 post-flag)', function () {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);

        $shopRole = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 50]);

        $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
        $outsider = User::factory()->create(['console_organization_id' => $this->orgId]);

        DB::table('role_user_pivots')->insert([
            ['user_id' => $manager->id, 'role_id' => $shopRole->id, 'organization_id' => $this->orgId, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $outsider->id, 'role_id' => $shopRole->id, 'organization_id' => $this->orgId, 'branch_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $branch->id,
            'status' => 'open',
        ]);
        $order->status = 'closed';
        $order->save();

        $notification = Notification::query()
            ->where('organization_id', $this->orgId)
            ->where('type', 'order.status_changed')
            ->latest()
            ->firstOrFail();

        $ids = $notification->recipients->pluck('recipient_id')->values();
        expect($ids->all())->toBe([$manager->id]);
    });

});

describe('M1-2: emitter source asserts unconditional Audience path', function () {
    it('production emitter files contain no `use_audience` literal', function () {
        $files = [
            base_path('app/Observers/StockAlertNotificationObserver.php'),
            base_path('app/Observers/CustomerOrderNotificationObserver.php'),
            base_path('app/Services/Product/RecipeService.php'),
        ];

        foreach ($files as $file) {
            expect(file_get_contents($file))
                ->not->toContain("config('notifications.use_audience')")
                ->not->toContain('config("notifications.use_audience")');
        }
    });

    it('production observer files reference Audience::byRole', function () {
        $observers = [
            base_path('app/Observers/StockAlertNotificationObserver.php'),
            base_path('app/Observers/CustomerOrderNotificationObserver.php'),
        ];

        foreach ($observers as $file) {
            expect(file_get_contents($file))->toContain('Audience::byRole');
        }
    });

    it('config key notifications.use_audience is null', function () {
        expect(config('notifications.use_audience'))->toBeNull();
    });
});
