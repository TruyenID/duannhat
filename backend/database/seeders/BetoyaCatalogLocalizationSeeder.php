<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Completes the customer-facing ja/en/vi catalog copy imported by
 * CatalogSnapshotSeeder. The source snapshot contains valid translation rows
 * for most product data, but legacy menu labels and a small set of product /
 * modifier translations still contain Japanese in their en/vi values.
 */
class BetoyaCatalogLocalizationSeeder extends Seeder
{
    /** @var array<string, array{en: string, vi: string}> */
    private const SECTION_NAMES = [
        '30円容器必要' => ['en' => '30-yen Container Required', 'vi' => 'Cần hộp 30 yên'],
        'PE 店内' => ['en' => 'PHO EXPRESS Dine-in', 'vi' => 'Ăn tại chỗ PHO EXPRESS'],
        'PE 持ち帰り' => ['en' => 'PHO EXPRESS Takeaway', 'vi' => 'Mang về PHO EXPRESS'],
        'おすすめ' => ['en' => 'Recommended', 'vi' => 'Đề xuất'],
        'お持ち帰り' => ['en' => 'Takeaway', 'vi' => 'Mang về'],
        'お持ち帰り-朝' => ['en' => 'Morning Takeaway', 'vi' => 'Mang về buổi sáng'],
        'お持ち帰りソースつき' => ['en' => 'Takeaway with Sauce', 'vi' => 'Mang về kèm sốt'],
        'お持ち帰りドーム' => ['en' => 'Takeaway Bowls', 'vi' => 'Tô mang về'],
        'その他' => ['en' => 'Other', 'vi' => 'Khác'],
        'アルコル' => ['en' => 'Alcohol', 'vi' => 'Đồ uống có cồn'],
        'アルコール' => ['en' => 'Alcohol', 'vi' => 'Đồ uống có cồn'],
        'オプション用' => ['en' => 'Options', 'vi' => 'Tùy chọn'],
        'オリジナル・グッズ' => ['en' => 'Original Goods', 'vi' => 'Sản phẩm thương hiệu'],
        'キッチンカー' => ['en' => 'Food Truck', 'vi' => 'Xe bán đồ ăn'],
        'コーヒー' => ['en' => 'Coffee', 'vi' => 'Cà phê'],
        'サラダ・野菜' => ['en' => 'Salads & Vegetables', 'vi' => 'Salad & rau'],
        'セット' => ['en' => 'Sets', 'vi' => 'Combo'],
        'デザート・飲み物' => ['en' => 'Desserts & Drinks', 'vi' => 'Tráng miệng & đồ uống'],
        'デフォルト' => ['en' => 'Default', 'vi' => 'Mặc định'],
        'バインミー' => ['en' => 'Banh Mi', 'vi' => 'Bánh mì'],
        'フォー' => ['en' => 'Pho', 'vi' => 'Phở'],
        'ブン' => ['en' => 'Bun Noodles', 'vi' => 'Bún'],
        'ホームページ おすすめ' => ['en' => 'Website Recommendations', 'vi' => 'Đề xuất trên website'],
        'ランチセット' => ['en' => 'Lunch Sets', 'vi' => 'Combo bữa trưa'],
        '事前予約' => ['en' => 'Pre-order', 'vi' => 'Đặt trước'],
        '小皿料理' => ['en' => 'Small Plates', 'vi' => 'Món ăn nhỏ'],
        '小皿料理・春巻き' => ['en' => 'Small Plates & Spring Rolls', 'vi' => 'Món nhỏ & nem cuốn'],
        '店内' => ['en' => 'Dine-in', 'vi' => 'Ăn tại chỗ'],
        '店内-ディナー' => ['en' => 'Dine-in Dinner', 'vi' => 'Bữa tối tại quán'],
        '店内-ランチ' => ['en' => 'Dine-in Lunch', 'vi' => 'Bữa trưa tại quán'],
        '春巻き' => ['en' => 'Spring Rolls', 'vi' => 'Nem cuốn'],
        '期間限定' => ['en' => 'Limited Time', 'vi' => 'Có thời hạn'],
        '無料サービス' => ['en' => 'Complimentary Items', 'vi' => 'Phục vụ miễn phí'],
        '追加' => ['en' => 'Extras', 'vi' => 'Gọi thêm'],
        'メイン' => ['en' => 'Mains', 'vi' => 'Món chính'],
        'ランチセット' => ['en' => 'Lunch Sets', 'vi' => 'Combo bữa trưa'],
        'おすすめ' => ['en' => 'Recommended', 'vi' => 'Đề xuất'],
        'サイド' => ['en' => 'Sides', 'vi' => 'Món ăn kèm'],
        'ドリンク' => ['en' => 'Drinks', 'vi' => 'Đồ uống'],
    ];

