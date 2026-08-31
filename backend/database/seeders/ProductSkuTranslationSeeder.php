<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds product_sku_translations (ja / en / vi) for the demo catalog's SKU
 * variant names so the POS product-options dialog shows the variation in the
 * operator's language. Numeric/volume codes ("2pc", "330ml") and names that are
 * already Japanese ("モーニングセット") are intentionally left unmapped — the
 * workstation feed falls back to the base product_skus.name for those.
 *
 * Idempotent: matches by product_skus.name, deletes any existing translations
 * for the matched SKUs first, then re-inserts. Safe to run repeatedly.
 */
class ProductSkuTranslationSeeder extends Seeder
{
    public function run(): void
    {
        // en key => [ja, vi]. en value is the map key itself.
        $dict = [
            '0% sugar' => ['ja' => '無糖', 'vi' => 'Không đường'],
            '50% sugar' => ['ja' => '甘さ50%', 'vi' => 'Ngọt 50%'],
            '100% sugar' => ['ja' => '通常の甘さ', 'vi' => 'Ngọt 100%'],
            'Aloe vera' => ['ja' => 'アロエ', 'vi' => 'Nha đam'],
            'Avocado' => ['ja' => 'アボカド', 'vi' => 'Bơ'],
            'Boba pearls' => ['ja' => 'タピオカ', 'vi' => 'Trân châu'],
            'Cheese' => ['ja' => 'チーズ', 'vi' => 'Phô mai'],
            'Chicken' => ['ja' => 'チキン', 'vi' => 'Gà'],
            'Chocolate Croissant' => ['ja' => 'チョコクロワッサン', 'vi' => 'Bánh sừng bò socola'],
            'Classic' => ['ja' => 'クラシック', 'vi' => 'Cổ điển'],
            'Coconut jelly' => ['ja' => 'ココナッツゼリー', 'vi' => 'Thạch dừa'],
            'Dragon Fruit' => ['ja' => 'ドラゴンフルーツ', 'vi' => 'Thanh long'],
            'Egg' => ['ja' => 'たまご', 'vi' => 'Trứng'],
            'Extra beef' => ['ja' => '牛肉増し', 'vi' => 'Thêm bò'],
            'Extra hot' => ['ja' => '辛さ増し', 'vi' => 'Cay hơn'],
            'Extra noodles' => ['ja' => '麺増し', 'vi' => 'Thêm mì'],
            'Extra sweet' => ['ja' => '甘さ増し', 'vi' => 'Ngọt hơn'],
            'Fish sauce' => ['ja' => 'ヌクマム', 'vi' => 'Nước mắm'],
            'Hot' => ['ja' => 'ホット', 'vi' => 'Nóng'],
            'Iced' => ['ja' => 'アイス', 'vi' => 'Đá'],
            'Iced Coffee (L)' => ['ja' => 'アイスコーヒー(L)', 'vi' => 'Cà phê đá (L)'],
            'Iced Coffee (M)' => ['ja' => 'アイスコーヒー(M)', 'vi' => 'Cà phê đá (M)'],
            'Large' => ['ja' => 'ラージ', 'vi' => 'Lớn'],
            'Less ice' => ['ja' => '氷少なめ', 'vi' => 'Ít đá'],
            'Lychee jelly' => ['ja' => 'ライチゼリー', 'vi' => 'Thạch vải'],
            'Mango' => ['ja' => 'マンゴー', 'vi' => 'Xoài'],
            'Matcha Latte (L)' => ['ja' => '抹茶ラテ(L)', 'vi' => 'Matcha Latte (L)'],
            'Matcha Latte (M)' => ['ja' => '抹茶ラテ(M)', 'vi' => 'Matcha Latte (M)'],
            'Medium' => ['ja' => 'ミディアム', 'vi' => 'Vừa'],
            'Mild' => ['ja' => 'マイルド', 'vi' => 'Nhẹ'],
            'No bean sprouts' => ['ja' => 'もやし抜き', 'vi' => 'Không giá'],
            'No cilantro' => ['ja' => 'パクチー抜き', 'vi' => 'Không ngò'],
            'No ice' => ['ja' => '氷なし', 'vi' => 'Không đá'],
            'No onion' => ['ja' => '玉ねぎ抜き', 'vi' => 'Không hành'],
            'No peanuts (allergy)' => ['ja' => 'ピーナッツ抜き（アレルギー）', 'vi' => 'Không đậu phộng (dị ứng)'],
            'Plain Croissant' => ['ja' => 'プレーンクロワッサン', 'vi' => 'Bánh sừng bò'],
            'Pork' => ['ja' => 'ポーク', 'vi' => 'Heo'],
            'Regular' => ['ja' => 'レギュラー', 'vi' => 'Thường'],
            'Small' => ['ja' => 'スモール', 'vi' => 'Nhỏ'],
            'Soy sauce' => ['ja' => '醤油', 'vi' => 'Nước tương'],
            'Special' => ['ja' => 'スペシャル', 'vi' => 'Đặc biệt'],
            'Sriracha' => ['ja' => 'シラチャー', 'vi' => 'Tương ớt Sriracha'],
            'Sweet chili' => ['ja' => 'スイートチリ', 'vi' => 'Ớt ngọt'],
            'Veggie' => ['ja' => 'ベジ', 'vi' => 'Chay'],
            'Veggies' => ['ja' => '野菜', 'vi' => 'Rau'],
        ];

        $skus = DB::table('product_skus')
            ->whereIn('name', array_keys($dict))
            ->get(['id', 'name']);

        $skuIds = $skus->pluck('id')->all();
        if (! empty($skuIds)) {
            DB::table('product_sku_translations')->whereIn('product_sku_id', $skuIds)->delete();
        }

        $rows = [];
        foreach ($skus as $sku) {
            $t = $dict[$sku->name];
            $rows[] = ['product_sku_id' => $sku->id, 'locale' => 'ja', 'name' => $t['ja']];
            $rows[] = ['product_sku_id' => $sku->id, 'locale' => 'en', 'name' => $sku->name];
            $rows[] = ['product_sku_id' => $sku->id, 'locale' => 'vi', 'name' => $t['vi']];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_sku_translations')->insert($chunk);
        }

        $this->command?->info('Seeded '.count($rows).' product_sku_translations for '.$skus->count().' SKUs.');
    }
}
