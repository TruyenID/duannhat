<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationEmailSuppression;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * NotificationDemoSeeder — populates every notification-related table with
 * varied demo data wired to the NAMED users from NotificationDemoUsersSeeder.
 *
 * Flow map (actor → recipients):
 *   • system  → Bình + Emi                → stock.alert.low normal
 *   • system  → Bình + Emi                → stock.alert.out high
 *   • Alice   → Bình                      → recipe.approved normal
 *   • system  → Alice + Bình              → ops.daily_summary normal
 *   • system  → everyone (6)              → promo.welcome normal
 *   • system  → Alice + Bình              → system.critical URGENT
 *   • Alice   → everyone (6)              → brand.announcement Tết
 *   • Alice   → Chi + Daiki (shop mgrs)   → order.status_changed
 *   • system  → everyone (6)              → maintenance.scheduled (+2h PENDING)
 *
 * Inbox state after seeding:
 *   Alice: round-robin read / seen / unread
 *   Bình:  1 dismissed + 2 read, rest unread
 *   Others: fully unread
 *
 * Idempotent via idempotency_key on every dispatch.
 */
class NotificationDemoSeeder extends Seeder
{
    public function run(): void
    {
        Bus::fake();

        $brand = Brand::query()->first();
        if ($brand === null) {
            $this->command?->warn('NotificationDemoSeeder: no brands — skipping.');

            return;
        }

        $org = Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->first();
        if ($org === null) {
            $this->command?->warn('NotificationDemoSeeder: no matching organization — skipping.');

            return;
        }

        $cast = $this->loadNamedCast();
        if ($cast['alice'] === null) {
            $this->command?->warn('NotificationDemoSeeder: demo users missing — run NotificationDemoUsersSeeder first.');

            return;
        }

        $branch = Branch::query()->where('console_brand_id', $brand->console_brand_id)->first();

        $this->seedAudiences($org, $brand, $branch, $cast);
        $this->seedTemplates($org, $brand);
        $this->seedRoutes($org);
        $this->seedPreferences($cast);
        $this->seedNotifications($org, $cast);
        $this->seedEmailSuppressions($org);

        $this->command?->info(sprintf(
            'NotificationDemoSeeder: %d audiences, %d templates, %d routes, %d preferences, %d notifications, %d email suppressions.',
            NotificationAudience::query()->count(),
            NotificationTemplate::query()->count(),
            NotificationChannelRoute::query()->count(),
            NotificationPreference::query()->count(),
            Notification::query()->count(),
            NotificationEmailSuppression::query()->count(),
        ));
    }