    /** @var array<string, array{ja: string, en: string, vi: string}> */
    private const BRANCH_NAMES = [
        'head-office' => ['ja' => '本社', 'en' => 'Head Office', 'vi' => 'Trụ sở chính'],
        'hikarie-norengai' => ['ja' => '渋谷店', 'en' => 'Shibuya Store', 'vi' => 'Cửa hàng Shibuya'],
        'hongo' => ['ja' => '本郷店', 'en' => 'Hongo Store', 'vi' => 'Cửa hàng Hongo'],
        'jimbocho' => ['ja' => '神保町店', 'en' => 'Jimbocho Store', 'vi' => 'Cửa hàng Jimbocho'],
        'event-store' => ['ja' => 'キッチンカー1号', 'en' => 'Food Truck No. 1', 'vi' => 'Xe bán đồ ăn số 1'],
        'monzen-nakacho' => ['ja' => '門前仲町店', 'en' => 'Monzen-nakacho Store', 'vi' => 'Cửa hàng Monzen-nakacho'],
        'ningyocho' => ['ja' => '人形町店', 'en' => 'Ningyocho Store', 'vi' => 'Cửa hàng Ningyocho'],
        'aeon-mall-tsudanuma' => ['ja' => 'PHO EXPRESS イオンモール津田沼店', 'en' => 'PHO EXPRESS AEON Mall Tsudanuma', 'vi' => 'PHO EXPRESS AEON Mall Tsudanuma'],
        'tameike-sanno' => ['ja' => '溜池山王店', 'en' => 'Tameike-sanno Store', 'vi' => 'Cửa hàng Tameike-sanno'],
        'laqua-dd' => ['ja' => '東京ドーム', 'en' => 'Tokyo Dome Store', 'vi' => 'Cửa hàng Tokyo Dome'],
        'tsukiji' => ['ja' => '築地店', 'en' => 'Tsukiji Store', 'vi' => 'Cửa hàng Tsukiji'],
    ];

    /** @var array<string, array{en: string, vi: string}> */
    private const MENU_NAMES = [
        'ベト屋 マスターメニュー' => ['en' => 'Betoya Master Menu', 'vi' => 'Menu tổng Betoya'],
        'ベト屋 — 渋谷店' => ['en' => 'Betoya — Shibuya Store', 'vi' => 'Betoya — Cửa hàng Shibuya'],
        'Menu TEST' => ['en' => 'Test Menu', 'vi' => 'Menu thử nghiệm'],
        'PHO EXPRESS イオンモール津田沼店 メニュー' => ['en' => 'PHO EXPRESS AEON Mall Tsudanuma Menu', 'vi' => 'Menu PHO EXPRESS AEON Mall Tsudanuma'],
        '人形町店 メニュー' => ['en' => 'Ningyocho Store Menu', 'vi' => 'Menu cửa hàng Ningyocho'],
        '門前仲町店 メニュー' => ['en' => 'Monzen-nakacho Store Menu', 'vi' => 'Menu cửa hàng Monzen-nakacho'],
        '東京ドーム メニュー' => ['en' => 'Tokyo Dome Store Menu', 'vi' => 'Menu cửa hàng Tokyo Dome'],
        '築地店 メニュー' => ['en' => 'Tsukiji Store Menu', 'vi' => 'Menu cửa hàng Tsukiji'],
        '本郷店 メニュー' => ['en' => 'Hongo Store Menu', 'vi' => 'Menu cửa hàng Hongo'],
        '溜池山王店 メニュー' => ['en' => 'Tameike-sanno Store Menu', 'vi' => 'Menu cửa hàng Tameike-sanno'],
        'キッチンカー1号 メニュー' => ['en' => 'Food Truck No. 1 Menu', 'vi' => 'Menu xe bán đồ ăn số 1'],
        '神保町店 メニュー' => ['en' => 'Jimbocho Store Menu', 'vi' => 'Menu cửa hàng Jimbocho'],
        'ランチ' => ['en' => 'Lunch', 'vi' => 'Bữa trưa'],
        'ディナー & 土日祝' => ['en' => 'Dinner & Weekends/Holidays', 'vi' => 'Bữa tối & cuối tuần/ngày lễ'],
        'お持ち帰り' => ['en' => 'Takeaway', 'vi' => 'Mang về'],
    ];

