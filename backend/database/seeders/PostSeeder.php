<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds sample Post content (categories, tags, posts + translations) per organization.
 *
 * Safe to re-run — uses firstOrCreate on (organization_id, slug) for idempotency.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=PostSeeder
 */
class PostSeeder extends Seeder
{
    use Concerns\WritesTranslations;

    private const CATEGORIES = [
        'news' => [
            'ja' => ['name' => 'お知らせ', 'description' => '最新のお知らせ'],
            'en' => ['name' => 'News', 'description' => 'Latest news'],
            'vi' => ['name' => 'Tin tức', 'description' => 'Tin tức mới nhất'],
        ],
        'promotion' => [
            'ja' => ['name' => 'キャンペーン', 'description' => '期間限定キャンペーン'],
            'en' => ['name' => 'Promotion', 'description' => 'Limited-time promotions'],
            'vi' => ['name' => 'Khuyến mãi', 'description' => 'Khuyến mãi có thời hạn'],
        ],
    ];

    private const TAGS = [
        'family-day' => ['ja' => 'ファミリーデー', 'en' => 'Family Day', 'vi' => 'Ngày gia đình', 'color' => '#e8b04d'],
        'new-menu' => ['ja' => '新メニュー', 'en' => 'New Menu', 'vi' => 'Menu mới', 'color' => '#6b8e7f'],
        'holiday' => ['ja' => '祝日', 'en' => 'Holiday', 'vi' => 'Ngày lễ', 'color' => '#c0564d'],
        'event' => ['ja' => 'イベント', 'en' => 'Event', 'vi' => 'Sự kiện', 'color' => '#5d7a9a'],
        'coupon' => ['ja' => 'クーポン', 'en' => 'Coupon', 'vi' => 'Phiếu giảm giá', 'color' => '#8a6fa3'],
    ];

