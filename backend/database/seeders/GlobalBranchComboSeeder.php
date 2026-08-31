<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Database\Seeder;

/**
 * Seeds 3 global branches (Tokyo, New York, London) with different timezones and combo products.
 *
 * Creates:
 * - 3 branches with timezone: Asia/Tokyo, America/New_York, Europe/London
 * - 3 combo products per branch (Breakfast, Lunch, Dinner combos)
 * - Topping groups with local menu items
 * - Menu with featured section
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=GlobalBranchComboSeeder
 */
class GlobalBranchComboSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        if (! $org) {
            $this->command->error('No organization found. Run MockDataSeeder first.');

            return;
        }

        $brand = Brand::where('is_active', true)->first();
        if (! $brand) {
            $this->command->error('No brand found. Login via SSO first.');

            return;
        }

        $orgId = $org->id;
        $brandId = $brand->id;
        $consoleBrandId = $brand->console_brand_id;

        $this->command->info("Organization: {$org->name}");
        $this->command->info("Brand: {$brand->name}");

        // Define 3 global branches with different timezones
        $branchDefs = [
            [
                'slug' => 'tokyo',
                'name' => '東京店 (Tokyo)',
                'timezone' => 'Asia/Tokyo',
                'currency' => 'JPY',
                'locale' => 'ja',
                'address' => '東京都渋谷区道玄坂2-10-12 新大宗ビル1号館 7階',
                'phone' => '+81-3-1234-5678',
                'hours' => '07:00 – 23:00',
            ],
            [
                'slug' => 'newyork',
                'name' => 'New York Store',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'locale' => 'en',
                'address' => '123 Broadway, New York, NY 10013',
                'phone' => '+1-212-555-0123',
                'hours' => '08:00 AM – 10:00 PM',
            ],
            [
                'slug' => 'london',
                'name' => 'London Store',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'locale' => 'en',
                'address' => '45 Oxford Street, London W1D 2DX',
                'phone' => '+44-20-7946-0958',
                'hours' => '09:00 – 22:00',
            ],
        ];

        foreach ($branchDefs as $branchDef) {
            $this->seedBranch($orgId, $brandId, $consoleBrandId, $branchDef);
        }

        $this->command->info("\nDone! Visit customer-web to see branches with combo products.");
    }

    private function seedBranch(string $orgId, string $brandId, ?string $consoleBrandId, array $def): void
    {
        $this->command->info("\n--- {$def['name']} ({$def['timezone']}) ---");

        // Create or update branch
        $branch = Branch::updateOrCreate(
            ['slug' => $def['slug'], 'console_organization_id' => Organization::first()->console_organization_id],
            [
                'console_brand_id' => $consoleBrandId,
                'name' => $def['name'],
                'is_headquarters' => false,
                'is_active' => true,
                'timezone' => $def['timezone'],
                'currency' => $def['currency'],
                'locale' => $def['locale'],
                'address' => $def['address'],
                'phone' => $def['phone'],
                'seat_capacity' => 50,
                'business_hours' => $def['hours'],
                'img_branches' => 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=1200&q=80',
                'weekly_hours' => $this->buildWeeklyHours(),
            ]
        );

        $this->command->info("Branch created: {$branch->name}");

        // Create menu for this branch
        $menu = $this->createMenu($orgId, $brandId, $branch);

        // Create combo products
        $this->createCombos($orgId, $brandId, $branch, $menu);
    }

    private function buildWeeklyHours(): array
    {
        return [
            'mon' => ['open' => '07:00', 'close' => '23:00', 'closed' => false],
            'tue' => ['open' => '07:00', 'close' => '23:00', 'closed' => false],
            'wed' => ['open' => '07:00', 'close' => '23:00', 'closed' => false],
            'thu' => ['open' => '07:00', 'close' => '23:00', 'closed' => false],
            'fri' => ['open' => '07:00', 'close' => '23:30', 'closed' => false],
            'sat' => ['open' => '08:00', 'close' => '23:30', 'closed' => false],
            'sun' => ['open' => '08:00', 'close' => '22:00', 'closed' => false],
        ];
    }

    private function createMenu(string $orgId, string $brandId, Branch $branch): Menu
    {
        $menu = Menu::firstOrCreate(
            ['branch_id' => $branch->id, 'name' => "{$branch->name} — Menu"],
            [
                'organization_id' => $orgId,
                'brand_id' => $brandId,
                'priority' => 1,
                'status' => 'Active',
                'is_master' => false,
            ]
        );

        $this->command->info("  Menu: {$menu->name}");

        return $menu;
    }

    private function createCombos(string $orgId, string $brandId, Branch $branch, Menu $menu): void
    {
        // Ensure product types exist
        $comboType = ProductType::firstOrCreate(
            ['brand_id' => $brandId, 'code' => 'combo'],
            [
                'organization_id' => $orgId,
                'name' => 'Combo',
                'product_form' => 'physical',
                'has_recipe' => false,
                'is_inventory_tracked' => false,
                'is_active' => true,
            ]
        );

        $toppingType = ProductType::firstOrCreate(
            ['brand_id' => $brandId, 'code' => 'topping'],
            [
                'organization_id' => $orgId,
                'name' => 'Topping',
                'product_form' => 'physical',
            ]
        );

        // Get or create featured section
        $featuredSection = $menu->menuSections()
            ->where('name', 'like', '%Featured%')
            ->orWhere('name', 'like', '%おすすめ%')
            ->first();

        if (! $featuredSection) {
            $featuredSection = MenuSection::create(['name' => 'Featured Combos', 'is_featured' => true]);
            $menu->menuSections()->attach($featuredSection->id, ['display_order' => 0]);
        }

        // Define combos based on branch locale
        $comboDefs = $this->getComboDefs($branch->locale);

        foreach ($comboDefs as $comboDef) {
            $this->createCombo($orgId, $brandId, $comboType, $toppingType, $menu, $featuredSection, $comboDef);
        }
    }

    private function getComboDefs(string $locale): array
    {
        if ($locale === 'ja') {
            return [
                [
                    'name' => 'モーニングセット (Morning Set)',
                    'description' => '朝食セット - お好みのメイン + ドリンク',
                    'price' => 880,
                    'groups' => [
                        [
                            'name' => 'メイン (1つ選択)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'トースト&卵', 'price' => 0],
                                ['name' => 'パンケーキ', 'price' => 100],
                                ['name' => 'おにぎり2個', 'price' => 0],
                            ],
                        ],
                        [
                            'name' => 'ドリンク (1つ選択)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'コーヒー', 'price' => 0],
                                ['name' => 'オレンジジュース', 'price' => 0],
                                ['name' => 'ミルク', 'price' => 0],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'ランチセット (Lunch Set)',
                    'description' => '昼食セット - メイン + サイド + ドリンク',
                    'price' => 1280,
                    'groups' => [
                        [
                            'name' => 'メイン (1つ選択)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'カレーライス', 'price' => 0],
                                ['name' => 'パスタ', 'price' => 100],
                                ['name' => '丼ぶり', 'price' => 0],
                            ],
                        ],
                        [
                            'name' => 'サイド (1つ選択)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'サラダ', 'price' => 0],
                                ['name' => 'スープ', 'price' => 0],
                            ],
                        ],
                        [
                            'name' => 'ドリンク (1つ選択)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'アイスティー', 'price' => 0],
                                ['name' => 'コーラ', 'price' => 0],
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            // English combos for NY and London
            return [
                [
                    'name' => 'Breakfast Combo',
                    'description' => 'Start your day right - Main + Drink',
                    'price' => 8.99,
                    'groups' => [
                        [
                            'name' => 'Choose Main (1 item)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'Eggs & Toast', 'price' => 0],
                                ['name' => 'Pancakes', 'price' => 1.00],
                                ['name' => 'Breakfast Burrito', 'price' => 0.50],
                            ],
                        ],
                        [
                            'name' => 'Choose Drink (1 item)',
                            'selection_type' => 'single',
                            'min_select' => 1,
                            'max_select' => 1,
                            'items' => [
                                ['name' => 'Coffee', 'price' => 0],
                                ['name' => 'Orange Juice', 'price' => 0],
                                ['name' => 'Tea', 'price' => 0],
                            ],
                        ],
                    ],
                ],
            ];
        }
    }
}