    /** @var array<string, array{en: string, vi: string}> */
    private const PRODUCT_NAMES = [
        'Lサイズ' => ['en' => 'Large Size', 'vi' => 'Cỡ lớn'],
        'お子様セット' => ['en' => 'Kids Set', 'vi' => 'Combo trẻ em'],
        'ご飯' => ['en' => 'Rice', 'vi' => 'Cơm'],
        'グアバージュース' => ['en' => 'Guava Juice', 'vi' => 'Nước ép ổi'],
        'ココナツジュース' => ['en' => 'Coconut Juice', 'vi' => 'Nước dừa'],
        'ココナツチェー' => ['en' => 'Coconut Che', 'vi' => 'Chè dừa'],
        'コムガーホイアン' => ['en' => 'Hoi An Chicken Rice', 'vi' => 'Cơm gà Hội An'],
        'コムタム' => ['en' => 'Com Tam', 'vi' => 'Cơm tấm'],
        'コーラ' => ['en' => 'Cola', 'vi' => 'Coca-Cola'],
        'ドリアンチェー' => ['en' => 'Durian Che', 'vi' => 'Chè sầu riêng'],
        'バインクオン（具あり）' => ['en' => 'Filled Banh Cuon', 'vi' => 'Bánh cuốn có nhân'],
        'バインクオン（具なし）' => ['en' => 'Plain Banh Cuon', 'vi' => 'Bánh cuốn không nhân'],
        'パクチージン' => ['en' => 'Coriander Gin', 'vi' => 'Gin rau mùi'],
        'パクチー追加' => ['en' => 'Extra Coriander', 'vi' => 'Thêm rau mùi'],
        'パションジュース' => ['en' => 'Passion Fruit Juice', 'vi' => 'Nước chanh dây'],
        'ブン（米細麺）1mm' => ['en' => 'Bun Rice Noodles 1 mm', 'vi' => 'Bún sợi 1 mm'],
        'ブン（米細麺）2mm' => ['en' => 'Bun Rice Noodles 2 mm', 'vi' => 'Bún sợi 2 mm'],
        'ベトナム版大福 バインコム1個' => ['en' => 'Banh Com (1 piece)', 'vi' => 'Bánh cốm (1 chiếc)'],
        'ベトナム版大福 バインコム1箱' => ['en' => 'Banh Com (1 box)', 'vi' => 'Bánh cốm (1 hộp)'],
        'マンゴージュース' => ['en' => 'Mango Juice', 'vi' => 'Nước xoài'],
        'ライス【大】盛り' => ['en' => 'Large Rice', 'vi' => 'Cơm cỡ lớn'],
        'レンゲ' => ['en' => 'Soup Spoon', 'vi' => 'Thìa súp'],
        '仙草ゼーリ' => ['en' => 'Grass Jelly', 'vi' => 'Sương sáo'],
        '卵入り豚ひき肉蒸し' => ['en' => 'Steamed Minced Pork with Egg', 'vi' => 'Mắm chưng thịt trứng'],
        '卵焼き' => ['en' => 'Omelet', 'vi' => 'Trứng chiên'],
        '取り皿' => ['en' => 'Extra Plate', 'vi' => 'Đĩa dùng thêm'],
        '揚げパナナ' => ['en' => 'Fried Banana', 'vi' => 'Chuối chiên'],
        '揚げパン' => ['en' => 'Fried Dough Stick', 'vi' => 'Quẩy'],
        '春巻きセット' => ['en' => 'Spring Roll Set', 'vi' => 'Combo nem cuốn'],
        '温かい水' => ['en' => 'Warm Water', 'vi' => 'Nước ấm'],
        '生ビール' => ['en' => 'Draft Beer', 'vi' => 'Bia tươi'],
        '茉莉花' => ['en' => 'Jasmine', 'vi' => 'Hoa nhài'],
        '鶏肉のライム葉焼きブン' => ['en' => 'Grilled Lime Leaf Chicken Bun', 'vi' => 'Bún gà nướng lá chanh'],
        '鶏肉ライム葉焼きバインミー' => ['en' => 'Grilled Lime Leaf Chicken Banh Mi', 'vi' => 'Bánh mì gà nướng lá chanh'],
        '麺【大】盛り' => ['en' => 'Large Noodles', 'vi' => 'Mì cỡ lớn'],
    ];

