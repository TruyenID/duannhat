<?php

/**
 * Regenerates database/seeders/fixtures/catalog/*.json from a Betoya database.
 *
 * The source is a SEPARATE connection so a production dump can be loaded into a
 * scratch schema without touching the working database:
 *
 *   mysql -uroot -psecret -e 'create database tempo_snapshot'
 *   mysql -uroot -psecret tempo_snapshot < db-tempo.sql
 *   php artisan tinker --execute 'require database_path("seeders/fixtures/_dump_catalog.php");'
 *
 * Override the scratch schema with SNAPSHOT_DB_DATABASE.
 *
 * Every row is filtered down to the columns that exist in the CURRENT schema
 * (read from the default connection). A dump taken before a migration lands
 * would otherwise carry dropped columns into the fixture and break the upsert
 * in CatalogSnapshotSeeder — columns added since simply fall back to their
 * migration default.
 */

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$directory = __DIR__.'/catalog';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}

$sourceName = 'catalog_snapshot_source';
config()->set('database.connections.'.$sourceName, array_merge(
    config('database.connections.'.config('database.default')),
    ['database' => env('SNAPSHOT_DB_DATABASE', 'tempo_snapshot')],
));
$source = DB::connection($sourceName);

$table = fn (string $name): Builder => $source->table($name);

$brand = $table('brands')->where('slug', 'betoya')->first();
if ($brand === null) {
    throw new RuntimeException('Source brand [betoya] was not found.');
}

$branches = $table('branches')
    ->where('console_brand_id', $brand->console_brand_id)
    ->orderBy('slug')
    ->get();
$branchIds = $branches->pluck('id')->all();
$sourceOrganizationId = $table('organizations')
    ->where('console_organization_id', $brand->console_organization_id)
    ->value('id');

