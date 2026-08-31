<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * NotificationDemoUsersSeeder — creates a cast of named demo users so the
 * notification audit, inbox, and composer flows read "Alice sends X to
 * Bob and Chi" instead of anonymous UUIDs.
 *
 * Each user has a clear role badge (Org Admin / Shop Manager / Staff /
 * Shop Staff) and a memorable name + email so the HQ audit list +
 * `/inbox` + audience builder pickers become easy to reason about.
 *
 * Password for every demo user: `password` (standard dev default).
 *
 * Idempotent via `console_user_id`: re-running doesn't duplicate rows.
 */
class NotificationDemoUsersSeeder extends Seeder
{
    /**
     * @var array<int, array{
     *   id: string,
     *   name: string,
     *   email: string,
     *   role: string,
     *   locale: string,
     *   description: string,
     * }>
     */
    private const CAST = [
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000101',
            'name' => 'Alice Tanaka',
            'email' => 'alice@demo.test',
            'role' => 'org-admin',
            'locale' => 'ja',
            'description' => 'HQ brand admin — usually the actor when recipes are approved or broadcasts fire.',
        ],
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000102',
            'name' => 'Bình Nguyễn',
            'email' => 'binh@demo.test',
            'role' => 'org-manager',
            'locale' => 'vi',
            'description' => 'Ops manager — receives daily summary + urgent stock alerts.',
        ],
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000103',
            'name' => 'Chi Lê',
            'email' => 'chi@demo.test',
            'role' => 'shop-manager',
            'locale' => 'vi',
            'description' => 'Shop manager (Shinjuku) — receives order.paid + stock alerts scoped to the shop.',
        ],
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000104',
            'name' => 'Daiki Sato',
            'email' => 'daiki@demo.test',
            'role' => 'shop-manager',
            'locale' => 'ja',
            'description' => 'Shop manager (Shibuya) — 2nd shop so audiences with shop_ids[] show variety.',
        ],
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000105',
            'name' => 'Emi Watanabe',
            'email' => 'emi@demo.test',
            'role' => 'staff',
            'locale' => 'en',
            'description' => 'Warehouse/inventory staff — gets stock.alert.low + receives the broadcast announcements.',
        ],
        [
            'id' => '019e8a3b-8001-7a00-8001-000000000106',
            'name' => 'Frank Kim',
            'email' => 'frank@demo.test',
            'role' => 'shop-staff',
            'locale' => 'en',
            'description' => 'Shop floor staff — opt-out test case; demonstrates type opt-out on the preferences UI.',
        ],
    ];

    public function run(): void
    {
        $org = Organization::query()->where('console_organization_id', MockDataSeeder::ORG_CONSOLE_ID)->first();
        if ($org === null) {
            $this->command?->warn('NotificationDemoUsersSeeder: no primary organization — run MockDataSeeder first.');

            return;
        }

        $brand = Brand::query()->where('console_organization_id', $org->console_organization_id)->first();

        foreach (self::CAST as $spec) {
            $user = User::query()->firstOrCreate(
                ['console_user_id' => $spec['id']],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $spec['name'],
                    'email' => $spec['email'],
                    'password' => bcrypt('password'),
                    'console_organization_id' => $org->console_organization_id,
                    'is_active' => true,
                    'locale' => $spec['locale'],
                ]
            );

            // assignRole from HasSsoRoles is idempotent — safe to re-run.
            $user->assignRole($spec['role'], $org->id);
        }

        $this->command?->info('NotificationDemoUsersSeeder: '.count(self::CAST).' demo users ready.');
        $this->command?->line('  Password for every demo user: password');
        $this->command?->line('  Brand: '.($brand?->name ?? '—').' · Org: '.$org->name);
        foreach (self::CAST as $spec) {
            $this->command?->line(sprintf('  %s · %-18s · %s', $spec['email'], $spec['role'], $spec['name']));
        }
    }

    /**
     * @return array<int, string>
     */
    public static function consoleUserIds(): array
    {
        return array_column(self::CAST, 'id');
    }
}