    /** @var array<string, array{en: string, vi: string}> */
    private const TOPPING_GROUP_NAMES = [
        'BBH辛さ' => ['en' => 'Bun Bo Hue Spice Level', 'vi' => 'Độ cay bún bò Huế'],
        'Ginオプション' => ['en' => 'Gin Options', 'vi' => 'Tùy chọn gin'],
        'Lサイズ' => ['en' => 'Large Size', 'vi' => 'Cỡ lớn'],
        'Whiskeyオプション' => ['en' => 'Whiskey Options', 'vi' => 'Tùy chọn whisky'],
        'ご飯' => ['en' => 'Rice', 'vi' => 'Cơm'],
        'ちょい飲みビール' => ['en' => 'Quick Drink Beer', 'vi' => 'Bia uống nhanh'],
        'ちょい飲みフォーミニ' => ['en' => 'Mini Pho Add-on', 'vi' => 'Phở mini gọi thêm'],
        'コーヒー Hot/Ice' => ['en' => 'Coffee Hot/Iced', 'vi' => 'Cà phê nóng/đá'],
        'コーヒー種類' => ['en' => 'Coffee Type', 'vi' => 'Loại cà phê'],
        'サラダ種類' => ['en' => 'Salad Type', 'vi' => 'Loại salad'],
        'ジュース' => ['en' => 'Juice', 'vi' => 'Nước ép'],
        'ソース' => ['en' => 'Sauce', 'vi' => 'Nước sốt'],
        'チェー種類' => ['en' => 'Che Type', 'vi' => 'Loại chè'],
        'ネップモイ・ネップカム' => ['en' => 'Nep Moi / Nep Cam', 'vi' => 'Nếp mới / nếp cẩm'],
        'バインミーのトッピング' => ['en' => 'Banh Mi Toppings', 'vi' => 'Topping bánh mì'],
        'バインミー種類' => ['en' => 'Banh Mi Type', 'vi' => 'Loại bánh mì'],
        'パクチー' => ['en' => 'Coriander', 'vi' => 'Rau mùi'],
        'フォーのトッピング' => ['en' => 'Pho Toppings', 'vi' => 'Topping phở'],
        'フォー持ち帰りトッピング' => ['en' => 'Takeaway Pho Toppings', 'vi' => 'Topping phở mang về'],
        'フォー麺の量' => ['en' => 'Pho Noodle Portion', 'vi' => 'Lượng bánh phở'],
        'ブンのトッピング' => ['en' => 'Bun Toppings', 'vi' => 'Topping bún'],
        'ランチセットのブン' => ['en' => 'Lunch Set Bun', 'vi' => 'Bún trong combo trưa'],
        'ランチセットの麺' => ['en' => 'Lunch Set Noodles', 'vi' => 'Mì trong combo trưa'],
        'ランチ一緒にお得' => ['en' => 'Lunch Add-on Deal', 'vi' => 'Ưu đãi gọi kèm bữa trưa'],
        'ルアモイ' => ['en' => 'Lua Moi', 'vi' => 'Lúa Mới'],
        '冷やし麺類' => ['en' => 'Chilled Noodles', 'vi' => 'Mì lạnh'],
        '唐揚げサイズ' => ['en' => 'Fried Chicken Size', 'vi' => 'Cỡ gà chiên'],
        '子どもフォーセット' => ['en' => 'Kids Pho Set', 'vi' => 'Combo phở trẻ em'],
        '春巻きセット' => ['en' => 'Spring Roll Set', 'vi' => 'Combo nem cuốn'],
        '昼間コーヒー' => ['en' => 'Daytime Coffee', 'vi' => 'Cà phê ban ngày'],
        '茉莉花' => ['en' => 'Jasmine', 'vi' => 'Hoa nhài'],
        '辛さ' => ['en' => 'Spice Level', 'vi' => 'Độ cay'],
        '麺の量' => ['en' => 'Noodle Portion', 'vi' => 'Lượng mì'],
        '麺種類フォーミニ' => ['en' => 'Mini Pho Noodle Type', 'vi' => 'Loại bánh phở mini'],
    ];

