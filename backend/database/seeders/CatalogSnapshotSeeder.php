<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\TaxType;
use App\Services\Provisioning\BrandBaselineProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restores the Betoya catalog/menu snapshot while keeping tenant roots owned by Platform.
 */
class CatalogSnapshotSeeder extends Seeder
{
    private const FIXTURE_DIR = __DIR__.'/fixtures/catalog';

    private const MEDIA_DIR = self::FIXTURE_DIR.'/media';

    private const CHUNK = 200;

    /** @var list<string> */
    private const TABLES = [
        'branch_translations',
        'product_types', 'product_type_translations',
        'categories', 'category_translations',
        'products', 'product_translations', 'product_category',
        'product_options', 'product_option_translations',
        'product_option_values', 'product_option_value_translations',
        'allergens', 'allergen_translations',
        'materials', 'material_translations', 'material_units', 'material_allergens',
        'recipes', 'recipe_translations',
        'product_skus', 'product_sku_translations',
        'topping_groups', 'topping_group_translations',
        'topping_group_items', 'topping_group_item_skus',
        'product_topping_groups', 'product_topping_group_item_overrides',
        'files',
        'menu_sections', 'menu_section_translations',
        'menus', 'menu_translations', 'menu_menu_sections',
        'menu_products', 'menu_product_skus', 'menu_schedules',
        'zones', 'tables',
        'shop_order_settings',
    ];

    /** @var array<string, list<string>> */
    private const UNIQUE_KEYS = [
        'menu_menu_sections' => ['menu_id', 'menu_section_id'],
        // The snapshot regenerates this pivot's surrogate id on every dump, so
        // a stale (soft-deleted) row and its live replacement can share the
        // same natural key with different ids — upsert by the natural key so
        // the later (live) row wins instead of colliding on the DB unique
        // index (menu_id, product_id, menu_section_id).
        'menu_products' => ['menu_id', 'product_id', 'menu_section_id'],
    ];

    /**
     * id loại thuế trong ảnh chụp → id loại thuế của brand đích.
     *
     * @var array<string, string>
     */
    private array $taxTypeMap = [];

