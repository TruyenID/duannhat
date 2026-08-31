<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfills product_translations (ja / en / vi) for the demo catalog's
 * component products — toppings, modifiers and option-driver products
 * (Fish sauce, Egg, No onion, Hot, …). These were created without any
 * translation, so the POS product-options dialog rendered the topping items
 * in English even under 日本語. Main menu products already have translations.
 *
 * Set-name products that are already Japanese ("モーニングセット") are left
 * alone — the workstation falls back to the base name for ja.
 *
 * Idempotent: matches by products.name, clears existing translations for the
 * matched products, then re-inserts. Safe to run repeatedly.
 */
class ProductComponentTranslationSeeder extends Seeder
{
    public function run(): void
    {
        // en key => [ja, vi]. en value is the map key itself.
        $dict = [
            '0% sugar' => ['ja' => '無糖', 'vi' => 'Không đường'],
            '50% sugar' => ['ja' => '甘さ50%', 'vi' => 'Ngọt 50%'],
            '100% sugar' => ['ja' => '通常の甘さ', 'vi' => 'Ngọt 100%'],
            'Aloe vera' => ['ja' => 'アロエ', 'vi' => 'Nha đam'],
            'Boba pearls' => ['ja' => 'タピオカ', 'vi' => 'Trân châu'],
            'Cheese' => ['ja' => 'チーズ', 'vi' => 'Phô mai'],
            'Coconut jelly' => ['ja' => 'ココナッツゼリー', 'vi' => 'Thạch dừa'],
            'Egg' => ['ja' => 'たまご', 'vi' => 'Trứng'],
            'Extra beef' => ['ja' => '牛肉増し', 'vi' => 'Thêm bò'],
            'Extra hot' => ['ja' => '辛さ増し', 'vi' => 'Cay hơn'],
            'Extra noodles' => ['ja' => '麺増し', 'vi' => 'Thêm mì'],
            'Extra sweet' => ['ja' => '甘さ増し', 'vi' => 'Ngọt hơn'],
            'Fish sauce' => ['ja' => 'ヌクマム', 'vi' => 'Nước mắm'],
            'Hot' => ['ja' => 'ホット', 'vi' => 'Nóng'],
            'Iced' => ['ja' => 'アイス', 'vi' => 'Đá'],
            'Less ice' => ['ja' => '氷少なめ', 'vi' => 'Ít đá'],
            'Lychee jelly' => ['ja' => 'ライチゼリー', 'vi' => 'Thạch vải'],
            'Medium' => ['ja' => 'ミディアム', 'vi' => 'Vừa'],
            'Mild' => ['ja' => 'マイルド', 'vi' => 'Nhẹ'],
            'No bean sprouts' => ['ja' => 'もやし抜き', 'vi' => 'Không giá'],
            'No cilantro' => ['ja' => 'パクチー抜き', 'vi' => 'Không ngò'],
            'No ice' => ['ja' => '氷なし', 'vi' => 'Không đá'],
            'No onion' => ['ja' => '玉ねぎ抜き', 'vi' => 'Không hành'],
            'No peanuts (allergy)' => ['ja' => 'ピーナッツ抜き（アレルギー）', 'vi' => 'Không đậu phộng (dị ứng)'],
            'Soy sauce' => ['ja' => '醤油', 'vi' => 'Nước tương'],
            'Sriracha' => ['ja' => 'シラチャー', 'vi' => 'Tương ớt Sriracha'],
            'Sweet chili' => ['ja' => 'スイートチリ', 'vi' => 'Ớt ngọt'],
            'Veggies' => ['ja' => '野菜', 'vi' => 'Rau'],
        ];

        $products = DB::table('products')
            ->whereIn('name', array_keys($dict))
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        $ids = $products->pluck('id')->all();
        if (! empty($ids)) {
            DB::table('product_translations')->whereIn('product_id', $ids)->delete();
        }

        $rows = [];
        foreach ($products as $p) {
            $t = $dict[$p->name];
            $rows[] = ['product_id' => $p->id, 'locale' => 'ja', 'name' => $t['ja'], 'description' => null];
            $rows[] = ['product_id' => $p->id, 'locale' => 'en', 'name' => $p->name, 'description' => null];
            $rows[] = ['product_id' => $p->id, 'locale' => 'vi', 'name' => $t['vi'], 'description' => null];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_translations')->insert($chunk);
        }

        $this->command?->info('Seeded '.count($rows).' product_translations for '.$products->count().' component products.');
    }
}