$ids = [];
$ids['product_types'] = $table('product_types')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['categories'] = $table('categories')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['products'] = $table('products')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['product_options'] = $table('product_options')->whereIn('product_id', $ids['products'])->pluck('id')->all();
$ids['product_option_values'] = $table('product_option_values')->whereIn('option_id', $ids['product_options'])->pluck('id')->all();
$ids['product_skus'] = $table('product_skus')->whereIn('product_id', $ids['products'])->pluck('id')->all();
$ids['topping_groups'] = $table('topping_groups')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['topping_group_items'] = $table('topping_group_items')->whereIn('topping_group_id', $ids['topping_groups'])->pluck('id')->all();
$ids['product_topping_groups'] = $table('product_topping_groups')->whereIn('product_id', $ids['products'])->pluck('id')->all();
$ids['materials'] = $table('materials')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['allergens'] = $table('allergens')->where('organization_id', $sourceOrganizationId)->pluck('id')->all();
$ids['recipes'] = $table('recipes')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['menus'] = $table('menus')
    ->where('brand_id', $brand->id)
    ->where(fn (Builder $query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
    ->pluck('id')->all();
$ids['menu_sections'] = $table('menu_sections')->where('brand_id', $brand->id)->pluck('id')->all();
$ids['menu_products'] = $table('menu_products')->whereIn('menu_id', $ids['menus'])->pluck('id')->all();
$ids['zones'] = $table('zones')->whereIn('branch_id', $branchIds)->pluck('id')->all();

$queries = [
    'organizations' => $table('organizations')->where('id', $sourceOrganizationId),
    // 'brands' ĐÃ GỠ (#2230): brands.json chưa bao giờ được seeder nào đọc
    // (CatalogSnapshotSeeder ĐÒI brand tồn tại trước — "must be synced before
    // catalog seeding" — và 'brands' không nằm trong TABLES), nhưng chụp nó là
    // chụp reverb_app_key/secret của tenant production vào git. Đừng thêm lại;
    // brand đến từ đường sync Platform, reverb do BrandBaselineProvisioner
    // provision.
    //
    // 'tax_types' KHÔNG nằm trong TABLES của seeder — nó không được upsert vào
    // DB đích (brand đích tự có ba loại thuế do baseline dựng). Chụp nó vì
    // `CatalogSnapshotSeeder::buildTaxTypeMap()` cần bảng tra `id nguồn → mã`
    // để ánh xạ `products.tax_type_id`. Thiếu file này thì mọi product mang
    // 軽減税率 sẽ ném lỗi ở lượt seed — cố ý, xem #2320.
    'tax_types' => $table('tax_types')->where('brand_id', $brand->id),
    'branches' => $table('branches')->whereIn('id', $branchIds),
    'branch_translations' => $table('branch_translations')->whereIn('branch_id', $branchIds),
    'product_types' => $table('product_types')->whereIn('id', $ids['product_types']),
    'product_type_translations' => $table('product_type_translations')->whereIn('product_type_id', $ids['product_types']),
    'categories' => $table('categories')->whereIn('id', $ids['categories']),
    'category_translations' => $table('category_translations')->whereIn('category_id', $ids['categories']),
    'product_category' => $table('product_category')->whereIn('product_id', $ids['products'])->whereIn('category_id', $ids['categories']),
    'products' => $table('products')->whereIn('id', $ids['products']),
    'product_translations' => $table('product_translations')->whereIn('product_id', $ids['products']),
    'product_options' => $table('product_options')->whereIn('id', $ids['product_options']),
    'product_option_translations' => $table('product_option_translations')->whereIn('product_option_id', $ids['product_options']),
    'product_option_values' => $table('product_option_values')->whereIn('id', $ids['product_option_values']),
    'product_option_value_translations' => $table('product_option_value_translations')->whereIn('product_option_value_id', $ids['product_option_values']),
    'allergens' => $table('allergens')->whereIn('id', $ids['allergens']),
    'allergen_translations' => $table('allergen_translations')->whereIn('allergen_id', $ids['allergens']),
    'materials' => $table('materials')->whereIn('id', $ids['materials']),
    'material_translations' => $table('material_translations')->whereIn('material_id', $ids['materials']),
    'material_units' => $table('material_units')->whereIn('material_id', $ids['materials']),
    'material_allergens' => $table('material_allergens')->whereIn('material_id', $ids['materials']),
    'recipes' => $table('recipes')->whereIn('id', $ids['recipes']),
    'recipe_translations' => $table('recipe_translations')->whereIn('recipe_id', $ids['recipes']),
    'product_skus' => $table('product_skus')->whereIn('id', $ids['product_skus']),
    'product_sku_translations' => $table('product_sku_translations')->whereIn('product_sku_id', $ids['product_skus']),
    'topping_groups' => $table('topping_groups')->whereIn('id', $ids['topping_groups']),
    'topping_group_translations' => $table('topping_group_translations')->whereIn('topping_group_id', $ids['topping_groups']),
    'topping_group_items' => $table('topping_group_items')->whereIn('id', $ids['topping_group_items']),
    'topping_group_item_skus' => $table('topping_group_item_skus')->whereIn('topping_group_item_id', $ids['topping_group_items']),
    'product_topping_groups' => $table('product_topping_groups')->whereIn('id', $ids['product_topping_groups']),
    'product_topping_group_item_overrides' => $table('product_topping_group_item_overrides')->whereIn('product_id', $ids['products'])->whereIn('topping_group_id', $ids['topping_groups']),
    'menu_sections' => $table('menu_sections')->whereIn('id', $ids['menu_sections']),
    'menu_section_translations' => $table('menu_section_translations')->whereIn('menu_section_id', $ids['menu_sections']),
    'menus' => $table('menus')->whereIn('id', $ids['menus']),
    'menu_translations' => $table('menu_translations')->whereIn('menu_id', $ids['menus']),
    'menu_menu_sections' => $table('menu_menu_sections')->whereIn('menu_id', $ids['menus'])->whereIn('menu_section_id', $ids['menu_sections']),
    'menu_products' => $table('menu_products')->whereIn('id', $ids['menu_products'])->whereIn('product_id', $ids['products']),
    'menu_product_skus' => $table('menu_product_skus')->whereIn('menu_product_id', $ids['menu_products'])->whereIn('product_sku_id', $ids['product_skus']),
    'menu_schedules' => $table('menu_schedules')->whereIn('menu_id', $ids['menus']),
    'zones' => $table('zones')->whereIn('id', $ids['zones']),
    'tables' => $table('tables')->whereIn('branch_id', $branchIds),
    'shop_order_settings' => $table('shop_order_settings')->whereIn('branch_id', $branchIds),
    'files' => $table('files')->where(function (Builder $query) use ($ids): void {
        $query->where(fn (Builder $products) => $products->where('fileable_type', 'Product')->whereIn('fileable_id', $ids['products']))
            ->orWhere(fn (Builder $skus) => $skus->where('fileable_type', 'ProductSku')->whereIn('fileable_id', $ids['product_skus']));
    }),
];

/**
 * The release bundles product/branch binaries under catalog/media, and
 * CatalogSnapshotSeeder asserts every manifest path is installed. A file row
 * whose binary lives only on the source server would render as a broken image
 * forever, so the snapshot keeps only rows the bundle can actually satisfy.
 */
$bundledPaths = array_flip(array_column(
    json_decode((string) @file_get_contents($directory.'/media_manifest.json'), true) ?? [],
    'path',
));
$unbundledFiles = $queries['files']->get()
    ->reject(fn (object $file): bool => isset($bundledPaths[$file->path]))
    ->all();
if ($unbundledFiles !== []) {
    $queries['files']->whereNotIn('id', array_column(array_map(fn (object $file) => (array) $file, $unbundledFiles), 'id'));
    echo 'skipped '.count($unbundledFiles)." file row(s) with no bundled binary:\n";
    foreach ($unbundledFiles as $file) {
        echo "  {$file->path}\n";
    }
}

/** Columns the CURRENT schema still has; anything else is dropped from the fixture. */
$targetColumns = [];
$keepTargetColumns = function (string $name, array $row) use (&$targetColumns): array {
    if (! array_key_exists($name, $targetColumns)) {
        $targetColumns[$name] = Schema::hasTable($name)
            ? array_flip(Schema::getColumnListing($name))
            : [];
    }

    return array_intersect_key($row, $targetColumns[$name]);
};

$write = function (string $name, array $rows) use ($directory): void {
    file_put_contents(
        $directory."/{$name}.json",
        json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
    );
};

/**
 * The prune list removes file rows a long-dead fixture era installed; it is
 * carried forward verbatim, never grown from the outgoing files.json.
 *
 * Growing it looks tidy and is quietly destructive: production runs this
 * snapshot on every deploy, so every id added here is a real file row deleted
 * from a live shop. A row the new export merely could not carry — orphaned
 * owner, binary absent from the release bundle — is not a row production wants
 * gone.
 */
$previousPruneIds = array_map(
    'strval',
    json_decode((string) @file_get_contents($directory.'/files_prune.json'), true) ?? [],
);

$counts = [];
$dropped = [];
foreach ($queries as $name => $query) {
    $rows = $query->get()->map(function (object $row) use ($name, $keepTargetColumns, &$dropped): array {
        $row = (array) $row;
        $kept = $keepTargetColumns($name, $row);
        foreach (array_diff(array_keys($row), array_keys($kept)) as $column) {
            $dropped[$name][$column] = true;
        }

        return $kept;
    })->all();

    $write($name, $rows);
    $counts[$name] = count($rows);
    echo str_pad($name, 42).count($rows)."\n";
}

$write('files_prune', array_values(array_diff(
    array_unique($previousPruneIds),
    array_column($queries['files']->get()->map(fn (object $row) => (array) $row)->all(), 'id'),
)));

/** Branch cover art is applied by slug mapping, not by primary key. */
$write('branch_media', $branches->map(fn (object $branch): array => [
    'source_branch_id' => $branch->id,
    'banner_desktop' => $branch->banner_desktop,
    'banner_mobile' => $branch->banner_mobile,
    'banner_tablet' => $branch->banner_tablet,
    'img_branches' => $branch->img_branches,
    'logo' => $branch->logo,
])->all());

$manifest = [
    'source' => [
        'organization_id' => $sourceOrganizationId,
        'brand_id' => $brand->id,
        'brand_slug' => $brand->slug,
        'branches' => $branches->mapWithKeys(fn (object $branch) => [$branch->id => $branch->slug])->all(),
    ],
    'counts' => $counts,
];
file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

foreach ($dropped as $name => $columns) {
    echo "dropped (absent from current schema) {$name}: ".implode(', ', array_keys($columns))."\n";
}

echo "done\n";