    public function run(): void
    {
        $manifest = $this->fixture('manifest');
        $source = $manifest['source'] ?? null;
        if (! is_array($source)) {
            throw new RuntimeException('Catalog snapshot manifest is missing.');
        }

        $brandSlug = (string) $source['brand_slug'];
        $brand = Brand::query()->where('slug', $brandSlug)->where('is_active', true)->first();
        if (! $brand instanceof Brand) {
            throw new RuntimeException("Platform brand [{$brandSlug}] must be synced before catalog seeding.");
        }

        $organization = Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->first();
        if (! $organization instanceof Organization) {
            throw new RuntimeException("Platform organization for brand [{$brandSlug}] is missing.");
        }

        $branchMap = $this->branchMap($organization, $brand, $source['branches'] ?? []);
        $allowedMenuIds = [];
        $allowedMenuProductIds = [];
        $allowedToppingGroupItemIds = [];
        $liveProductIds = array_column(array_filter(
            $this->fixture('products'),
            fn (array $product): bool => ($product['deleted_at'] ?? null) === null,
        ), 'id');

        DB::transaction(function () use ($source, $organization, $brand, $branchMap, &$allowedMenuIds, &$allowedMenuProductIds, &$allowedToppingGroupItemIds, $liveProductIds): void {
            Schema::disableForeignKeyConstraints();

            try {
                foreach (array_chunk($this->fixture('files_prune'), self::CHUNK) as $ids) {
                    DB::table('files')->whereIn('id', $ids)->delete();
                }

                DB::table('product_types')
                    ->where('brand_id', $brand->id)
                    ->whereNotIn('id', array_column($this->fixture('product_types'), 'id'))
                    ->delete();
                DB::table('categories')
                    ->where('brand_id', $brand->id)
                    ->whereNotIn('id', array_column($this->fixture('categories'), 'id'))
                    ->delete();

                // Drifting pivots: the snapshot regenerates these join tables'
                // surrogate ids on every dump, so a plain upsert-by-id collides
                // with an already-seeded row's composite unique key (e.g.
                // product_topping_groups_product_id_topping_group_id_unique).
                // None are order-referenced, so clear the brand-scoped rows —
                // keyed by the STABLE entity ids (product / sku / topping-item),
                // never by the pivots' own drifting ids — and let the TABLES
                // loop reinsert them fresh. Child tables first so FK order holds
                // even with constraints disabled.
                $this->clearDriftingPivots(
                    array_column($this->fixture('products'), 'id'),
                    array_column($this->fixture('product_skus'), 'id'),
                    array_column($this->fixture('topping_group_items'), 'id'),
                );

                // TRƯỚC vòng lặp chỉ dựng loại thuế — `remapTaxTypeId()` cần
                // đích để trỏ tới. Cố ý KHÔNG chạy baseline đầy đủ ở đây: mục
                // `combo` tạo `product_types` mã `combo` với id mới, còn ảnh
                // chụp cũng mang một hàng `combo` với id nguồn ⇒ đụng unique
                // (brand_id, code). Baseline đầy đủ chạy sau vòng lặp.
                app(BrandBaselineProvisioner::class)->ensureTaxTypes($brand);
                $this->taxTypeMap = $this->buildTaxTypeMap($brand);

                foreach (self::TABLES as $table) {
                    $rows = $this->fixture($table);
                    $rows = $this->transformRows(
                        $table,
                        $rows,
                        $source,
                        $organization,
                        $brand,
                        $branchMap,
                        $allowedMenuIds,
                        $allowedMenuProductIds,
                        $allowedToppingGroupItemIds,
                        $liveProductIds,
                    );

                    if ($table === 'menus') {
                        $allowedMenuIds = array_column($rows, 'id');
                    }
                    if ($table === 'menu_products') {
                        $allowedMenuProductIds = array_column($rows, 'id');
                    }
                    if ($table === 'topping_group_items') {
                        $allowedToppingGroupItemIds = array_column($rows, 'id');
                    }

                    $this->upsert($table, $rows);
                }

                $this->pruneExcludedMenuProducts($allowedMenuProductIds);
                $this->pruneExcludedToppingGroupItems($allowedToppingGroupItemIds);
                $this->applyBranchMedia($branchMap);

                // Lượt thứ hai: đóng dấu loại thuế mặc định lên product mà ảnh
                // chụp KHÔNG nói gì (`tax_type_id` null). Chỉ những hàng đó —
                // 13 product mang 軽減税率 ở trên vừa được ánh xạ đúng và không
                // ai được phép ghi đè chúng nữa.
                $this->baseline($brand);
                $this->call(BetoyaCatalogLocalizationSeeder::class);
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });

        // Local needs the bundled binaries just as much as production does —
        // without them every product card and branch banner 404s and the seeded
        // catalog only looks right in the database. Tests are the exception:
        // they assert on rows, and copying 180-odd images per run costs more
        // than it proves (the media bundle has its own checksum test).
        if (! app()->runningUnitTests()) {
            $this->installPublicMedia();
            $this->ensurePublicStorageLink();
            $this->assertPublicMediaExists();
        }

        $this->command?->info(sprintf(
            'CatalogSnapshotSeeder: Betoya snapshot restored onto %d Platform branch mappings.',
            count($branchMap),
        ));
    }

    /** @param list<string> $allowedMenuProductIds */
    private function pruneExcludedMenuProducts(array $allowedMenuProductIds): void
    {
        $excludedIds = array_values(array_diff(
            array_column($this->fixture('menu_products'), 'id'),
            $allowedMenuProductIds,
        ));

        foreach (array_chunk($excludedIds, self::CHUNK) as $ids) {
            DB::table('menu_product_skus')->whereIn('menu_product_id', $ids)->delete();
            DB::table('menu_products')->whereIn('id', $ids)->delete();
        }
    }

    /** @param list<string> $allowedToppingGroupItemIds */
    private function pruneExcludedToppingGroupItems(array $allowedToppingGroupItemIds): void
    {
        $excludedIds = array_values(array_diff(
            array_column($this->fixture('topping_group_items'), 'id'),
            $allowedToppingGroupItemIds,
        ));

        foreach (array_chunk($excludedIds, self::CHUNK) as $ids) {
            DB::table('topping_group_item_skus')->whereIn('topping_group_item_id', $ids)->delete();
            DB::table('topping_group_items')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Delete the brand-scoped rows of the join tables whose surrogate ids drift
     * between snapshots, so the reinsert never collides on their composite
     * unique keys. Scoped by the stable entity ids, child tables first.
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $skuIds
     * @param  list<string>  $toppingGroupItemIds
     */
    private function clearDriftingPivots(array $productIds, array $skuIds, array $toppingGroupItemIds): void
    {
        foreach (array_chunk($skuIds, self::CHUNK) as $ids) {
            DB::table('menu_product_skus')->whereIn('product_sku_id', $ids)->delete();
        }
        foreach (array_chunk($productIds, self::CHUNK) as $ids) {
            DB::table('menu_products')->whereIn('product_id', $ids)->delete();
            DB::table('product_topping_group_item_overrides')->whereIn('product_id', $ids)->delete();
            DB::table('product_topping_groups')->whereIn('product_id', $ids)->delete();
        }
        foreach (array_chunk($toppingGroupItemIds, self::CHUNK) as $ids) {
            DB::table('topping_group_item_skus')->whereIn('topping_group_item_id', $ids)->delete();
        }
    }

    /** @param array<string, string> $branchMap */
    private function applyBranchMedia(array $branchMap): void
    {
        foreach ($this->fixture('branch_media') as $media) {
            $targetId = $branchMap[(string) ($media['source_branch_id'] ?? '')] ?? null;
            if ($targetId === null) {
                continue;
            }

            unset($media['source_branch_id']);
            DB::table('branches')->where('id', $targetId)->update($media);
        }
    }

    private function assertPublicMediaExists(): void
    {
        $missing = [];
        $invalid = [];
        foreach ($this->fixture('media_manifest') as $media) {
            $path = (string) ($media['path'] ?? '');
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                $missing[] = $path;

                continue;
            }

            $stream = Storage::disk('public')->readStream($path);
            if (! is_resource($stream)) {
                $invalid[] = $path;

                continue;
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);
            if (! hash_equals((string) $media['sha256'], hash_final($context))) {
                $invalid[] = $path;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Betoya public media is incomplete: %d paths missing (first: %s).',
                count($missing),
                $missing[0],
            ));
        }

        if ($invalid !== []) {
            throw new RuntimeException(sprintf(
                'Betoya public media checksum mismatch: %d paths invalid (first: %s).',
                count($invalid),
                $invalid[0],
            ));
        }
    }

    private function installPublicMedia(): void
    {
        foreach ($this->fixture('media_manifest') as $media) {
            $path = (string) ($media['path'] ?? '');
            if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new RuntimeException("Invalid Betoya media path [{$path}].");
            }

            $source = self::MEDIA_DIR.'/'.$path;
            if (! is_file($source)) {
                throw new RuntimeException("Betoya media bundle is missing [{$path}].");
            }

            $sourceChecksum = hash_file('sha256', $source);
            if (! is_string($sourceChecksum) || ! hash_equals((string) $media['sha256'], $sourceChecksum)) {
                throw new RuntimeException("Betoya media bundle checksum mismatch [{$path}].");
            }

            if (Storage::disk('public')->exists($path)
                && Storage::disk('public')->size($path) === (int) $media['size']
                && hash_equals((string) $media['sha256'], $this->publicMediaChecksum($path))) {
                continue;
            }

            $stream = fopen($source, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException("Cannot read Betoya media bundle [{$path}].");
            }

            try {
                if (! Storage::disk('public')->put($path, $stream)) {
                    throw new RuntimeException("Cannot install Betoya public media [{$path}].");
                }
            } finally {
                fclose($stream);
            }
        }
    }

