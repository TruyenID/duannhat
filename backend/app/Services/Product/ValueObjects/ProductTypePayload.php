<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductTypePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $code;

    public ?string $description;

    public string $productForm;

    public ?string $icon;

    /** @var list<LocalizedText> */
    public array $translations;

    /** @var list<string> */
    public array $clearedLocales;

    /** @param list<LocalizedText> $translations @param list<string> $clearedLocales */
    public function __construct(string $name, ?string $code, ?string $description, string $productForm, public bool $hasRecipe, public bool $inventoryTracked, ?string $icon, public bool $active, array $translations = [], array $clearedLocales = [])
    {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->code = $code === null || trim($code) === '' ? null : MutationCommand::safeToken($code, 'code', 50);
        if ($description !== null && (mb_strlen($description) > 4000 || preg_match('/[\x00\x0B\x0C\x0E-\x1F\x7F]/u', $description) === 1)) {
            throw new InvalidArgumentException('description must be printable text of at most 4000 characters.');
        }
        $this->description = $description;
        $this->productForm = MutationCommand::safeToken($productForm, 'productForm', 32);
        if (! in_array($this->productForm, ['physical', 'digital'], true)) {
            throw new InvalidArgumentException('productForm must be physical or digital.');
        }
        $this->icon = $icon === null || trim($icon) === '' ? null : MutationCommand::safeToken($icon, 'icon', 255);
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->clearedLocales = MutationCommand::canonicalSet(array_map(static fn (string $locale): string => MutationCommand::safeToken($locale, 'clearedLocale', 5), $clearedLocales), static fn (string $locale): string => $locale, 'clearedLocales');
        foreach ($this->clearedLocales as $locale) {
            if (! in_array($locale, ['ja', 'en', 'vi'], true)) {
                throw new InvalidArgumentException('clearedLocales contains an unsupported locale.');
            }
            foreach ($this->translations as $translation) {
                if ($translation->locale->value === $locale) {
                    throw new InvalidArgumentException('A locale cannot be updated and cleared together.');
                }
            }
        }
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'description' => $this->description, 'product_form' => $this->productForm, 'has_recipe' => $this->hasRecipe, 'is_inventory_tracked' => $this->inventoryTracked, 'icon' => $this->icon, 'active' => $this->active, 'translations' => $this->translations, 'cleared_locales' => $this->clearedLocales];
    }
}
