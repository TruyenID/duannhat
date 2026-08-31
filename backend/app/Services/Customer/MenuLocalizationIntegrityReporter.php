<?php

namespace App\Services\Customer;

use App\Models\Menu;
use App\Services\Topping\Contracts\ToppingGroupItemIntegrity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MenuLocalizationIntegrityReporter
{
    /**
     * #2850 — cửa sổ khử trùng phải khớp với NHỊP ĐỔI của thứ đang đo.
     *
     * Thiếu bản dịch là một TRẠNG THÁI cấu hình, không phải một sự kiện: nó
     * đứng yên cho tới khi ai đó sửa catalog. Cửa sổ 5 phút trước đây tính ra
     * 288 dòng log mỗi ngày cho MỘT menu — đo trên production hai ngày
     * 08-13/14: 725 dòng, tất cả rút gọn về đúng **4 payload** khác nhau, hai
     * menu ở 人形町店. Một dòng log lặp lại 288 lần không nói thêm được gì so
     * với lần đầu, nhưng nó chôn những dòng đáng đọc khác trong cùng file.
     *
     * Vân tay theo TẬP path vẫn giữ nguyên, và đó là phần quan trọng: một path
     * thiếu dịch MỚI xuất hiện là tin tức, nên nó đổi vân tay và log ngay lập
     * tức, không phải chờ hết cửa sổ.
     */
    private const DEDUPLICATION_SECONDS = 86_400;

    public function __construct(
        // #962 — "dòng topping nào hỏng" là hiểu biết của Catalog, không phải
        // của người dựng thực đơn khách. Xem `ToppingGroupItemIntegrity`.
        private readonly ToppingGroupItemIntegrity $toppingIntegrity,
    ) {}

    public function inspect(Menu $menu, string $locale): void
    {
        $missing = $this->missingTranslationPaths($menu, $locale);

        if ($missing !== []) {
            $this->report($menu, $locale, 'missing_translation', 'fallback', $missing);
            request()->attributes->set('localization_fallback_count', count($missing));
        }

        $invalidRelations = $this->invalidToppingRelationCount($menu);
        if ($invalidRelations > 0) {
            $this->report(
                $menu,
                $locale,
                'invalid_topping_relation',
                'excluded',
                ["topping_group_items:{$invalidRelations}"],
            );
        }
    }

    /** @return array<int, string> */
    private function missingTranslationPaths(Menu $menu, string $locale): array
    {
        $paths = [];
        $check = function (object $model, string $relation, string $field, string $path, bool $optional = false) use (&$paths, $locale): void {
            if ($optional && blank($model->{$field} ?? null)) {
                return;
            }

            $translation = $model->{$relation}->firstWhere('locale', $locale);
            if (! $translation || blank($translation->{$field} ?? null)) {
                $paths[] = $path;
            }
        };

        $check($menu, 'translations', 'name', "menu:{$menu->id}.name");
        $check($menu, 'translations', 'description', "menu:{$menu->id}.description", true);

        foreach ($menu->menuProducts as $menuProduct) {
            $section = $menuProduct->menuSection;
            $product = $menuProduct->product;
            if ($section) {
                $check($section, 'translations', 'name', "menu_section:{$section->id}.name");
            }
            if (! $product) {
                continue;
            }

            $check($product, 'translations', 'name', "product:{$product->id}.name");
            $check($product, 'translations', 'description', "product:{$product->id}.description", true);

            foreach ($product->options as $option) {
                $check($option, 'translations', 'name', "product_option:{$option->id}.name");
                foreach ($option->values as $value) {
                    $check($value, 'translations', 'label', "product_option_value:{$value->id}.label");
                }
            }
            foreach ($menuProduct->menuProductSkus as $menuSku) {
                if ($menuSku->productSku) {
                    // A SKU name is optional for the common/default SKU. The
                    // customer payload labels selectable variants from their
                    // product-option value, not from this technical SKU row.
                    // Requiring a translation for a deliberately blank SKU
                    // name therefore creates one false fallback per product.
                    $check($menuSku->productSku, 'translations', 'name', "product_sku:{$menuSku->productSku->id}.name", true);
                }
            }
            foreach ($product->toppingGroups as $group) {
                $check($group, 'translations', 'name', "topping_group:{$group->id}.name");
                foreach ($group->items as $item) {
                    if ($item->product) {
                        $check($item->product, 'translations', 'name', "topping_product:{$item->product->id}.name");
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function invalidToppingRelationCount(Menu $menu): int
    {
        $groupIds = $menu->menuProducts
            ->flatMap(fn ($menuProduct) => $menuProduct->product?->toppingGroups ?? [])
            ->pluck('id')
            ->unique()
            ->values()
            ->map(fn ($id): string => (string) $id)
            ->all();

        return $this->toppingIntegrity->unusableItemCountForGroups($groupIds);
    }

    /** @param array<int, string> $paths */
    private function report(Menu $menu, string $locale, string $reason, string $result, array $paths): void
    {
        sort($paths);
        $fingerprint = hash('sha256', implode('|', $paths));
        $key = "menu-localization-integrity:{$menu->id}:{$locale}:{$reason}:{$fingerprint}";
        if (! Cache::add($key, true, self::DEDUPLICATION_SECONDS)) {
            return;
        }

        Log::warning('menu.localization.integrity', [
            'event' => 'menu.localization.integrity',
            'result' => $result,
            'reason_code' => $reason,
            'locale' => $locale,
            'menu_id' => (string) $menu->id,
            'organization_id' => (string) $menu->organization_id,
            'brand_id' => (string) $menu->brand_id,
            'branch_id' => (string) $menu->branch_id,
            'affected_count' => count($paths),
            'paths' => array_slice($paths, 0, 25),
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