    private function ensurePublicStorageLink(): void
    {
        $link = public_path('storage');
        $target = storage_path('app/public');

        if (is_link($link)) {
            if (realpath($link) !== realpath($target)) {
                throw new RuntimeException("Public storage link points to an unexpected target [{$link}].");
            }

            return;
        }

        if (file_exists($link)) {
            throw new RuntimeException("Cannot create public storage link because [{$link}] already exists.");
        }

        File::ensureDirectoryExists(dirname($link));
        File::link($target, $link);
    }

    private function publicMediaChecksum(string $path): string
    {
        $stream = Storage::disk('public')->readStream($path);
        if (! is_resource($stream)) {
            return '';
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $sourceBranches
     * @return array<string, string>
     */
    private function branchMap(Organization $organization, Brand $brand, array $sourceBranches): array
    {
        $targets = Branch::query()
            ->where('console_organization_id', $organization->console_organization_id)
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_active', true)
            ->get()
            ->keyBy('slug');

        $map = [];
        foreach ($sourceBranches as $sourceId => $sourceSlug) {
            $target = $targets->get((string) $sourceSlug);
            if ($target instanceof Branch) {
                $map[(string) $sourceId] = (string) $target->id;
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $branchMap
     * @param  list<string>  $allowedMenuIds
     * @param  list<string>  $allowedMenuProductIds
     * @param  list<string>  $allowedToppingGroupItemIds
     * @param  list<string>  $liveProductIds
     * @return list<array<string, mixed>>
     */
    private function transformRows(
        string $table,
        array $rows,
        array $source,
        Organization $organization,
        Brand $brand,
        array $branchMap,
        array $allowedMenuIds,
        array $allowedMenuProductIds,
        array $allowedToppingGroupItemIds,
        array $liveProductIds,
    ): array {
        $result = [];

        foreach ($rows as $row) {
            if (isset($row['branch_id']) && $row['branch_id'] !== null) {
                if (! isset($branchMap[(string) $row['branch_id']])) {
                    continue;
                }
                $row['branch_id'] = $branchMap[(string) $row['branch_id']];
            }

            if (in_array($table, ['menu_translations', 'menu_menu_sections', 'menu_products', 'menu_schedules'], true)
                && isset($row['menu_id']) && ! in_array((string) $row['menu_id'], $allowedMenuIds, true)) {
                continue;
            }

            // The source SQL contains active menu placements whose base product
            // was soft-deleted during catalog replacement. Keeping those rows
            // renders anonymous "untitled" cards and inflates menu counts. A
            // production snapshot may retain deleted products for HQ history,
            // but a live menu must never point at one.
            if ($table === 'menu_products'
                && ! in_array((string) $row['product_id'], $liveProductIds, true)) {
                continue;
            }

            if ($table === 'menu_product_skus'
                && ! in_array((string) $row['menu_product_id'], $allowedMenuProductIds, true)) {
                continue;
            }

            if ($table === 'topping_group_items'
                && ! in_array((string) $row['product_id'], $liveProductIds, true)) {
                continue;
            }

            if ($table === 'topping_group_item_skus'
                && ! in_array((string) $row['topping_group_item_id'], $allowedToppingGroupItemIds, true)) {
                continue;
            }

            if (($row['organization_id'] ?? null) === ($source['organization_id'] ?? null)) {
                $row['organization_id'] = $organization->id;
            }
            if (($row['brand_id'] ?? null) === ($source['brand_id'] ?? null)) {
                $row['brand_id'] = $brand->id;
            }

            foreach (array_keys($row) as $column) {
                if (str_ends_with($column, '_by_id')) {
                    $row[$column] = null;
                }
            }

            // #2320 — ÁNH XẠ theo mã, không xoá trắng.
            //
            // Trước đây hai cột này bị set `null` vì id trong ảnh chụp thuộc DB
            // nguồn. Cái giá của phép "an toàn" đó: ảnh chụp mang **13 product
            // gán 軽減税率 (8%)** và một chi nhánh lấy 軽減 làm mặc định — toàn bộ
            // chỗ đó biến mất ở đây, rồi `JapaneseTaxSeeder` (đã gỡ) dán STANDARD
            // 10% đè lên tất cả. Khách bị tính vượt 2% trên đúng những món mà
            // luật cho hưởng 8%, ở MỌI lượt seed.
            //
            // Id nguồn ↔ mã đọc từ `tax_types.json`; mã ↔ id đích tra trong DB
            // của brand đích (baseline đã dựng ba loại thuế trước vòng lặp này).
            foreach (['tax_type_id', 'default_tax_type_id'] as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = $this->remapTaxTypeId($row[$column], $table, $column);
                }
            }

            if ($table === 'files') {
                // Production stores media on Laravel's persistent public disk.
                // Preserve the exported object path exactly; installPublicMedia()
                // restores the matching release-bundled binary at that path.
                $row['disk'] = 'public';
            }

            if ($table === 'zones') {
                // HQ template ids belong to the source database and are not
                // part of this production snapshot.
                $row['zone_template_id'] = null;
            }

            if ($table === 'tables') {
                // Floor layout belongs in the snapshot, but live occupancy is
                // runtime state. A fresh production database must start with
                // every usable table free and detached from historical orders.
                $row['status'] = 'free';
                $row['paid_at'] = null;
                $row['call_requested_at'] = null;
                $row['current_order_id'] = null;
                $row['table_template_id'] = null;
            }

            $result[] = $row;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $uniqueBy = self::UNIQUE_KEYS[$table] ?? ['id'];
        $updateColumns = array_values(array_diff(array_keys($rows[0]), $uniqueBy));
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }

        $this->command?->info(sprintf('  %-42s %d', $table, count($rows)));
    }

    /**
     * Dựng baseline của brand (loại thuế, Reverb, ProductType combo, đóng dấu
     * thuế cho product chưa gắn). Idempotent — xem {@see BrandBaselineProvisioner}.
     */
    private function baseline(Brand $brand): void
    {
        app(BrandBaselineProvisioner::class)->ensure($brand);
    }

    /**
     * `id loại thuế trong ảnh chụp` → `id loại thuế của brand đích`.
     *
     * Cầu nối là **mã** (`STANDARD`/`REDUCED`/`EXEMPT`), không phải id: id đích
     * do `TaxTypeService` sinh ngẫu nhiên và không việc gì phải trùng id nguồn.
     * Trước #2320 hai bên buộc phải trùng id — nên mới có một seeder thứ hai
     * ghi thẳng `tax_types` bằng UUIDv5 tất định, và nó lại XOÁ hàng do
     * `TaxTypeService` tạo để chiếm chỗ. Ánh xạ theo mã cắt luôn ràng buộc đó.
     *
     * @return array<string, string>
     */
    private function buildTaxTypeMap(Brand $brand): array
    {
        $localIdByCode = TaxType::query()
            ->where('brand_id', $brand->id)
            ->pluck('id', 'code')
            ->all();

        $map = [];
        foreach ($this->fixture('tax_types') as $row) {
            $code = (string) ($row['code'] ?? '');
            $sourceId = (string) ($row['id'] ?? '');
            if ($code === '' || $sourceId === '') {
                continue;
            }

            if (! isset($localIdByCode[$code])) {
                throw new RuntimeException(
                    "Catalog snapshot references tax type [{$code}] which brand [{$brand->slug}] does not carry. "
                    .'Run `php artisan provisioning:reconcile` first.'
                );
            }

            $map[$sourceId] = (string) $localIdByCode[$code];
        }

        return $map;
    }

    /**
     * Một id không ánh xạ được là **lỗi**, không phải `null`.
     *
     * Ghi `null` ở đây chính là bản vá cũ, và nó im lặng đánh mất thuế suất của
     * 13 món. Nếu ảnh chụp mang một loại thuế mà `tax_types.json` không mô tả
     * (dump mới, brand thêm loại riêng) thì phải dừng và cập nhật fixture.
     */
    private function remapTaxTypeId(mixed $value, string $table, string $column): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $sourceId = (string) $value;
        if (! isset($this->taxTypeMap[$sourceId])) {
            throw new RuntimeException(
                "Catalog snapshot row {$table}.{$column} points at unknown tax type [{$sourceId}]; "
                .'add it to database/seeders/fixtures/catalog/tax_types.json.'
            );
        }

        return $this->taxTypeMap[$sourceId];
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $path = self::FIXTURE_DIR."/{$name}.json";
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) ?? [];
    }
}