    /** @var array<string, array{en: string, vi: string}> */
    private const PRODUCT_DESCRIPTIONS = [
        '019f6ed5-4978-71ac-baf1-8444fa1192c4' => ['en' => '<strong>333 Beer</strong> is a classic Vietnamese lager with a crisp, balanced taste.', 'vi' => '<strong>Bia 333</strong> là dòng lager tiêu biểu của Việt Nam, có vị sảng khoái và cân bằng.'],
        '019f6ed5-499b-72fc-8b16-083ff20848b8' => ['en' => '<strong>Saigon Beer</strong> is a smooth traditional lager from Ho Chi Minh City with gentle bitterness, ideal with Vietnamese food.', 'vi' => '<strong>Bia Sài Gòn</strong> là lager truyền thống từ Thành phố Hồ Chí Minh, vị êm và đắng vừa, rất hợp món Việt.'],
        '019f6ed5-4e72-7123-afdb-57070fee7597' => ['en' => 'Omelet banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì trứng ốp la kèm cà phê Việt Nam.'],
        '019f6ed5-4e80-7141-8874-f77dd5748e77' => ['en' => 'Shrimp and avocado banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì tôm bơ kèm cà phê Việt Nam.'],
        '019f6ed5-4eeb-73d1-abf2-59cf88c5093c' => ['en' => 'Pork BBQ banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì thịt heo BBQ kèm cà phê Việt Nam.'],
        '019f6ed5-4f31-730d-8a25-40f82f3d7f40' => ['en' => 'Grilled lime leaf chicken banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì gà nướng lá chanh kèm cà phê Việt Nam.'],
        '019f6ed5-4f6f-71a2-80b1-09dbd4b2cbf1' => ['en' => 'Pho served with two cuts of beef.', 'vi' => 'Phở dùng kèm hai loại thịt bò.'],
        '019f6ed5-5637-704c-885a-eeec767850a9' => ['en' => 'Enjoy the fried dough stick dipped into pho broth, a classic Vietnamese pairing.', 'vi' => 'Quẩy chấm nước phở là cách thưởng thức quen thuộc của người Việt.'],
        '019f6ed5-59b1-7103-877b-da9a0dceee9a' => ['en' => 'Omelet banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì trứng ốp la kèm cà phê Việt Nam.'],
        '019f6ed5-59b7-7163-b493-823cc2864676' => ['en' => 'Shrimp and avocado banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì tôm bơ kèm cà phê Việt Nam.'],
        '019f6ed5-59be-7138-b859-80c6023de50f' => ['en' => 'Pork BBQ banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì thịt heo BBQ kèm cà phê Việt Nam.'],
        '019f6ed5-59c6-73d7-8088-bcf95d544041' => ['en' => 'Grilled lime leaf chicken banh mi served with Vietnamese coffee.', 'vi' => 'Bánh mì gà nướng lá chanh kèm cà phê Việt Nam.'],
        '019f6ed5-5a3b-713f-9102-9553c926f9f3' => ['en' => 'Chilled rice noodles with fragrant grilled chicken and crisp fresh vegetables, perfect for hot summer days.', 'vi' => 'Bánh phở lạnh ăn cùng gà nướng thơm và rau tươi giòn, thích hợp cho ngày hè nóng bức.'],
        '019f6ed5-5a4b-700f-9500-d760400dae99' => ['en' => 'Chilled rice noodles with fresh shrimp and crisp vegetables, perfect for hot summer days.', 'vi' => 'Bánh phở lạnh ăn cùng tôm tươi và rau giòn, thích hợp cho ngày hè nóng bức.'],
        '019f6ed5-5b98-72e3-8698-dabc26cca54b' => ['en' => 'Tender rare beef gently cooked in hot aromatic broth with fresh pho noodles.', 'vi' => 'Thịt bò tái mềm chín vừa trong nước dùng nóng thơm, ăn cùng bánh phở tươi.'],
        '019f6ed5-5c39-7388-85a9-d6e72bc1c77d' => ['en' => 'Kirin Ichiban draft beer with a refined malt flavor that pairs well with the meal.', 'vi' => 'Bia tươi Kirin Ichiban có vị malt thanh nhã, rất hợp dùng cùng món ăn.'],
        '019f6ed5-5cca-72b7-a64c-b02468d01b7e' => ['en' => 'Chargrilled pork ribs in a sweet-savory marinade, served with egg, pickles, and nuoc cham as a complete rice plate.', 'vi' => 'Sườn heo ướp mặn ngọt nướng than, dùng cùng trứng, đồ chua và nước chấm trong một đĩa cơm đầy đủ.'],
        '019f6ed5-5d2c-72b6-be5a-19120a786b03' => ['en' => 'Box of 9 pieces.', 'vi' => 'Hộp gồm 9 chiếc.'],
        '019f6ed5-5d4f-704b-8294-34e5439d8487' => ['en' => 'Light chicken broth with smooth bun rice noodles and tender chicken.', 'vi' => 'Nước dùng gà thanh nhẹ với bún mềm và thịt gà mềm thơm.'],
        '019f6ed5-5d67-71ac-9dab-f75f53546acd' => ['en' => 'A Hanoi specialty: delicate steamed rice sheets filled with pork and wood ear mushrooms, served with sweet-and-sour fish sauce.', 'vi' => 'Đặc sản Hà Nội gồm bánh gạo hấp mỏng cuốn nhân thịt heo, mộc nhĩ, dùng với nước mắm chua ngọt.'],
        '019f6ed5-5d70-7343-b70d-23963c3229b8' => ['en' => 'Delicate plain steamed rice sheets in the Hanoi style, served with sweet-and-sour fish sauce.', 'vi' => 'Bánh cuốn chay kiểu Hà Nội, dùng cùng nước mắm chua ngọt.'],
    ];

