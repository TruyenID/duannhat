<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Organization;
use App\Services\Omnify\AllergenService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the JP/EU/US regulatory allergen sets for every organization.
 *
 * - JP: 8 mandatory (tokutei-genryou) + 20 recommended (junkan-tokutei-genryou)
 * - EU: 14 mandatory (FIC 1169/2011 Annex II)
 * - US:  9 mandatory (FALCPA + FASTER Act 2023 sesame)
 *
 * Idempotent: matches on (organization_id, code, jurisdiction). Re-running
 * the seeder syncs translations / severity / is_active without duplicating.
 *
 * Goes through AllergenService::create / ::update so Astrotomic translations
 * persist via flushTranslations() (raw Allergen::create would route locale
 * keys through fillable and silently lose them — see project convention #3).
 */
class AllergenSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(AllergenService $service): void
    {
        $organizations = Organization::query()->get();

        if ($organizations->isEmpty()) {
            $this->command?->warn('AllergenSeeder skipped: no organizations to seed against.');

            return;
        }

        foreach ($organizations as $org) {
            foreach ($this->allergenSet() as $row) {
                $existing = Allergen::query()
                    ->where('organization_id', $org->id)
                    ->where('code', $row['code'])
                    ->where('jurisdiction', $row['jurisdiction'])
                    ->first();

                $payload = [
                    'organization_id' => $org->id,
                    'code' => $row['code'],
                    'jurisdiction' => $row['jurisdiction'],
                    'severity' => $row['severity'],
                    'is_active' => true,
                    'name:ja' => $row['name']['ja'] ?? $row['name']['en'],
                    'name:en' => $row['name']['en'],
                    'name:vi' => $row['name']['vi'] ?? $row['name']['en'],
                ];

                if ($existing) {
                    $service->update($existing, $payload);
                } else {
                    $service->create($payload);
                }
            }
        }
    }

    /**
     * @return array<int, array{code: string, jurisdiction: string, severity: string, name: array<string, string>}>
     */
    private function allergenSet(): array
    {
        return [
            // -----------------------------------------------------------------
            // JP — 8 mandatory (特定原材料 tokutei-genryou)
            // -----------------------------------------------------------------
            ['code' => 'shrimp', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => 'えび', 'en' => 'Shrimp', 'vi' => 'Tôm']],
            ['code' => 'crab', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => 'かに', 'en' => 'Crab', 'vi' => 'Cua']],
            ['code' => 'wheat', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => '小麦', 'en' => 'Wheat', 'vi' => 'Lúa mì']],
            ['code' => 'buckwheat', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => 'そば', 'en' => 'Buckwheat', 'vi' => 'Kiều mạch']],
            ['code' => 'egg', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => '卵', 'en' => 'Egg', 'vi' => 'Trứng']],
            ['code' => 'milk', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => '乳', 'en' => 'Milk', 'vi' => 'Sữa']],
            ['code' => 'peanut', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => '落花生', 'en' => 'Peanut', 'vi' => 'Đậu phộng']],
            ['code' => 'walnut', 'jurisdiction' => 'jp', 'severity' => 'mandatory', 'name' => ['ja' => 'くるみ', 'en' => 'Walnut', 'vi' => 'Quả óc chó']],

            // -----------------------------------------------------------------
            // JP — 20 recommended (準特定原材料 junkan-tokutei-genryou)
            // -----------------------------------------------------------------
            ['code' => 'almond', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'アーモンド', 'en' => 'Almond', 'vi' => 'Hạnh nhân']],
            ['code' => 'abalone', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'あわび', 'en' => 'Abalone', 'vi' => 'Bào ngư']],
            ['code' => 'squid', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'いか', 'en' => 'Squid', 'vi' => 'Mực']],
            ['code' => 'salmon_roe', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'いくら', 'en' => 'Salmon Roe', 'vi' => 'Trứng cá hồi']],
            ['code' => 'orange', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'オレンジ', 'en' => 'Orange', 'vi' => 'Cam']],
            ['code' => 'cashew', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'カシューナッツ', 'en' => 'Cashew Nut', 'vi' => 'Hạt điều']],
            ['code' => 'kiwi', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'キウイフルーツ', 'en' => 'Kiwifruit', 'vi' => 'Kiwi']],
            ['code' => 'beef', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => '牛肉', 'en' => 'Beef', 'vi' => 'Thịt bò']],
            ['code' => 'sesame', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'ごま', 'en' => 'Sesame', 'vi' => 'Vừng']],
            ['code' => 'salmon', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'さけ', 'en' => 'Salmon', 'vi' => 'Cá hồi']],
            ['code' => 'mackerel', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'さば', 'en' => 'Mackerel', 'vi' => 'Cá thu']],
            ['code' => 'soybean', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => '大豆', 'en' => 'Soybean', 'vi' => 'Đậu nành']],
            ['code' => 'chicken', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => '鶏肉', 'en' => 'Chicken', 'vi' => 'Thịt gà']],
            ['code' => 'banana', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'バナナ', 'en' => 'Banana', 'vi' => 'Chuối']],
            ['code' => 'pork', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => '豚肉', 'en' => 'Pork', 'vi' => 'Thịt heo']],
            ['code' => 'matsutake', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'まつたけ', 'en' => 'Matsutake Mushroom', 'vi' => 'Nấm matsutake']],
            ['code' => 'peach', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'もも', 'en' => 'Peach', 'vi' => 'Đào']],
            ['code' => 'yam', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'やまいも', 'en' => 'Yam', 'vi' => 'Khoai mỡ']],
            ['code' => 'apple', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'りんご', 'en' => 'Apple', 'vi' => 'Táo']],
            ['code' => 'gelatin', 'jurisdiction' => 'jp', 'severity' => 'recommended', 'name' => ['ja' => 'ゼラチン', 'en' => 'Gelatin', 'vi' => 'Gelatin']],

            // -----------------------------------------------------------------
            // EU — 14 mandatory (FIC 1169/2011 Annex II)
            // -----------------------------------------------------------------
            ['code' => 'cereals_gluten', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Cereals containing gluten', 'ja' => 'グルテン含有穀物', 'vi' => 'Ngũ cốc chứa gluten']],
            ['code' => 'crustaceans', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Crustaceans', 'ja' => '甲殻類', 'vi' => 'Giáp xác']],
            ['code' => 'eggs', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Eggs', 'ja' => '卵', 'vi' => 'Trứng']],
            ['code' => 'fish', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Fish', 'ja' => '魚', 'vi' => 'Cá']],
            ['code' => 'peanuts', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Peanuts', 'ja' => '落花生', 'vi' => 'Đậu phộng']],
            ['code' => 'soybeans', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Soybeans', 'ja' => '大豆', 'vi' => 'Đậu nành']],
            ['code' => 'milk', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Milk', 'ja' => '乳', 'vi' => 'Sữa']],
            ['code' => 'tree_nuts', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Tree Nuts', 'ja' => 'ナッツ類', 'vi' => 'Các loại hạt cây']],
            ['code' => 'celery', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Celery', 'ja' => 'セロリ', 'vi' => 'Cần tây']],
            ['code' => 'mustard', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Mustard', 'ja' => 'マスタード', 'vi' => 'Mù tạt']],
            ['code' => 'sesame', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Sesame Seeds', 'ja' => 'ごま', 'vi' => 'Vừng']],
            ['code' => 'sulphites', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Sulphur Dioxide and Sulphites', 'ja' => '亜硫酸塩', 'vi' => 'Sulfit']],
            ['code' => 'lupin', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Lupin', 'ja' => 'ルピナス', 'vi' => 'Đậu lupin']],
            ['code' => 'molluscs', 'jurisdiction' => 'eu', 'severity' => 'mandatory', 'name' => ['en' => 'Molluscs', 'ja' => '軟体動物', 'vi' => 'Động vật thân mềm']],

            // -----------------------------------------------------------------
            // US — 9 mandatory (FALCPA + FASTER Act 2023)
            // -----------------------------------------------------------------
            ['code' => 'milk', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Milk', 'ja' => '乳', 'vi' => 'Sữa']],
            ['code' => 'eggs', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Eggs', 'ja' => '卵', 'vi' => 'Trứng']],
            ['code' => 'fish', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Fish', 'ja' => '魚', 'vi' => 'Cá']],
            ['code' => 'crustacean_shellfish', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Crustacean Shellfish', 'ja' => '甲殻類', 'vi' => 'Hải sản giáp xác']],
            ['code' => 'tree_nuts', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Tree Nuts', 'ja' => 'ナッツ類', 'vi' => 'Các loại hạt cây']],
            ['code' => 'peanuts', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Peanuts', 'ja' => '落花生', 'vi' => 'Đậu phộng']],
            ['code' => 'wheat', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Wheat', 'ja' => '小麦', 'vi' => 'Lúa mì']],
            ['code' => 'soybeans', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Soybeans', 'ja' => '大豆', 'vi' => 'Đậu nành']],
            ['code' => 'sesame', 'jurisdiction' => 'us', 'severity' => 'mandatory', 'name' => ['en' => 'Sesame', 'ja' => 'ごま', 'vi' => 'Vừng']],
        ];
    }
}
