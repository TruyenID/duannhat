<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Menu\Enums\MenuOverrideMode;
use InvalidArgumentException;

final readonly class ShopMenuOverridePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $menuItemId;

    public string $branchId;

    public ?string $taxTypeId;

    /** @var list<LocalizedText> */
    public array $translations;

    /** @var list<MenuSkuOverridePayload> */
    public array $skuOverrides;

    public function __construct(string $menuItemId, public MenuOverrideMode $visibleMode, public ?bool $visible, public MenuOverrideMode $priceMode, public ?int $priceOverrideMinor, string $branchId, public MenuOverrideMode $taxTypeMode = MenuOverrideMode::Inherit, ?string $taxTypeId = null, array $translations = [], array $skuOverrides = [], public SupportedLocale $locale = SupportedLocale::Japanese, public MenuOverrideMode $translationsMode = MenuOverrideMode::Inherit, public MenuOverrideMode $skuOverridesMode = MenuOverrideMode::Inherit)
    {
        if ($priceOverrideMinor !== null && $priceOverrideMinor < 0) {
            throw new InvalidArgumentException('priceOverrideMinor cannot be negative.');
        }
        if (($visibleMode === MenuOverrideMode::Set) !== ($visible !== null)
            || ($priceMode === MenuOverrideMode::Set) !== ($priceOverrideMinor !== null)
            || ($taxTypeMode === MenuOverrideMode::Set) !== ($taxTypeId !== null)
            || ($translationsMode === MenuOverrideMode::Set) !== ($translations !== [])
            || ($skuOverridesMode === MenuOverrideMode::Set) !== ($skuOverrides !== [])) {
            throw new InvalidArgumentException('Shop overrides must distinguish inherit, clear, and set values.');
        }

        $this->menuItemId = MutationCommand::uuid($menuItemId, 'menuItemId');
        $this->branchId = MutationCommand::uuid($branchId, 'branchId');
        $this->taxTypeId = MutationCommand::nullableUuid($taxTypeId, 'taxTypeId');
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        foreach ($skuOverrides as $override) {
            if (! $override instanceof MenuSkuOverridePayload) {
                throw new InvalidArgumentException('skuOverrides must contain MenuSkuOverridePayload values.');
            }
        }
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->skuOverrides = MutationCommand::canonicalSet($skuOverrides, static fn (MenuSkuOverridePayload $override): string => $override->skuId, 'skuOverrides');
    }

    public function jsonSerialize(): array
    {
        return [
            'menu_item_id' => $this->menuItemId,
            'visible' => $this->visible,
            'visible_mode' => $this->visibleMode->value,
            'price_override_minor' => $this->priceOverrideMinor,
            'price_mode' => $this->priceMode->value,
            'branch_id' => $this->branchId,
            'tax_type_id' => $this->taxTypeId,
            'tax_type_mode' => $this->taxTypeMode->value,
            'translations' => $this->translations,
            'translations_mode' => $this->translationsMode->value,
            'sku_overrides' => $this->skuOverrides,
            'sku_overrides_mode' => $this->skuOverridesMode->value,
            'locale' => $this->locale->value,
        ];
    }
}