    public function run(): void
    {
        $brand = Brand::query()->where('slug', 'betoya')->first();
        if (! $brand instanceof Brand) {
            $this->command?->warn('BetoyaCatalogLocalizationSeeder: brand [betoya] not found; skipped.');

            return;
        }

        // The catalog tree is consumed as one localized unit. If any nested
        // translation fails, roll the entire repair back so customers never
        // see a half-English/half-Japanese intermediate state.
        DB::transaction(function () use ($brand): void {
            $this->seedBranchNames((string) $brand->console_brand_id);
            $this->seedNamedTranslations('menu_sections', 'menu_section_translations', 'menu_section_id', (string) $brand->id, self::SECTION_NAMES);
            $this->seedNamedTranslations('menus', 'menu_translations', 'menu_id', (string) $brand->id, self::MENU_NAMES);
            $this->repairNamedTranslations('products', 'product_translations', 'product_id', (string) $brand->id, self::PRODUCT_NAMES);
            $this->repairNamedTranslations('topping_groups', 'topping_group_translations', 'topping_group_id', (string) $brand->id, self::TOPPING_GROUP_NAMES);
            $this->repairProductDescriptions((string) $brand->id);
            $this->seedProductSkuTranslations((string) $brand->id);
        });

        $this->command?->info('BetoyaCatalogLocalizationSeeder: ja/en/vi customer catalog translations completed.');
    }

    private function seedBranchNames(string $consoleBrandId): void
    {
        $branches = DB::table('branches')
            ->where('console_brand_id', $consoleBrandId)
            ->whereIn('slug', array_keys(self::BRANCH_NAMES))
            ->get(['id', 'slug']);

        foreach ($branches as $branch) {
            foreach (self::BRANCH_NAMES[$branch->slug] as $locale => $name) {
                DB::table('branch_translations')->updateOrInsert(
                    ['branch_id' => $branch->id, 'locale' => $locale],
                    ['name' => $name],
                );
            }
        }
    }

