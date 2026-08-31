<?php

use App\Models\Product;
use Database\Seeders\ProductComponentTranslationSeeder;
use Illuminate\Support\Facades\DB;

it('backfills ja/en/vi translations for component products, matched by name', function () {
    $fishSauce = Product::factory()->create(['name' => 'Fish sauce']);
    // The factory (Translatable) may auto-create a translation — clear it so the
    // seeder's delete-then-insert is the sole source.
    DB::table('product_translations')->where('product_id', $fishSauce->id)->delete();

    // A product NOT in the dictionary must be left untouched.
    $unknown = Product::factory()->create(['name' => 'Totally Unknown Item']);
    DB::table('product_translations')->where('product_id', $unknown->id)->delete();

    (new ProductComponentTranslationSeeder)->run();

    $byLocale = DB::table('product_translations')
        ->where('product_id', $fishSauce->id)
        ->pluck('name', 'locale');

    expect($byLocale['ja'])->toBe('ヌクマム')
        ->and($byLocale['en'])->toBe('Fish sauce')
        ->and($byLocale['vi'])->toBe('Nước mắm');

    expect(DB::table('product_translations')->where('product_id', $unknown->id)->count())->toBe(0);
});

it('is idempotent — re-running does not duplicate rows', function () {
    $egg = Product::factory()->create(['name' => 'Egg']);
    DB::table('product_translations')->where('product_id', $egg->id)->delete();

    (new ProductComponentTranslationSeeder)->run();
    (new ProductComponentTranslationSeeder)->run();

    expect(DB::table('product_translations')->where('product_id', $egg->id)->count())->toBe(3);
});
