<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Shop Manager User Seeder
 *
 * Creates manager and staff users scoped to the demo shop branch so the
 * shop-role UI test plan has real accounts to sign in with. Idempotent.
 *
 * Logins:
 *   shop-manager-sjk@famgia.com / password    (shop-manager scoped to the demo shop)
 *   shop-staff-sjk@famgia.com  / password    (shop-staff scoped to the demo shop)
 */
class ShopManagerUserSeeder extends Seeder
{
    use RefusesToRunInProduction;

    private const SHOP_SLUG = MockDataSeeder::DEMO_SHOP_SLUG;

    private const USERS = [
        [
            'console_user_id' => '019e8a3b-8001-7a00-8001-000000000010',
            'name' => 'Demo Shop Manager',
            'email' => 'shop-manager-sjk@famgia.com',
            'role_slug' => 'shop-manager',
        ],
        [
            'console_user_id' => '019e8a3b-8001-7a00-8001-000000000011',
            'name' => 'Demo Shop Staff',
            'email' => 'shop-staff-sjk@famgia.com',
            'role_slug' => 'shop-staff',
        ],
    ];

    public function run(): void
    {
        $this->guardAgainstProduction();

        $branch = Branch::where('slug', self::SHOP_SLUG)->first();

        if (! $branch) {
            $this->command->warn('Demo shop branch ['.self::SHOP_SLUG.'] not found — run MockDataSeeder first. Skipping ShopManagerUserSeeder.');

            return;
        }

        $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();

        if (! $organization) {
            $this->command->warn('Demo shop branch has no organization — skipping ShopManagerUserSeeder.');

            return;
        }

        foreach (self::USERS as $userData) {
            $user = User::firstOrCreate(
                [
                    'console_user_id' => $userData['console_user_id'],
                    'console_organization_id' => $organization->console_organization_id,
                ],
                [
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => bcrypt('password'),
                    'is_active' => true,
                    'locale' => 'ja',
                ]
            );

            $user->assignRole($userData['role_slug'], $organization->id, $branch->id);

            $this->command->info("User: {$userData['email']} / password ({$userData['role_slug']} @ ".self::SHOP_SLUG.')');
        }
    }
}