    /**
     * @param  array<string, array{en: string, vi: string}>  $translations
     */
    private function seedNamedTranslations(
        string $sourceTable,
        string $translationTable,
        string $foreignKey,
        string $brandId,
        array $translations,
    ): void {
        $records = DB::table($sourceTable)
            ->where('brand_id', $brandId)
            ->whereIn('name', array_keys($translations))
            ->get(['id', 'name']);

        foreach ($records as $record) {
            $names = ['ja' => $record->name, ...$translations[$record->name]];
            foreach ($names as $locale => $name) {
                DB::table($translationTable)->updateOrInsert(
                    [$foreignKey => $record->id, 'locale' => $locale],
                    ['name' => $name],
                );
            }
        }
    }

    /**
     * @param  array<string, array{en: string, vi: string}>  $translations
     */
    private function repairNamedTranslations(
        string $sourceTable,
        string $translationTable,
        string $foreignKey,
        string $brandId,
        array $translations,
    ): void {
        $ids = DB::table($sourceTable)->where('brand_id', $brandId)->pluck('id');

        foreach ($translations as $legacyName => $localizedNames) {
            foreach ($localizedNames as $locale => $name) {
                DB::table($translationTable)
                    ->whereIn($foreignKey, $ids)
                    ->where('locale', $locale)
                    ->where('name', $legacyName)
                    ->update(['name' => $name]);
            }
        }
    }

    private function repairProductDescriptions(string $brandId): void
    {
        $productIds = DB::table('products')->where('brand_id', $brandId)->pluck('id')->all();

        foreach (self::PRODUCT_DESCRIPTIONS as $productId => $descriptions) {
            if (! in_array($productId, $productIds, true)) {
                continue;
            }

            foreach ($descriptions as $locale => $description) {
                DB::table('product_translations')
                    ->where('product_id', $productId)
                    ->where('locale', $locale)
                    ->update(['description' => $description]);
            }
        }
    }

    private function seedProductSkuTranslations(string $brandId): void
    {
        $skus = DB::table('product_skus')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.brand_id', $brandId)
            ->select([
                'product_skus.id',
                'product_skus.name',
                'product_skus.option_value1_id',
                'product_skus.option_value2_id',
                'product_skus.option_value3_id',
            ])
            ->get();

        $valueIds = $skus
            ->flatMap(fn (object $sku): array => array_values(array_filter([
                $sku->option_value1_id,
                $sku->option_value2_id,
                $sku->option_value3_id,
            ])))
            ->unique()
            ->values();

        $valueNames = DB::table('product_option_value_translations')
            ->whereIn('product_option_value_id', $valueIds)
            ->get(['product_option_value_id', 'locale', 'label'])
            ->keyBy(fn (object $row): string => "{$row->product_option_value_id}|{$row->locale}");

        $legacySkuNames = [
            'ハッピーアワーセット' => ['ja' => 'ハッピーアワーセット', 'en' => 'Happy Hour Set', 'vi' => 'Combo giờ vàng'],
            'ディナーフィースト' => ['ja' => 'ディナーフィースト', 'en' => 'Dinner Feast', 'vi' => 'Tiệc tối'],
        ];

        foreach ($skus as $sku) {
            $optionValueIds = array_values(array_filter([
                $sku->option_value1_id,
                $sku->option_value2_id,
                $sku->option_value3_id,
            ]));

            foreach (['ja', 'en', 'vi'] as $locale) {
                $labels = collect($optionValueIds)
                    ->map(fn (string $id): ?string => $valueNames->get("{$id}|{$locale}")?->label)
                    ->filter(fn (?string $label): bool => is_string($label) && trim($label) !== '')
                    ->values();

                $name = $labels->isNotEmpty()
                    ? $labels->implode(' / ')
                    : ($legacySkuNames[$sku->name][$locale] ?? $sku->name);

                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                DB::table('product_sku_translations')->updateOrInsert(
                    ['product_sku_id' => $sku->id, 'locale' => $locale],
                    ['name' => $name],
                );
            }
        }
    }
}
