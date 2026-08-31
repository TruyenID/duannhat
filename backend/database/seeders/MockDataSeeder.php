<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Mock Data Seeder
 *
 * Seeds the console-synced data (organization, brand, branches, roles, admin user)
 * that would normally arrive via SSO login. Useful for local dev without console.
 *
 * The tenant roots mirror the production Betoya database one-for-one — same
 * organization, brand, branch codes, slugs, names and per-branch details — so a
 * local `migrate:fresh --seed` lands the CatalogSnapshotSeeder fixture onto the
 * branches it was exported from. Only the console identifiers stay synthetic:
 * the real ones belong to the SSO console, and the dev bypass keys off these.
 *
 * Usage:
 *   php artisan db:seed --class=MockDataSeeder
 *
 * Then optionally seed sample warehouses/devices:
 *   php artisan db:seed --class=LocalDevSeeder
 */
class MockDataSeeder extends Seeder
{
    use RefusesToRunInProduction;

    // Console IDs matching the dev console fixture
    const ORG_CONSOLE_ID = '00000000-aaaa-4aaa-aaaa-000000000001';

    const BRAND_IDS = [
        'betoya' => '00000001-bbbb-4bbb-bbbb-000000000001',
    ];

    const ADMIN_CONSOLE_USER_ID = '019e8a3b-8001-7a00-8001-000000000001';

    /**
     * Branch roots as they exist in production, keyed by branch code. The
     * console_branch_id is derived from the code so it stays stable across
     * re-seeds without inventing a second identifier to keep in sync.
     *
     * Address/hours/seat data is only present where production actually has it;
     * the back-office, factory and pop-up event branches genuinely carry none.
     *
     * @var list<array<string, mixed>>
     */
    private const BRANCHES = [
        ['code' => 'BETOYA-001', 'slug' => 'head-office', 'name' => '本社', 'is_headquarters' => true],
        ['code' => 'BETOYA-002', 'slug' => 'sumiyoshi-kitchen', 'name' => '住吉キッチン'],
        ['code' => 'BETOYA-003', 'slug' => 'tsukiji', 'name' => '築地店'],
        ['code' => 'BETOYA-004', 'slug' => 'hongo', 'name' => '本郷店'],
        ['code' => 'BETOYA-005', 'slug' => 'tameike-sanno', 'name' => '溜池山王店'],
        ['code' => 'BETOYA-007', 'slug' => 'shiroi-factory', 'name' => '白井工場'],
        ['code' => 'BETOYA-008', 'slug' => 'jimbocho', 'name' => '神保町店'],
        [
            'code' => 'BETOYA-009',
            'slug' => 'ningyocho',
            'name' => '人形町店',
            'address' => '東京都中央区日本橋堀留町1-8-9-1階',
            'phone' => '+0366619940',
            // Cover art (img_branches / banners / logo) is applied by
            // CatalogSnapshotSeeder from branch_media.json — it ships the
            // matching binary, so it owns the path.
            'seat_capacity' => 23,
            'business_hours' => '11:00 - 22:00',
            'weekly_hours' => [
                'mon' => ['open' => '11:00', 'close' => '22:00'],
                'tue' => ['open' => '11:00', 'close' => '22:00'],
                'wed' => ['open' => '06:00', 'close' => '22:00'],
                'thu' => ['open' => '11:00', 'close' => '22:00'],
                'fri' => ['open' => '11:00', 'close' => '22:00'],
                'sat' => ['open' => '11:00', 'close' => '22:00'],
                'sun' => ['open' => '11:00', 'close' => '22:00'],
            ],
        ],
        ['code' => 'BETOYA-010', 'slug' => 'laqua-dd', 'name' => 'ラクーアDD店'],
        ['code' => 'BETOYA-011', 'slug' => 'ningyocho-delicatessen', 'name' => '人形町惣菜部'],
        ['code' => 'BETOYA-012', 'slug' => 'event-store', 'name' => 'イベント出店'],
        ['code' => 'BETOYA-014', 'slug' => 'tokyu-kichijoji-event', 'name' => '東急吉祥寺-Event'],
        ['code' => 'BETOYA-015', 'slug' => 'monzen-nakacho', 'name' => '門前仲町店'],
        ['code' => 'BETOYA-016', 'slug' => 'aeon-mall-tsudanuma', 'name' => 'イオンモール津田沼店'],
        ['code' => 'BETOYA-017', 'slug' => 'hikarie-norengai', 'name' => 'ヒカリエのれん街店'],
        ['code' => 'BETOYA-018', 'slug' => 'sogo-chiba', 'name' => 'SOGO千葉店'],
        ['code' => 'BETOYA-019', 'slug' => 'marui-kinshicho-event', 'name' => 'マルイ錦糸町-Event'],
    ];