    /**
     * Seed a mix of suppression reasons so the email-health page has
     * something to display. Counts are intentionally varied across reasons
     * so the metric tiles (which only count hard_bounce + spam_complaint)
     * differ from the full list total.
     *
     * Idempotent via updateOrCreate on (organization_id, email, reason).
     */
    private function seedEmailSuppressions(Organization $org): void
    {
        $seeds = [
            // 4 hard_bounce — drives "Bị bounce" tile
            ['email' => 'bounce-1@example.com', 'reason' => 'hard_bounce', 'daysAgo' => 1],
            ['email' => 'bounce-2@example.com', 'reason' => 'hard_bounce', 'daysAgo' => 3],
            ['email' => 'bounce-3@example.com', 'reason' => 'hard_bounce', 'daysAgo' => 7],
            ['email' => 'bounce-4@example.com', 'reason' => 'hard_bounce', 'daysAgo' => 14],
            // 3 spam_complaint — drives "Báo cáo spam" tile
            ['email' => 'spam-1@example.com', 'reason' => 'spam_complaint', 'daysAgo' => 2],
            ['email' => 'spam-2@example.com', 'reason' => 'spam_complaint', 'daysAgo' => 5],
            ['email' => 'spam-3@example.com', 'reason' => 'spam_complaint', 'daysAgo' => 10],
            // 2 subscription_change — list-only (no tile)
            ['email' => 'unsub-1@example.com', 'reason' => 'subscription_change', 'daysAgo' => 4],
            ['email' => 'unsub-2@example.com', 'reason' => 'subscription_change', 'daysAgo' => 8],
            // 2 manual — list-only (no tile)
            ['email' => 'manual-1@example.com', 'reason' => 'manual', 'daysAgo' => 6],
            ['email' => 'manual-2@example.com', 'reason' => 'manual', 'daysAgo' => 12],
        ];

        foreach ($seeds as $seed) {
            NotificationEmailSuppression::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'email' => $seed['email'],
                    'reason' => $seed['reason'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'source_provider' => $seed['reason'] === 'manual' ? 'manual' : 'postmark',
                    'suppressed_at' => now()->subDays($seed['daysAgo']),
                    'un_suppressed_at' => null,
                ],
            );
        }
    }

    /**
     * @return array<string, User|null>
     */
    private function loadNamedCast(): array
    {
        return [
            'alice' => User::query()->where('email', 'alice@demo.test')->first(),
            'binh' => User::query()->where('email', 'binh@demo.test')->first(),
            'chi' => User::query()->where('email', 'chi@demo.test')->first(),
            'daiki' => User::query()->where('email', 'daiki@demo.test')->first(),
            'emi' => User::query()->where('email', 'emi@demo.test')->first(),
            'frank' => User::query()->where('email', 'frank@demo.test')->first(),
        ];
    }

    /**
     * @param  array<string, User|null>  $cast
     */
    private function seedAudiences(Organization $org, Brand $brand, ?Branch $branch, array $cast): void
    {
        $opsTeamIds = array_values(array_filter([$cast['binh']?->id, $cast['emi']?->id]));
        $shopManagerIds = array_values(array_filter([$cast['chi']?->id, $cast['daiki']?->id]));
        $leadershipIds = array_values(array_filter([$cast['alice']?->id, $cast['binh']?->id]));

        $audiences = [
            [
                'name' => 'Ops team (Bình + Emi)',
                'description' => 'Ops manager + warehouse staff — targeted by stock alerts.',
                'rule' => [
                    'version' => 1,
                    'combinator' => 'or',
                    'rules' => [['type' => 'user', 'user_ids' => $opsTeamIds]],
                ],
            ],
            [
                'name' => 'All shop managers (Chi + Daiki)',
                'description' => 'Shop managers of Shinjuku + Shibuya — receive order updates.',
                'rule' => [
                    'version' => 1,
                    'combinator' => 'or',
                    'rules' => [['type' => 'user', 'user_ids' => $shopManagerIds]],
                ],
            ],
            [
                'name' => 'Leadership (Alice + Bình)',
                'description' => 'Org admin + ops manager — receives critical system alerts.',
                'rule' => [
                    'version' => 1,
                    'combinator' => 'or',
                    'rules' => [['type' => 'user', 'user_ids' => $leadershipIds]],
                ],
            ],
            [
                'name' => 'Everyone in this brand',
                'description' => 'Full-brand broadcast audience — promos + Tết announcement.',
                'rule' => [
                    'version' => 1,
                    'combinator' => 'or',
                    'rules' => [['type' => 'brand', 'brand_id' => $brand->id, 'include_all_members' => true]],
                ],
            ],
            [
                'name' => 'Workstation devices (kitchen alerts)',
                'description' => 'Every paired workstation device — printer errors, kitchen recall.',
                'rule' => [
                    'version' => 1,
                    'combinator' => 'or',
                    'rules' => [['type' => 'device', 'device_types' => ['workstation']]],
                ],
            ],
        ];

        foreach ($audiences as $spec) {
            NotificationAudience::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'name' => $spec['name']],
                [
                    'organization_id' => $org->id,
                    'description' => $spec['description'],
                    'rule' => $spec['rule'],
                    'is_system' => false,
                ]
            );
        }
    }

    private function seedTemplates(Organization $org, Brand $brand): void
    {
        $templates = [
            [
                'key' => 'promo.welcome',
                'content' => [
                    'ja' => ['title' => 'ようこそ {{user_name}} さん 🎉', 'body' => '新しいキャンペーン "{{campaign_name}}" が開始しました。 {{coupon_code}} のクーポンをご利用ください。'],
                    'en' => ['title' => 'Welcome {{user_name}} 🎉', 'body' => 'Our new campaign "{{campaign_name}}" starts today. Use coupon code {{coupon_code}} at checkout.'],
                    'vi' => ['title' => 'Chào mừng {{user_name}} 🎉', 'body' => 'Campaign mới "{{campaign_name}}" đã bắt đầu. Dùng mã {{coupon_code}} khi thanh toán.'],
                ],
                'params_schema' => ['required' => ['user_name'], 'optional' => ['campaign_name', 'coupon_code']],
                'default_channels' => ['in_app', 'email'],
            ],
            [
                'key' => 'maintenance.scheduled',
                'content' => [
                    'ja' => ['title' => '🔧 メンテナンスのお知らせ', 'body' => '{{scheduled_at}} から {{duration_minutes}} 分間、システムメンテナンスを実施します。'],
                    'en' => ['title' => '🔧 Scheduled maintenance', 'body' => 'System maintenance starts at {{scheduled_at}} for ~{{duration_minutes}} minutes.'],
                    'vi' => ['title' => '🔧 Bảo trì hệ thống', 'body' => 'Hệ thống sẽ bảo trì từ {{scheduled_at}} trong ~{{duration_minutes}} phút.'],
                ],
                'params_schema' => ['required' => ['scheduled_at', 'duration_minutes'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            [
                'key' => 'ops.daily_summary',
                'content' => [
                    'ja' => ['title' => '📊 本日の運営サマリー', 'body' => '注文 {{order_count}} 件 / 売上 ¥{{revenue}} / アラート {{alert_count}} 件'],
                    'en' => ['title' => '📊 Daily ops summary', 'body' => '{{order_count}} orders · ¥{{revenue}} revenue · {{alert_count}} alerts'],
                    'vi' => ['title' => '📊 Tóm tắt vận hành hôm nay', 'body' => '{{order_count}} đơn · ¥{{revenue}} doanh thu · {{alert_count}} alert'],
                ],
                'params_schema' => ['required' => ['order_count', 'revenue', 'alert_count'], 'optional' => []],
                'default_channels' => ['in_app', 'email'],
            ],
            [
                'key' => 'brand.announcement',
                'content' => [
                    'ja' => ['title' => '📣 {{title}}', 'body' => '{{message}}'],
                    'en' => ['title' => '📣 {{title}}', 'body' => '{{message}}'],
                    'vi' => ['title' => '📣 {{title}}', 'body' => '{{message}}'],
                ],
                'params_schema' => ['required' => ['title', 'message'], 'optional' => []],
                'default_channels' => ['in_app', 'email', 'push'],
            ],
        ];

        foreach ($templates as $spec) {
            NotificationTemplate::query()->firstOrCreate(
                ['key' => $spec['key']],
                [
                    'organization_id' => $org->id,
                    'brand_id' => $brand->id,
                    'content' => $spec['content'],
                    'params_schema' => $spec['params_schema'],
                    'default_channels' => $spec['default_channels'],
                    'is_system' => false,
                ]
            );
        }
    }

    private function seedRoutes(Organization $org): void
    {
        $routes = [
            ['type' => 'stock.alert.low', 'channels' => ['in_app', 'email']],
            ['type' => 'stock.alert.out', 'channels' => ['in_app', 'realtime', 'email']],
            ['type' => 'recipe.approved', 'channels' => ['in_app']],
            ['type' => 'recipe.rejected', 'channels' => ['in_app']],
            ['type' => 'order.paid', 'channels' => ['in_app', 'realtime']],
            ['type' => 'order.status_changed', 'channels' => ['in_app', 'realtime']],
            [
                'type' => 'system.critical',
                'channels' => ['in_app', 'realtime'],
                'priority_overrides' => [
                    'urgent' => ['in_app', 'realtime', 'email', 'push'],
                    'high' => ['in_app', 'realtime', 'email'],
                ],
            ],
            ['type' => 'promo.welcome', 'channels' => ['in_app', 'email']],
            ['type' => 'brand.announcement', 'channels' => ['in_app', 'email']],
            ['type' => 'ops.daily_summary', 'channels' => ['in_app', 'email']],
            ['type' => 'maintenance.scheduled', 'channels' => ['in_app', 'realtime', 'email']],
        ];

        foreach ($routes as $spec) {
            NotificationChannelRoute::query()->updateOrCreate(
                ['organization_id' => $org->id, 'type' => $spec['type']],
                [
                    'channels' => $spec['channels'],
                    'priority_overrides' => $spec['priority_overrides'] ?? null,
                ]
            );
        }
    }

    /**
     * @param  array<string, User|null>  $cast
     */
    private function seedPreferences(array $cast): void
    {
        $alice = $cast['alice'];
        $frank = $cast['frank'];

        if ($alice !== null) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $alice->id, 'type' => '*', 'channel' => '*'],
                [
                    'enabled' => true,
                    'master_mute' => false,
                    'quiet_from' => '22:00',
                    'quiet_to' => '07:00',
                    'quiet_timezone' => 'Asia/Tokyo',
                ]
            );
        }

        if ($frank !== null) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $frank->id, 'type' => '*', 'channel' => '*'],
                ['enabled' => true, 'master_mute' => true]
            );
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $frank->id, 'type' => 'ops.daily_summary', 'channel' => 'email'],
                ['enabled' => false, 'master_mute' => false]
            );
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $frank->id, 'type' => 'promo.welcome', 'channel' => 'email'],
                ['enabled' => false, 'master_mute' => false]
            );
        }
    }

    /**
     * @param  array<string, User|null>  $cast
     */
    private function seedNotifications(Organization $org, array $cast): void
    {
        $service = app(NotificationService::class);
        $alice = $cast['alice'];
        $binh = $cast['binh'];
        $chi = $cast['chi'];
        $daiki = $cast['daiki'];
        $emi = $cast['emi'];
        $frank = $cast['frank'];

        $opsTeam = array_values(array_filter([$binh, $emi]));
        $shopManagers = array_values(array_filter([$chi, $daiki]));
        $leadership = array_values(array_filter([$alice, $binh]));
        $everyone = array_values(array_filter([$alice, $binh, $chi, $daiki, $emi, $frank]));

        $dispatches = [
            [
                'key' => 'demo:stock.alert.low:v3',
                'type' => 'stock.alert.low',
                'recipients' => $opsTeam,
                'actor' => null,
                'params' => ['warehouse_name' => 'Kitchen A', 'item_name' => 'Rice 5kg', 'current_quantity' => 2, 'min_stock' => 10],
                'priority' => NotificationPriorityEnum::Normal,
            ],
            [
                'key' => 'demo:stock.alert.out:v3',
                'type' => 'stock.alert.out',
                'recipients' => $opsTeam,
                'actor' => null,
                'params' => ['warehouse_name' => 'Kitchen A', 'item_name' => 'Soy sauce', 'current_quantity' => 0, 'min_stock' => 5],
                'priority' => NotificationPriorityEnum::High,
            ],
            [
                'key' => 'demo:recipe.approved:v3',
                'type' => 'recipe.approved',
                'recipients' => $binh ? [$binh] : [],
                'actor' => $alice,
                'params' => ['recipe_name' => 'Tonkotsu Ramen', 'approver' => $alice?->name ?? 'Alice'],
                'priority' => NotificationPriorityEnum::Normal,
            ],
            [
                'key' => 'demo:ops.daily_summary:'.now()->format('Y-m-d'),
                'type' => 'ops.daily_summary',
                'recipients' => $leadership,
                'actor' => null,
                'params' => ['order_count' => 37, 'revenue' => '124,500', 'alert_count' => 3],
                'priority' => NotificationPriorityEnum::Normal,
            ],
            [
                'key' => 'demo:promo.welcome:v3',
                'type' => 'promo.welcome',
                'recipients' => $everyone,
                'actor' => null,
                'params' => ['user_name' => 'Team', 'campaign_name' => 'Sakura Spring 2026', 'coupon_code' => 'SAKURA26'],
                'priority' => NotificationPriorityEnum::Normal,
            ],
            [
                'key' => 'demo:system.critical:v3',
                'type' => 'system.critical',
                'recipients' => $leadership,
                'actor' => null,
                'params' => ['message' => 'Reverb WebSocket server restarted (demo urgent)'],
                'priority' => NotificationPriorityEnum::Urgent,
            ],
            [
                'key' => 'demo:brand.announcement:tet2026',
                'type' => 'brand.announcement',
                'recipients' => $everyone,
                'actor' => $alice,
                'params' => ['title' => 'Tết 2026 schedule', 'message' => 'Shops closed Feb 2–6. Re-open Feb 7.'],
                'priority' => NotificationPriorityEnum::Normal,
            ],
            [
                'key' => 'demo:order.status_changed:v3',
                'type' => 'order.status_changed',
                'recipients' => $shopManagers,
                'actor' => $alice,
                'params' => ['order_code' => 'DEMO-0042', 'new_status' => 'ready', 'shop_name' => 'Shinjuku'],
                'priority' => NotificationPriorityEnum::Normal,
            ],
        ];

        foreach ($dispatches as $d) {
            if (empty($d['recipients'])) {
                continue;
            }
            $service->dispatch([
                'type' => $d['type'],
                'template_key' => $d['type'],
                'params' => $d['params'],
                'recipients' => $d['recipients'],
                'actor' => $d['actor'],
                'subject' => null,
                'organization_id' => $org->id,
                'priority' => $d['priority'],
                'idempotency_key' => $d['key'],
            ]);
        }

        // Scheduled — +2h future. Shows up on audit list as is_dispatched=false.
        $service->dispatch([
            'type' => 'maintenance.scheduled',
            'template_key' => 'maintenance.scheduled',
            'params' => ['scheduled_at' => now()->addHours(2)->toIso8601String(), 'duration_minutes' => 30],
            'recipients' => $everyone,
            'actor' => null,
            'organization_id' => $org->id,
            'priority' => NotificationPriorityEnum::Normal,
            'scheduled_for' => now()->addHours(2),
            'idempotency_key' => 'demo:maintenance.scheduled:v2',
        ]);

        $this->markMixedState($cast);
    }

    /**
     * @param  array<string, User|null>  $cast
     */
    private function markMixedState(array $cast): void
    {
        $alice = $cast['alice'];
        $binh = $cast['binh'];

        if ($alice !== null) {
            NotificationRecipient::query()
                ->forRecipient($alice)
                ->orderBy('created_at')
                ->get()
                ->each(function (NotificationRecipient $row, int $idx) {
                    if ($idx % 3 === 0) {
                        $row->markRead();
                    } elseif ($idx % 3 === 1) {
                        $row->markSeen();
                    }
                });
        }

        if ($binh !== null) {
            NotificationRecipient::query()
                ->forRecipient($binh)
                ->orderBy('created_at')
                ->first()
                ?->markDismissed();

            NotificationRecipient::query()
                ->forRecipient($binh)
                ->orderBy('created_at')
                ->offset(1)
                ->limit(2)
                ->get()
                ->each(fn (NotificationRecipient $r) => $r->markRead());
        }
    }
}
