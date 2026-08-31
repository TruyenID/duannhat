<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class CategoryPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $sku;

    public ?string $slug;

    public ?string $description;

    public ?string $parentId;

    public ?string $imageUrl;

    /** @var list<LocalizedText> */
    public array $translations;

    /** @var list<string> */
    public array $clearedLocales;

    /** @param list<LocalizedText> $translations @param list<string> $clearedLocales */
    public function __construct(string $name, ?string $sku, ?string $slug, ?string $description, ?string $parentId, public bool $active, public bool $featured = false, array $translations = [], ?string $imageUrl = null, array $clearedLocales = [])
    {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->sku = $sku === null ? null : MutationCommand::safeToken($sku, 'sku', 50);
        $this->slug = $slug === null ? null : MutationCommand::safeToken($slug, 'slug', 191);
        if ($description !== null && (mb_strlen($description) > 4000 || preg_match('/[\x00\x0B\x0C\x0E-\x1F\x7F]/u', $description) === 1)) {
            throw new InvalidArgumentException('description must be printable text of at most 4000 characters.');
        }
        $this->description = $description;
        $this->parentId = MutationCommand::nullableUuid($parentId, 'parentId');
        $this->imageUrl = $imageUrl === null || trim($imageUrl) === '' ? null : MutationCommand::safeToken($imageUrl, 'imageUrl', 500);
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
        return ['name' => $this->name, 'sku' => $this->sku, 'slug' => $this->slug, 'description' => $this->description, 'parent_id' => $this->parentId, 'active' => $this->active, 'featured' => $this->featured, 'image_url' => $this->imageUrl, 'translations' => $this->translations, 'cleared_locales' => $this->clearedLocales];
    }
}