    /** Slug of the branch demo seeders anchor on — the flagship shop in production. */
    const DEMO_SHOP_SLUG = 'ningyocho';

    private static function consoleBranchId(string $code): string
    {
        return sprintf('00000001-cccc-4ccc-cccc-%012d', (int) substr($code, -3));
    }

    public function run(): void
    {
        $this->guardAgainstProduction();

        $this->seedOrganization();
        $this->seedBrands();
        $this->seedBranches();
        $this->seedRoles();
        $this->seedAdminUser();

        $this->command->info('Mock data seeded successfully.');
        $this->command->info('Admin login: admin@famgia.com / password');
    }

    // =========================================================================
    //  Organization
    // =========================================================================

    private function seedOrganization(): void
    {
        Organization::updateOrCreate(
            ['console_organization_id' => self::ORG_CONSOLE_ID],
            [
                'name' => 'ベト屋フーズ株式会社',
                'slug' => 'betoya',
                'is_active' => true,
            ]
        );

        $this->command->info('Organization: ベト屋フーズ株式会社');
    }

    // =========================================================================
    //  Brands
    // =========================================================================

    private function seedBrands(): void
    {
        Brand::updateOrCreate(
            ['console_brand_id' => self::BRAND_IDS['betoya']],
            [
                'console_organization_id' => self::ORG_CONSOLE_ID,
                'name' => 'Betoya',
                'slug' => 'betoya',
                'description' => 'ベトナム料理専門店',
                'is_active' => true,
            ]
        );

        $this->command->info('Brand: Betoya');
    }

    // =========================================================================
    //  Branches
    // =========================================================================

    private function seedBranches(): void
    {
        foreach (self::BRANCHES as $branch) {
            Branch::updateOrCreate(
                ['console_branch_id' => self::consoleBranchId($branch['code'])],
                [
                    'console_organization_id' => self::ORG_CONSOLE_ID,
                    'console_brand_id' => self::BRAND_IDS['betoya'],
                    'code' => $branch['code'],
                    'slug' => $branch['slug'],
                    'name' => $branch['name'],
                    'timezone' => 'Asia/Tokyo',
                    'currency' => 'JPY',
                    'locale' => 'ja',
                    'is_headquarters' => $branch['is_headquarters'] ?? false,
                    'is_active' => true,
                    'address' => $branch['address'] ?? null,
                    'phone' => $branch['phone'] ?? null,
                    'img_branches' => $branch['img_branches'] ?? null,
                    'seat_capacity' => $branch['seat_capacity'] ?? null,
                    'business_hours' => $branch['business_hours'] ?? null,
                    'weekly_hours' => $branch['weekly_hours'] ?? null,
                ]
            );
        }

        $this->command->info(sprintf('Branches: %d (%s)', count(self::BRANCHES), implode(', ', array_column(self::BRANCHES, 'slug'))));
    }

    // =========================================================================
    //  Roles
    // =========================================================================

    private function seedRoles(): void
    {
        $roles = [
            ['slug' => 'org-admin',   'name' => 'Organization Admin',   'level' => 100],
            ['slug' => 'org-manager', 'name' => 'Organization Manager', 'level' => 50],
            ['slug' => 'staff',       'name' => 'Staff',                'level' => 10],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['slug' => $role['slug'], 'console_organization_id' => null],
                ['name' => $role['name'], 'level' => $role['level']]
            );
        }

        $this->command->info('Roles: org-admin, org-manager, staff');
    }

    // =========================================================================
    //  Admin User
    // =========================================================================

    private function seedAdminUser(): void
    {
        $user = User::firstOrCreate(
            [
                'console_user_id' => self::ADMIN_CONSOLE_USER_ID,
                'console_organization_id' => self::ORG_CONSOLE_ID,
            ],
            [
                'name' => 'Famgia Admin',
                'email' => 'admin@famgia.com',
                'password' => bcrypt('password'),
                'is_active' => true,
                'locale' => 'vi',
            ]
        );

        // Use local organization ID (not console ID) — role_user_pivots now uses local FKs.
        $org = Organization::where('console_organization_id', self::ORG_CONSOLE_ID)->first();

        if ($org) {
            $user->assignRole('org-admin', $org->id);
        }

        $this->command->info('Admin user: admin@famgia.com / password');
    }
}