    private const POSTS = [
        [
            'slug' => 'family-day-2026',
            'status' => 'Published',
            'is_pinned' => true,
            'category' => 'promotion',
            'tags' => ['family-day', 'event'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=1200&q=85',
            'published_offset_days' => -7,
            'ja' => ['title' => 'ファミリーデー — お子様にフォーをプレゼント', 'excerpt' => 'ご家族でのご来店で、お子様にミニフォーを無料で。', 'content' => "# ファミリーデー\n\n毎月第3日曜日、ご家族でご来店のお客様のお子様（6歳以下）にミニサイズのフォーを無料でご提供します。\n\n14時間煮込んだ優しい鶏がらスープで、小さなお子様にも安心してお召し上がりいただけます。"],
            'en' => ['title' => 'Family Day — Free Mini Pho for Kids', 'excerpt' => 'Complimentary mini bowl of pho for kids dining with family.', 'content' => "# Family Day\n\nEvery 3rd Sunday, children under 6 dining with family receive a complimentary mini bowl of pho.\n\nMade with our gentle 14-hour chicken broth — kid-safe and flavourful."],
            'vi' => ['title' => 'Ngày gia đình — Tặng tô phở nhỏ cho bé', 'excerpt' => 'Bé dưới 6 tuổi ăn cùng gia đình được tặng 1 tô phở mini.', 'content' => "# Ngày gia đình\n\nChủ nhật tuần 3 hàng tháng, các bé dưới 6 tuổi ăn cùng gia đình sẽ được tặng 1 tô phở mini.\n\nDùng nước dùng gà ninh 14 giờ dịu vị — an toàn và đậm đà cho bé."],
        ],
        [
            'slug' => 'spring-menu-launch',
            'status' => 'Published',
            'is_pinned' => false,
            'category' => 'news',
            'tags' => ['new-menu'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1583224944844-5b268c057b72?w=1200&q=85',
            'published_offset_days' => -3,
            'ja' => ['title' => '春の新作「ブンボーフエ」登場', 'excerpt' => 'フエ風ピリ辛スープ麺、春の香草をたっぷり添えて。', 'content' => "# ブンボーフエ\n\n中部フエ地方を代表するピリ辛スープ麺「ブンボーフエ」を新たにメニューに加えました。\n\nレモングラスと唐辛子の効いた深い味わい、太めのブン（米麺）とたっぷりの春の香草をどうぞ。"],
            'en' => ['title' => 'New: Bún Bò Huế Spicy Noodle Soup', 'excerpt' => 'Central Vietnam\'s bold lemongrass-chili noodle soup, with spring herbs.', 'content' => "# Bún Bò Huế\n\nWe're adding the signature spicy noodle soup of Huế (Central Vietnam) to our menu.\n\nDeep lemongrass-chili broth, thick round rice noodles, topped with fresh spring herbs."],
            'vi' => ['title' => 'Ra mắt Bún bò Huế cay nồng', 'excerpt' => 'Sả, ớt, sợi bún tròn, rau thơm mùa xuân tươi rói.', 'content' => "# Bún bò Huế\n\nChúng tôi vừa thêm món bún bò Huế — đặc sản miền Trung — vào thực đơn.\n\nNước dùng đậm vị sả ớt, sợi bún tròn dai, ăn kèm rau thơm mùa xuân tươi rói."],
        ],
        [
            'slug' => 'golden-week-hours',
            'status' => 'Published',
            'is_pinned' => false,
            'category' => 'news',
            'tags' => ['holiday'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1604908176997-125f25cc6f3d?w=1200&q=85',
            'published_offset_days' => -1,
            'ja' => ['title' => 'ゴールデンウィーク — 全店通常営業', 'excerpt' => 'GW期間中も14時間ブイヨンのフォーをご提供します。', 'content' => "# ゴールデンウィーク営業のお知らせ\n\nGW期間中（4/29〜5/6）も全店通常営業いたします。\n\n14時間煮込んだ看板のフォーボー、ブンチャー、バインミーなど全メニューご用意してお待ちしております。"],
            'en' => ['title' => 'Golden Week — All Stores Open', 'excerpt' => 'Enjoy our 14-hour-simmered pho throughout Golden Week.', 'content' => "# Golden Week Opening Hours\n\nAll stores will operate normal hours during Golden Week (Apr 29 – May 6).\n\nOur signature 14-hour-simmered pho bò, bún chả and bánh mì are all available."],
            'vi' => ['title' => 'Tuần lễ vàng — mở cửa đủ menu phở', 'excerpt' => 'Phục vụ đầy đủ phở bò 14 giờ trong suốt kỳ nghỉ.', 'content' => "# Giờ mở cửa Tuần lễ vàng\n\nTất cả cửa hàng mở cửa bình thường trong Tuần lễ vàng (29/4 – 6/5).\n\nPhở bò ninh 14 giờ, bún chả, bánh mì — đầy đủ menu, sẵn sàng phục vụ."],
        ],
        [
            'slug' => 'chef-special-omakase',
            'status' => 'Published',
            'is_pinned' => false,
            'category' => 'news',
            'tags' => ['new-menu', 'event'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=1200&q=85',
            'published_offset_days' => -5,
            'ja' => ['title' => 'シェフおまかせ — ベトナム三大料理コース', 'excerpt' => '北部・中部・南部の代表料理を一度にお楽しみいただけるコース。', 'content' => "# ベトナム三大料理コース\n\n料理長が厳選した北部（フォー）、中部（ブンボーフエ）、南部（バインセオ）の代表料理を一皿ずつお楽しみいただけます。\n\n前菜のゴイクオン（生春巻き）からデザートのチェーまで、ベトナム料理の真髄を味わう全7品コース。"],
            'en' => ['title' => 'Chef\'s Omakase — Three Regions Tasting', 'excerpt' => 'North, Central, South Vietnam — a seven-course tasting menu.', 'content' => "# Three Regions Tasting\n\nOur head chef presents the signature dish of each region: phở (North), bún bò Huế (Central), bánh xèo (South).\n\nA seven-course journey from gỏi cuốn spring rolls to chè dessert — the essence of Vietnamese cuisine."],
            'vi' => ['title' => 'Set khám phá — Ẩm thực Bắc Trung Nam', 'excerpt' => 'Thực đơn 7 món do bếp trưởng tuyển chọn từ 3 miền.', 'content' => "# Set khám phá Bắc Trung Nam\n\nBếp trưởng chọn lọc món đại diện 3 miền: phở (Bắc), bún bò Huế (Trung), bánh xèo (Nam).\n\nHành trình 7 món — từ gỏi cuốn khai vị đến chè tráng miệng — tinh hoa ẩm thực Việt."],
        ],
        [
            'slug' => 'loyalty-program-launch',
            'status' => 'Published',
            'is_pinned' => false,
            'category' => 'promotion',
            'tags' => ['coupon', 'event'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1601050690597-df0568f70950?w=1200&q=85',
            'published_offset_days' => -10,
            'ja' => ['title' => 'フォーカード — 10杯で1杯無料', 'excerpt' => 'フォーを10杯ご注文ごとに、次回1杯無料でご提供。', 'content' => "# フォーカード\n\nフォーを10杯ご注文ごとに、次回1杯無料の「フォーカード」プログラムを開始しました。\n\nアプリでスタンプを貯めて、お好きなフォーでお使いいただけます。有効期限は最終利用から1年間。"],
            'en' => ['title' => 'Pho Card — 10 Bowls, 1 Free', 'excerpt' => 'Earn a stamp with every bowl of pho — 10 stamps, 1 free bowl.', 'content' => "# Pho Card\n\nCollect a stamp for every bowl of pho you order. Ten stamps earn you any pho of your choice on the house.\n\nTrack stamps in the app. Valid 12 months from last use."],
            'vi' => ['title' => 'Thẻ Phở — 10 tô tặng 1', 'excerpt' => 'Mỗi tô phở được 1 tem — đủ 10 tem tặng 1 tô bất kỳ.', 'content' => "# Thẻ Phở\n\nMỗi tô phở bạn ăn sẽ được 1 tem. Đủ 10 tem đổi 1 tô phở bất kỳ miễn phí.\n\nTem lưu trên app. Hạn sử dụng 12 tháng kể từ lần dùng gần nhất."],
        ],
        [
            'slug' => 'anniversary-5-years',
            'status' => 'Published',
            'is_pinned' => false,
            'category' => 'promotion',
            'tags' => ['event', 'family-day'],
            'cover_image_url' => 'https://images.unsplash.com/photo-1552611052-33e04de081de?w=1200&q=85',
            'published_offset_days' => -14,
            'ja' => ['title' => '5周年 — ハノイ伝統フォーに帰る', 'excerpt' => '5年目の記念に、ハノイ老舗レシピを忠実に再現した一杯を。', 'content' => "# 開店5周年フェア\n\nおかげさまで5周年。記念メニューとして、ハノイ旧市街の老舗レシピを忠実に再現した「ノスタルジック・ハノイフォー」を期間限定でご提供します。\n\n牛骨を14時間以上じっくり煮込んだ透き通るスープ、自家製生麺、香草の盛り合わせ。5年分の感謝を一杯に込めて。"],
            'en' => ['title' => '5 Years — Back to Hanoi\'s Classic Pho', 'excerpt' => 'Celebrating 5 years with a faithful Hanoi old-town pho.', 'content' => "# 5th Anniversary\n\nFive years of thanks. Our anniversary special — \"Nostalgic Hanoi Pho\" — faithfully recreates the recipe of a beloved old-town Hanoi house.\n\nBeef bones simmered 14+ hours for a crystal-clear broth, house-made fresh noodles, fragrant herbs on the side. Available for a limited time."],
            'vi' => ['title' => '5 năm — Trở về với phở Hà Nội hoài niệm', 'excerpt' => 'Mừng 5 năm, phục dựng công thức phở phố cổ Hà Nội.', 'content' => "# Kỷ niệm 5 năm\n\n5 năm — cảm ơn quý khách. Thực đơn kỷ niệm đặc biệt: “Phở Hà Nội hoài niệm” phục dựng công thức một quán lâu đời trong phố cổ Hà Nội.\n\nXương bò ninh hơn 14 giờ cho nước dùng trong vắt, bánh phở tươi tự làm, rau thơm ăn kèm. Có hạn — 5 năm tri ân trong một tô phở."],
        ],
        [
            'slug' => 'summer-coupon-draft',
            'status' => 'Draft',
            'is_pinned' => false,
            'category' => 'promotion',
            'tags' => ['coupon'],
            'cover_image_url' => null,
            'published_offset_days' => null,
            'ja' => ['title' => '夏の割引クーポン（下書き）', 'excerpt' => '夏季限定クーポンの準備中。', 'content' => '夏季の割引キャンペーンを企画中です。'],
            'en' => ['title' => 'Summer Discount Coupon (Draft)', 'excerpt' => 'Summer coupon in preparation.', 'content' => 'Planning a summer discount campaign.'],
            'vi' => ['title' => 'Phiếu giảm giá mùa hè (Bản nháp)', 'excerpt' => 'Đang chuẩn bị phiếu giảm giá mùa hè.', 'content' => 'Đang lên kế hoạch chương trình giảm giá mùa hè.'],
        ],
        [
            'slug' => 'old-new-year-campaign',
            'status' => 'Archived',
            'is_pinned' => false,
            'category' => 'promotion',
            'tags' => ['event'],
            'cover_image_url' => null,
            'published_offset_days' => -120,
            'ja' => ['title' => '新年キャンペーン（終了）', 'excerpt' => '過去のキャンペーンです。', 'content' => '新年を祝うキャンペーンは終了いたしました。'],
            'en' => ['title' => 'New Year Campaign (Ended)', 'excerpt' => 'Past campaign.', 'content' => 'Our New Year campaign has ended.'],
            'vi' => ['title' => 'Chiến dịch Tết (Đã kết thúc)', 'excerpt' => 'Chiến dịch trong quá khứ.', 'content' => 'Chiến dịch đón năm mới đã kết thúc.'],
        ],
    ];

    public function run(): void
    {
        $organizations = Organization::all();
        if ($organizations->isEmpty()) {
            $this->command->warn('No organization found. Run MockDataSeeder first.');

            return;
        }

        $author = User::first();

        foreach ($organizations as $org) {
            $categories = $this->seedCategories($org);
            $tags = $this->seedTags($org);
            $this->seedPosts($org, $author, $categories, $tags);
        }

        $this->command->info('Posts seeded for '.$organizations->count().' organization(s).');
    }

    /**
     * @return array<string, PostCategory>
     */
    private function seedCategories(Organization $org): array
    {
        $result = [];

        foreach (self::CATEGORIES as $slug => $locales) {
            $category = PostCategory::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => $slug],
                [
                    'name' => $locales['ja']['name'],
                    'description' => $locales['ja']['description'],
                    'sort_order' => 0,
                    'is_active' => true,
                ]
            );

            $this->writeTranslations($category, [
                'ja' => ['name' => $locales['ja']['name'], 'description' => $locales['ja']['description']],
                'en' => ['name' => $locales['en']['name'], 'description' => $locales['en']['description']],
                'vi' => ['name' => $locales['vi']['name'], 'description' => $locales['vi']['description']],
            ]);

            $result[$slug] = $category;
        }

        return $result;
    }

    /**
     * @return array<string, PostTag>
     */
    private function seedTags(Organization $org): array
    {
        $result = [];

        foreach (self::TAGS as $slug => $locales) {
            $tag = PostTag::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => $slug],
                [
                    'name' => $locales['ja'],
                    'color' => $locales['color'],
                ]
            );

            $this->writeTranslations($tag, [
                'ja' => ['name' => $locales['ja']],
                'en' => ['name' => $locales['en']],
                'vi' => ['name' => $locales['vi']],
            ]);

            $result[$slug] = $tag;
        }

        return $result;
    }

    /**
     * @param  array<string, PostCategory>  $categories
     * @param  array<string, PostTag>  $tags
     */
    private function seedPosts(
        Organization $org,
        ?User $author,
        array $categories,
        array $tags,
    ): void {
        foreach (self::POSTS as $def) {
            $publishedAt = $def['published_offset_days'] !== null
                ? Carbon::now()->addDays($def['published_offset_days'])
                : null;

            $post = Post::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => $def['slug']],
                [
                    'author_id' => $author?->id,
                    'category_id' => $categories[$def['category']]->id ?? null,
                    'title' => $def['ja']['title'],
                    'excerpt' => $def['ja']['excerpt'],
                    'content' => $def['ja']['content'],
                    'cover_image_url' => $def['cover_image_url'],
                    'status' => $def['status'],
                    'published_at' => $publishedAt,
                    'is_pinned' => $def['is_pinned'],
                    'view_count' => 0,
                ]
            );

            $post->fill([
                'category_id' => $categories[$def['category']]->id ?? null,
                'cover_image_url' => $def['cover_image_url'],
                'status' => $def['status'],
                'published_at' => $publishedAt,
                'is_pinned' => $def['is_pinned'],
            ])->save();

            $this->writeTranslations($post, [
                'ja' => $def['ja'],
                'en' => $def['en'],
                'vi' => $def['vi'],
            ]);

            $tagIds = collect($def['tags'])
                ->map(fn (string $slug): ?string => $tags[$slug]->id ?? null)
                ->filter()
                ->values()
                ->all();

            if (! empty($tagIds)) {
                $post->tags()->syncWithoutDetaching($tagIds);
            }
        }
    }
}
