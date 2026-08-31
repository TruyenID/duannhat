<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductToppingGroupItemOverride;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Product\ValueObjects\ProductOptionPayload;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use App\Services\Product\ValueObjects\ProductPayload;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use App\Services\Product\ValueObjects\ProductToppingGroupPayload;
use App\Services\Product\ValueObjects\ProductToppingItemOverridePayload;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class ProductPayloadFactory
{
    /** @param array<string, mixed> $data */
    public function forCreate(array $data, bool $createDefaultSku = true, ?string $identitySeed = null): ProductPayload
    {
        $options = [];
        $valueIdsByInputIndex = [];
        $optionPositions = [];
        foreach (array_values($data['options'] ?? []) as $optionIndex => $row) {
            $optionPositions[$optionIndex] = (int) $row['position'];
            $optionPath = "option:{$row['position']}:{$row['key']}";
            $values = [];
            foreach (array_values($row['values'] ?? []) as $valueIndex => $value) {
                $valuePosition = $value['position'] ?? ($valueIndex + 1);
                $valueId = $this->createId($identitySeed, "{$optionPath}:value:{$valuePosition}:{$value['value']}");
                $valueIdsByInputIndex[$optionIndex][$valueIndex] = $valueId;
                $values[] = new ProductOptionValuePayload(
                    $valueId,
                    $value['label'],
                    $value['value'],
                    $value['position'] ?? ($valueIndex + 1),
                    $value['is_active'] ?? true,
                );
            }
            $options[] = new ProductOptionPayload(
                $this->createId($identitySeed, $optionPath),
                $row['name'],
                $values,
                $row['key'],
                $row['position'],
                $row['is_active'] ?? true,
            );
        }

        $skus = [];
        foreach (array_values($data['skus'] ?? []) as $row) {
            $optionValueIds = [null, null, null];
            foreach ($row['value_indices'] ?? [] as $optionIndex => $valueIndex) {
                if (! isset($valueIdsByInputIndex[$optionIndex][$valueIndex])) {
                    throw new \InvalidArgumentException("SKU references an unknown option value at option index {$optionIndex}.");
                }
                $optionValueIds[($optionPositions[$optionIndex] ?? ($optionIndex + 1)) - 1] = $valueIdsByInputIndex[$optionIndex][$valueIndex];
            }
            while ($optionValueIds !== [] && end($optionValueIds) === null) {
                array_pop($optionValueIds);
            }
            $skuIdentity = trim((string) ($row['sku'] ?? ''));
            if ($skuIdentity === '') {
                $coordinates = $row['value_indices'] ?? [];
                ksort($coordinates);
                $skuIdentity = 'values:'.json_encode($coordinates, JSON_THROW_ON_ERROR);
            }
            $skus[] = $this->skuPayload($this->createId($identitySeed, "sku:{$skuIdentity}"), $row, $optionValueIds);
        }
        if ($skus === [] && $createDefaultSku) {
            $skus[] = $this->skuPayload($this->createId($identitySeed, 'sku:default'), ['name' => $this->resolvedName($data)], []);
        }

        return $this->payload($data, $this->resolvedName($data), $skus, $options);
    }

    /** @param array<string, mixed> $changes */
    public function forRevision(Product $product, array $changes): ProductPayload
    {
        $product->loadMissing(['translations', 'options.values.translations', 'skus.translations', 'skus.gallery', 'gallery', 'thumbnail', 'toppingGroups']);
        $translations = $this->mergedTranslations($product, $changes);
        $clearedLocales = [];
        foreach (SupportedLocale::cases() as $locale) {
            if (array_key_exists($locale->value, $changes) && trim((string) ($changes[$locale->value]['name'] ?? '')) === '') {
                $clearedLocales[] = $locale->value;
            }
        }

        $options = $product->options->map(fn ($option) => new ProductOptionPayload(
            $option->id,
            $option->name,
            $option->values->map(fn ($value) => new ProductOptionValuePayload(
                $value->id,
                $value->label,
                $value->value,
                (int) $value->position,
                (bool) $value->is_active,
                $this->modelTranslations($value, 'label'),
            ))->all(),
            $option->key,
            (int) $option->position,
            (bool) $option->is_active,
            $this->modelTranslations($option, 'name'),
        ))->all();
        $skus = $product->skus->map(fn ($sku) => new ProductSkuPayload(
            $sku->id,
            $sku->sku,
            $sku->selling_price ?? 0,
            $this->optionValueSlots($sku->option_value1_id, $sku->option_value2_id, $sku->option_value3_id),
            (bool) $sku->is_active,
            $sku->name,
            $sku->recipe_id,
            $sku->recipe_multiplier ?? 1,
            $sku->cost_price ?? 0,
            $sku->cost_price_auto ?? 0,
            (bool) $sku->is_cost_override,
            $sku->inventory_mode instanceof ProductSkuInventoryModeEnum ? $sku->inventory_mode : (ProductSkuInventoryModeEnum::tryFrom((string) $sku->inventory_mode) ?? ProductSkuInventoryModeEnum::MadeToOrder),
            $this->modelTranslations($sku, 'name'),
            $sku->gallery->pluck('id')->all(),
        ))->all();
        $toppingGroups = $product->toppingGroups->map(function ($group) use ($product): ProductToppingGroupPayload {
            $overrides = ProductToppingGroupItemOverride::where('product_id', $product->id)->where('topping_group_id', $group->id)->get();

            return new ProductToppingGroupPayload(
                $group->id,
                null,
                (int) $group->pivot->sort_order,
                true,
                $group->pivot->min_select_override === null ? null : (int) $group->pivot->min_select_override,
                $group->pivot->max_select_override === null ? null : (int) $group->pivot->max_select_override,
                $overrides->map(fn ($override) => new ProductToppingItemOverridePayload(
                    $override->topping_group_item_id,
                    $override->product_sku_id,
                    (bool) $override->is_hidden,
                    $override->override_price === null ? null : (int) $override->override_price,
                ))->all(),
            );
        })->all();
        $description = array_key_exists('description', $changes)
            ? $changes['description']
            : ($product->getRawOriginal('description') === null ? null : $this->printable($product->getRawOriginal('description')));
        if (! is_string($description) || trim($description) === '') {
            $description = null;
        }

        return new ProductPayload(
            $changes['name'] ?? $this->firstTranslationName($translations) ?? $product->name,
            $description,
            $skus,
            $changes['product_type_id'] ?? $product->product_type_id,
            $changes['category_ids'] ?? $product->categories()->pluck('categories.id')->all(),
            $options,
            $changes['is_hidden'] ?? (bool) $product->is_hidden,
            array_key_exists('slug', $changes) ? $changes['slug'] : $product->slug,
            array_key_exists('tax_type_id', $changes) ? $changes['tax_type_id'] : $product->tax_type_id,
            $translations,
            $product->gallery->pluck('id')->all(),
            $toppingGroups,
            $product->thumbnail?->id,
            isset($changes['status']) ? ProductStatusEnum::from($changes['status']) : ($product->status instanceof ProductStatusEnum ? $product->status : ProductStatusEnum::from($product->status)),
            $clearedLocales,
        );
    }

    /** @param array<string, mixed> $data @param list<ProductSkuPayload> $skus @param list<ProductOptionPayload> $options */
    private function payload(array $data, string $name, array $skus, array $options): ProductPayload
    {
        return new ProductPayload(
            $name,
            $data['description'] ?? null,
            $skus,
            $data['product_type_id'] ?? null,
            $data['category_ids'] ?? [],
            $options,
            $data['is_hidden'] ?? false,
            $data['slug'] ?? null,
            $data['tax_type_id'] ?? null,
            $this->requestTranslations($data),
            $data['gallery_file_ids'] ?? [],
            [],
            $data['thumbnail_file_id'] ?? null,
            ProductStatusEnum::from($data['status'] ?? ProductStatusEnum::Draft->value),
        );
    }

    /** @param array<string, mixed> $row @param list<string|null> $optionValueIds */
    private function skuPayload(string $id, array $row, array $optionValueIds): ProductSkuPayload
    {
        return new ProductSkuPayload($id, $row['sku'] ?? null, $row['selling_price'] ?? $row['cost_price'] ?? 0, $optionValueIds, $row['is_active'] ?? true, $row['name'] ?? null, costPrice: $row['cost_price'] ?? 0, costOverride: $row['is_cost_override'] ?? false);
    }

    /** @param array<string, mixed> $data @return list<LocalizedText> */
    private function requestTranslations(array $data): array
    {
        $translations = [];
        foreach (SupportedLocale::cases() as $locale) {
            $row = $data[$locale->value] ?? null;
            if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                $translations[] = new LocalizedText($locale, $row['name'], $row['description'] ?? null);
            }
        }

        return $translations;
    }

    /** @param array<string, mixed> $changes @return list<LocalizedText> */
    private function mergedTranslations(Product $product, array $changes): array
    {
        $current = collect($this->modelTranslations($product, 'name'))->keyBy(fn (LocalizedText $text) => $text->locale->value);
        if (isset($changes['name'])
            && $changes['name'] !== $product->getRawOriginal('name')
            && ! array_intersect(array_keys($changes), array_map(fn (SupportedLocale $locale) => $locale->value, SupportedLocale::cases()))) {
            $locale = SupportedLocale::tryFrom(app()->getLocale()) ?? SupportedLocale::English;
            $existing = $current->get($locale->value);
            $current->put($locale->value, new LocalizedText(
                $locale,
                $changes['name'],
                array_key_exists('description', $changes) ? $changes['description'] : $existing?->description,
            ));
        }
        foreach (SupportedLocale::cases() as $locale) {
            if (! array_key_exists($locale->value, $changes)) {
                continue;
            }
            $row = $changes[$locale->value];
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                $current->forget($locale->value);
            } else {
                $current->put($locale->value, new LocalizedText($locale, $row['name'], $row['description'] ?? null));
            }
        }

        return $current->values()->all();
    }

    /** @return list<LocalizedText> */
    private function modelTranslations(object $model, string $nameField): array
    {
        return $model->translations
            ->filter(fn ($translation) => trim((string) $translation->{$nameField}) !== '')
            ->map(fn ($translation) => new LocalizedText(
                SupportedLocale::from($translation->locale),
                $this->printable($translation->{$nameField}),
                trim((string) ($translation->description ?? '')) === '' ? null : $this->printable($translation->description),
            ))
            ->all();
    }

    private function printable(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /** @return list<string|null> */
    private function optionValueSlots(?string $first, ?string $second, ?string $third): array
    {
        $slots = [$first, $second, $third];
        while ($slots !== [] && end($slots) === null) {
            array_pop($slots);
        }

        return $slots;
    }

    private function createId(?string $identitySeed, string $path): string
    {
        return $identitySeed === null
            ? (string) Str::uuid()
            : (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "product-payload:{$identitySeed}:{$path}");
    }

    /** @param list<LocalizedText> $translations */
    private function firstTranslationName(array $translations): ?string
    {
        return $translations[0]->name ?? null;
    }

    /** @param array<string, mixed> $data */
    private function resolvedName(array $data): string
    {
        foreach (['name', 'ja', 'en', 'vi'] as $key) {
            $name = $key === 'name' ? ($data[$key] ?? null) : ($data[$key]['name'] ?? null);
            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        throw new \InvalidArgumentException('A product name is required.');
    }
}
