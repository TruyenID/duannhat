<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductSku;
use App\Services\Product\ProductOptionService;
use App\Services\Product\ProductOptionValueService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Attach ProductOption + ProductOptionValue rows to a curated set of
 * customer-menu products so the customer-web ProductModal can render
 * variants. Existing product_skus (e.g. PHO-BO-R / PHO-BO-L) are linked
 * via option_value1_id so CustomerMenuService::transformOptions resolves
 * the matching menu_product_sku and computes price deltas.
 *
 * Idempotent: skips products where the option key already exists.
 */
class ProductOptionsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(
        ProductOptionService $optionService,
        ProductOptionValueService $valueService,
    ): void {
        foreach ($this->definitions() as $slug => $def) {
            $products = Product::where('slug', $slug)->get();

            if ($products->isEmpty()) {
                $this->command?->warn("ProductOptionsSeeder: no products found for slug '{$slug}' — skipping.");

                continue;
            }

            foreach ($products as $product) {
                $this->seedFor($product, $def, $optionService, $valueService);
            }
        }
    }

    /**
     * @param  array{optionKey: string, optionName: array<string,string>, values: array<int, array{skuSuffix: string, value: string, label: array<string,string>}>}  $def
     */
    private function seedFor(
        Product $product,
        array $def,
        ProductOptionService $optionService,
        ProductOptionValueService $valueService,
    ): void {
        $existing = ProductOption::where('product_id', $product->id)
            ->where('key', $def['optionKey'])
            ->first();

        if ($existing) {
            $this->command?->info("skip: {$product->slug} / {$product->id} already has option '{$def['optionKey']}'");

            return;
        }

        $option = $optionService->create([
            'product_id' => $product->id,
            'key' => $def['optionKey'],
            'position' => 1,
            'is_active' => true,
            'name' => $def['optionName']['ja'],
            'ja' => ['name' => $def['optionName']['ja']],
            'en' => ['name' => $def['optionName']['en']],
            'vi' => ['name' => $def['optionName']['vi']],
        ]);

        foreach ($def['values'] as $idx => $valueDef) {
            $value = $valueService->create([
                'option_id' => $option->id,
                'value' => $valueDef['value'],
                'position' => $idx,
                'is_active' => true,
                'label' => $valueDef['label']['ja'],
                'ja' => ['label' => $valueDef['label']['ja']],
                'en' => ['label' => $valueDef['label']['en']],
                'vi' => ['label' => $valueDef['label']['vi']],
            ]);

            // Link existing SKUs whose `sku` code matches the variant suffix
            // (e.g. sku='PHO-BO-L' → skuSuffix='L'). option_value1_id because
            // this seeder always attaches options at position=1.
            ProductSku::where('product_id', $product->id)
                ->where('sku', 'like', "%-{$valueDef['skuSuffix']}")
                ->update(['option_value1_id' => $value->id]);
        }

        $this->command?->info("seeded: {$product->slug} / {$product->id} → '{$def['optionKey']}' (".count($def['values']).' values)');
    }

    /**
     * @return array<string, array{optionKey: string, optionName: array<string,string>, values: array<int, array{skuSuffix: string, value: string, label: array<string,string>}>}>
     */
    private function definitions(): array
    {
        $sizeValues = [
            ['skuSuffix' => 'R', 'value' => 'regular', 'label' => ['ja' => '並', 'en' => 'Regular', 'vi' => 'Thường']],
            ['skuSuffix' => 'L', 'value' => 'large',   'label' => ['ja' => '大盛', 'en' => 'Large', 'vi' => 'Lớn']],
        ];

        $sizeOption = ['ja' => 'サイズ', 'en' => 'Size', 'vi' => 'Size'];

        $tempValues = [
            ['skuSuffix' => 'H', 'value' => 'hot',  'label' => ['ja' => 'ホット', 'en' => 'Hot',  'vi' => 'Nóng']],
            ['skuSuffix' => 'I', 'value' => 'iced', 'label' => ['ja' => 'アイス', 'en' => 'Iced', 'vi' => 'Đá']],
        ];

        $tempOption = ['ja' => '温度', 'en' => 'Temperature', 'vi' => 'Nhiệt độ'];

        return [
            'pho-bo' => ['optionKey' => 'size',        'optionName' => $sizeOption, 'values' => $sizeValues],
            'pho-ga' => ['optionKey' => 'size',        'optionName' => $sizeOption, 'values' => $sizeValues],
            'bun-bo-hue' => ['optionKey' => 'size',        'optionName' => $sizeOption, 'values' => $sizeValues],
            'ca-phe-sua' => ['optionKey' => 'temperature', 'optionName' => $tempOption, 'values' => $tempValues],
            'ca-phe-trung' => ['optionKey' => 'temperature', 'optionName' => $tempOption, 'values' => $tempValues],

            'banh-mi' => [
                'optionKey' => 'style',
                'optionName' => ['ja' => 'スタイル', 'en' => 'Style', 'vi' => 'Loại'],
                'values' => [
                    ['skuSuffix' => 'CL', 'value' => 'classic', 'label' => ['ja' => 'クラシック', 'en' => 'Classic', 'vi' => 'Cổ điển']],
                    ['skuSuffix' => 'SP', 'value' => 'special', 'label' => ['ja' => 'スペシャル', 'en' => 'Special', 'vi' => 'Đặc biệt']],
                    ['skuSuffix' => 'VG', 'value' => 'veggie',  'label' => ['ja' => 'ベジ',     'en' => 'Veggie',  'vi' => 'Chay']],
                ],
            ],

            'com-tam' => [
                'optionKey' => 'protein',
                'optionName' => ['ja' => '主菜', 'en' => 'Protein', 'vi' => 'Thịt'],
                'values' => [
                    ['skuSuffix' => 'GA', 'value' => 'chicken', 'label' => ['ja' => '鶏肉',  'en' => 'Chicken', 'vi' => 'Gà']],
                    ['skuSuffix' => 'SN', 'value' => 'pork',    'label' => ['ja' => '豚スネ', 'en' => 'Pork',    'vi' => 'Sườn']],
                ],
            ],

            'sinh-to' => [
                'optionKey' => 'flavor',
                'optionName' => ['ja' => 'フレーバー', 'en' => 'Flavor', 'vi' => 'Hương vị'],
                'values' => [
                    ['skuSuffix' => 'AV', 'value' => 'avocado',      'label' => ['ja' => 'アボカド',          'en' => 'Avocado',      'vi' => 'Bơ']],
                    ['skuSuffix' => 'DL', 'value' => 'dragon_fruit', 'label' => ['ja' => 'ドラゴンフルーツ', 'en' => 'Dragon Fruit', 'vi' => 'Thanh long']],
                    ['skuSuffix' => 'MG', 'value' => 'mango',        'label' => ['ja' => 'マンゴー',          'en' => 'Mango',        'vi' => 'Xoài']],
                ],
            ],
        ];
    }
}
