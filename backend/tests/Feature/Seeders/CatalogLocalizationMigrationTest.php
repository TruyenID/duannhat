<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use Database\Seeders\BetoyaCatalogLocalizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates constrained translation tables with non-null names and cascading parents', function (string $table, string $foreignKey, string $parentTable) {
    expect(Schema::hasColumns($table, ['id', $foreignKey, 'locale', 'name']))->toBeTrue();

    $parent = match ($parentTable) {
        'branches' => Branch::factory()->create(),
        'menus' => Menu::factory()->create(),
        'menu_sections' => MenuSection::factory()->create(),
    };

    DB::table($table)->where($foreignKey, $parent->id)->delete();

    DB::table($table)->insert([$foreignKey => $parent->id, 'locale' => 'en', 'name' => 'English']);

    expect(fn () => DB::table($table)->insert([
        $foreignKey => $parent->id,
        'locale' => 'en',
        'name' => 'Duplicate',
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table($table)->insert([
            $foreignKey => $parent->id,
            'locale' => 'vi',
            'name' => null,
        ]))->toThrow(QueryException::class);

    if (DB::getDriverName() === 'sqlite') {
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list({$table})"));
        expect($foreignKeys->contains(
            fn (object $key): bool => $key->table === $parentTable && strtoupper($key->on_delete) === 'CASCADE',
        ))->toBeTrue();
    }
})->with([
    'branch' => ['branch_translations', 'branch_id', 'branches'],
    'menu' => ['menu_translations', 'menu_id', 'menus'],
    'section' => ['menu_section_translations', 'menu_section_id', 'menu_sections'],
]);

it('backfills translations without overwriting legacy base columns', function () {
    $brand = Brand::factory()->create([
        'slug' => 'betoya',
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $brand->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'jimbocho',
        'name' => '神保町店',
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id,
        'name' => '神保町店 メニュー',
    ]);

    $this->seed(BetoyaCatalogLocalizationSeeder::class);

    expect($branch->fresh()->getRawOriginal('name'))->toBe('神保町店')
        ->and($menu->fresh()->getRawOriginal('name'))->toBe('神保町店 メニュー');
    $this->assertDatabaseHas('branch_translations', [
        'branch_id' => $branch->id, 'locale' => 'en', 'name' => 'Jimbocho Store',
    ]);
    $this->assertDatabaseHas('menu_translations', [
        'menu_id' => $menu->id, 'locale' => 'vi', 'name' => 'Menu cửa hàng Jimbocho',
    ]);
});

it('rolls back every localization write when a nested translation insert fails', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The deterministic failure trigger is SQLite-specific.');
    }

    $brand = Brand::factory()->create([
        'slug' => 'betoya',
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $brand->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => 'jimbocho',
        'name' => '神保町店',
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brand->id,
        'name' => '神保町店 メニュー',
    ]);
    DB::table('branch_translations')->where('branch_id', $branch->id)->delete();
    DB::table('menu_translations')->where('menu_id', $menu->id)->delete();

    DB::statement(<<<'SQL'
        CREATE TRIGGER force_localization_failure
        BEFORE INSERT ON menu_translations
        WHEN NEW.locale = 'en'
        BEGIN
            SELECT RAISE(ABORT, 'forced localization failure');
        END
    SQL);

    try {
        expect(fn () => $this->seed(BetoyaCatalogLocalizationSeeder::class))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS force_localization_failure');
    }

    expect(DB::table('branch_translations')->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(DB::table('menu_translations')->where('menu_id', $menu->id)->count())->toBe(0);
});
