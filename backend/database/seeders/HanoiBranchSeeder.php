<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HanoiBranchSeeder extends Seeder
{
    /**
     * Seed Hanoi branch for Betoya brand with Vietnam timezone.
     */
    public function run(): void
    {
        $this->command->info('Creating Hanoi branch...');

        // Get Famgia organization
        $org = Organization::where('console_organization_id', '00000000-aaaa-4aaa-aaaa-000000000001')->firstOrFail();
        $this->command->info("Organization: {$org->name}");

        // Get Betoya brand
        $brand = Brand::where('console_brand_id', '00000001-bbbb-4bbb-bbbb-000000000001')->firstOrFail();
        $this->command->info("Brand: {$brand->name}");

        // Find or create Hanoi branch, then update it
        $branch = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('slug', 'hanoi')
            ->first();

        if (! $branch) {
            $branch = Branch::create([
                'console_branch_id' => (string) Str::ulid(),
                'console_organization_id' => $org->console_organization_id,
                'console_brand_id' => $brand->console_brand_id,
                'slug' => 'hanoi',
                'name' => 'Chi nhánh Hà Nội',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'address' => '123 Phố Huế, Hai Bà Trưng, Hà Nội',
                'phone' => '+84 24 3974 1234',
                'is_active' => true,
            ]);
        } else {
            $branch->update([
                'console_brand_id' => $brand->console_brand_id,
                'name' => 'Chi nhánh Hà Nội',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'address' => '123 Phố Huế, Hai Bà Trưng, Hà Nội',
                'phone' => '+84 24 3974 1234',
                'is_active' => true,
            ]);
        }

        $this->command->info("✓ Branch created: {$branch->name} (timezone: {$branch->timezone})");
    }
}
