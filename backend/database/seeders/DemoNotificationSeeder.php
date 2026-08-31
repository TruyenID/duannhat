<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\StockAlert;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Seeder;

/**
 * DemoNotificationSeeder — creates a varied set of inbox rows for the demo
 * admin user so the FE bell + `/inbox` page have something to render locally.
 *
 * Idempotent: every dispatch carries a stable `idempotency_key`, so re-running
 * the seeder does not duplicate rows. Mixes actor kinds (user / device / null)
 * to exercise the morph map.
 */
class DemoNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->first();
        if ($org === null) {
            $this->command?->warn('No organizations found — skipping DemoNotificationSeeder.');

            return;
        }

        // Pick up to 3 users in the org; create demo ones if empty.
        $users = User::query()->limit(3)->get();
        if ($users->count() === 0) {
            $this->command?->warn('No users found — skipping DemoNotificationSeeder.');

            return;
        }

        $primary = $users->first();
        $service = app(NotificationService::class);

        // 1. System event — stock alert (no actor).
        $alert = StockAlert::query()->first();
        $service->dispatch([
            'type' => 'stock.alert.low',
            'template_key' => 'stock.alert.low',
            'params' => [
                'warehouse_name' => $alert?->warehouse?->name ?? 'Tokyo',
                'item_name' => 'Demo Rice 5kg',
                'current_quantity' => 3,
                'min_stock' => 10,
            ],
            'recipients' => $users,
            'actor' => null,
            'subject' => $alert,
            'organization_id' => $org->id,
            'priority' => NotificationPriorityEnum::Normal,
            'idempotency_key' => 'demo:stock.alert.low:v1',
        ]);

        // 2. User event — recipe approved (user actor + recipe subject).
        $recipe = Recipe::query()->first();
        if ($recipe !== null) {
            $service->dispatch([
                'type' => 'recipe.approved',
                'template_key' => 'recipe.approved',
                'params' => [
                    'recipe_name' => $recipe->name ?? 'Demo Recipe',
                    'approver' => $primary->name ?? 'Demo User',
                ],
                'recipients' => [$primary],
                'actor' => $primary,
                'subject' => $recipe,
                'organization_id' => $org->id,
                'idempotency_key' => 'demo:recipe.approved:'.$recipe->id,
            ]);
        }

        // 3. System critical — urgent priority.
        $service->dispatch([
            'type' => 'system.critical',
            'template_key' => 'system.critical',
            'params' => ['message' => 'Database backup completed (demo urgent notification).'],
            'recipients' => [$primary],
            'actor' => null,
            'subject' => null,
            'organization_id' => $org->id,
            'priority' => NotificationPriorityEnum::Urgent,
            'idempotency_key' => 'demo:system.critical:v1',
        ]);

        // 4. Device-actor event (optional) — exercises morph with a non-User actor.
        $device = Device::query()->first();
        if ($device !== null) {
            $service->dispatch([
                'type' => 'order.status_changed',
                'template_key' => 'order.status_changed',
                'params' => [
                    'order_code' => 'DEMO-0001',
                    'new_status' => 'ready',
                    'shop_name' => 'Demo Shop',
                ],
                'recipients' => [$primary],
                'actor' => $device,
                'subject' => null,
                'organization_id' => $org->id,
                'idempotency_key' => 'demo:order.status_changed:device:v1',
            ]);
        }

        // 5. Fan-out to multiple recipients (low priority).
        if ($users->count() >= 2) {
            $service->dispatch([
                'type' => 'recipe.rejected',
                'template_key' => 'recipe.rejected',
                'params' => [
                    'recipe_name' => 'Demo Ramen',
                    'reviewer' => $primary->name,
                    'reason' => 'Missing allergen declaration (demo).',
                ],
                'recipients' => $users,
                'actor' => $primary,
                'subject' => $recipe,
                'organization_id' => $org->id,
                'priority' => NotificationPriorityEnum::Low,
                'idempotency_key' => 'demo:recipe.rejected:v1',
            ]);
        }

        $this->command?->info(
            'DemoNotificationSeeder: '.Notification::query()->count().' notifications in DB.',
        );
    }
}
