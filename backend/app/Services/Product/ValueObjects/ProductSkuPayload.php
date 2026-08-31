<?php

namespace App\Services\Product\ValueObjects;

use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductSkuPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $skuId;

    public ?string $code;

    public string $sellingPrice;

    public string $costPrice;

    public string $costPriceAuto;

    public string $recipeMultiplier;

    /** @var list<string|null> */
    public array $optionValueIds;

    /** @var list<LocalizedText> */
    public array $translations;

    public ?string $recipeId;

    public ?string $name;

    /** @var list<string> */
    public array $galleryFileIds;

    /** @var list<string> */
    public array $clearedLocales;

    /** @param list<string|null> $optionValueIds */
    public function __construct(string $skuId, ?string $code, string|int|float $sellingPrice, array $optionValueIds = [], public bool $active = true, ?string $name = null, ?string $recipeId = null, string|int|float $recipeMultiplier = '1', string|int|float $costPrice = '0', string|int|float $costPriceAuto = '0', public bool $costOverride = false, public ProductSkuInventoryModeEnum $inventoryMode = ProductSkuInventoryModeEnum::MadeToOrder, array $translations = [], array $galleryFileIds = [], array $clearedLocales = [])
    {
        $this->skuId = MutationCommand::uuid($skuId, 'skuId');
        $this->code = $code === null ? null : MutationCommand::safeToken($code, 'code', 100);
        $this->sellingPrice = self::decimal($sellingPrice, 'sellingPrice', 2, true);
        $this->costPrice = self::decimal($costPrice, 'costPrice', 2, true);
        $this->costPriceAuto = self::decimal($costPriceAuto, 'costPriceAuto', 2, true);
        $this->recipeMultiplier = self::decimal($recipeMultiplier, 'recipeMultiplier', 4, false);

        $this->optionValueIds = array_map(
            static fn (?string $id): ?string => $id === null ? null : MutationCommand::uuid($id, 'optionValueId'),
            array_values($optionValueIds),
        );
        if (count(array_filter($this->optionValueIds)) !== count(array_unique(array_filter($this->optionValueIds)))) {
            throw new InvalidArgumentException('optionValueIds must not contain duplicates.');
        }
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        $this->name = $name === null ? null : MutationCommand::safeToken($name, 'name', 255);
        $this->recipeId = MutationCommand::nullableUuid($recipeId, 'recipeId');
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->galleryFileIds = MutationCommand::uniqueOrdered(array_map(static fn (string $id): string => MutationCommand::uuid($id, 'galleryFileId'), array_values($galleryFileIds)), static fn (string $id): string => $id, 'galleryFileIds');
        $this->clearedLocales = MutationCommand::canonicalSet(array_map(static fn (string $locale): string => MutationCommand::safeToken($locale, 'clearedLocale', 5), $clearedLocales), static fn (string $locale): string => $locale, 'clearedLocales');
        foreach ($this->clearedLocales as $locale) {
            if (! in_array($locale, ['ja', 'en', 'vi'], true)) {
                throw new InvalidArgumentException('clearedLocales contains an unsupported locale.');
            }
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'sku_id' => $this->skuId,
            'code' => $this->code,
            'selling_price' => $this->sellingPrice,
            'option_value_ids' => $this->optionValueIds,
            'active' => $this->active,
            'name' => $this->name,
            'recipe_id' => $this->recipeId,
            'recipe_multiplier' => $this->recipeMultiplier,
            'cost_price' => $this->costPrice,
            'cost_price_auto' => $this->costPriceAuto,
            'cost_override' => $this->costOverride,
            'inventory_mode' => $this->inventoryMode->value,
            'translations' => $this->translations,
            'gallery_file_ids' => $this->galleryFileIds,
            'cleared_locales' => $this->clearedLocales,
        ];
    }

    private static function decimal(string|int|float $value, string $name, int $scale, bool $allowZero): string
    {
        $raw = trim((string) $value);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw) !== 1 || (! $allowZero && (float) $raw <= 0)) {
            throw new InvalidArgumentException("{$name} must be a non-negative decimal value.");
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("{$name} supports at most {$scale} decimal places.");
        }
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : "{$whole}.{$fraction}";
    }
}
