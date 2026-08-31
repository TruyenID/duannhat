<?php

namespace App\Services\Product\ValueObjects;

use App\Omnify\Enums\ProductStatusEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $description;

    public ?string $productTypeId;

    /** @var list<string> */
    public array $categoryIds;

    /** @var list<ProductOptionPayload> */
    public array $options;

    /** @var list<ProductSkuPayload> */
    public array $skus;

    /** @var list<LocalizedText> */
    public array $translations;

    /** @var list<string> */
    public array $galleryFileIds;

    /** @var list<ProductToppingGroupPayload> */
    public array $toppingGroups;

    public ?string $taxTypeId;

    public ?string $slug;

    public ?string $thumbnailFileId;

    /** @var list<string> */
    public array $clearedLocales;

    /**
     * @param  list<ProductSkuPayload>  $skus
     * @param  list<string>  $categoryIds
     * @param  list<ProductOptionPayload>  $options
     */
    public function __construct(
        string $name,
        ?string $description,
        array $skus,
        ?string $productTypeId = null,
        array $categoryIds = [],
        array $options = [],
        public bool $hidden = false,
        ?string $slug = null,
        ?string $taxTypeId = null,
        array $translations = [],
        array $galleryFileIds = [],
        array $toppingGroups = [],
        ?string $thumbnailFileId = null,
        public ProductStatusEnum $status = ProductStatusEnum::Draft,
        array $clearedLocales = [],
    ) {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->description = $description === null ? null : MutationCommand::safeText($description, 'description', 4000);

        foreach ($skus as $sku) {
            if (! $sku instanceof ProductSkuPayload) {
                throw new InvalidArgumentException('skus must contain ProductSkuPayload values.');
            }
        }

        foreach ($options as $option) {
            if (! $option instanceof ProductOptionPayload) {
                throw new InvalidArgumentException('options must contain ProductOptionPayload values.');
            }
        }

        $this->skus = MutationCommand::canonicalSet($skus, static fn (ProductSkuPayload $sku): string => $sku->skuId, 'skus');
        $this->productTypeId = MutationCommand::nullableUuid($productTypeId, 'productTypeId');
        $categoryIds = array_map(
            static fn (string $id): string => MutationCommand::uuid($id, 'categoryId'),
            array_values($categoryIds),
        );
        $this->categoryIds = MutationCommand::canonicalSet($categoryIds, static fn (string $id): string => $id, 'categoryIds');
        $this->options = MutationCommand::canonicalSet($options, static fn (ProductOptionPayload $option): string => $option->optionId, 'options');
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        foreach ($toppingGroups as $toppingGroup) {
            if (! $toppingGroup instanceof ProductToppingGroupPayload) {
                throw new InvalidArgumentException('toppingGroups must contain ProductToppingGroupPayload values.');
            }
        }
        $this->slug = $slug === null ? null : MutationCommand::safeToken($slug, 'slug', 255);
        $this->taxTypeId = MutationCommand::nullableUuid($taxTypeId, 'taxTypeId');
        $this->thumbnailFileId = MutationCommand::nullableUuid($thumbnailFileId, 'thumbnailFileId');
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->galleryFileIds = MutationCommand::uniqueOrdered(array_map(static fn (string $id): string => MutationCommand::uuid($id, 'galleryFileId'), array_values($galleryFileIds)), static fn (string $id): string => $id, 'galleryFileIds');
        $this->toppingGroups = MutationCommand::canonicalSet($toppingGroups, static fn (ProductToppingGroupPayload $group): string => $group->toppingGroupId.'|'.($group->skuId ?? ''), 'toppingGroups');
        $this->clearedLocales = MutationCommand::canonicalSet($clearedLocales, static fn (string $locale): string => MutationCommand::safeToken($locale, 'clearedLocale', 5), 'clearedLocales');
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'product_type_id' => $this->productTypeId,
            'category_ids' => $this->categoryIds,
            'options' => $this->options,
            'skus' => $this->skus,
            'hidden' => $this->hidden,
            'slug' => $this->slug,
            'tax_type_id' => $this->taxTypeId,
            'translations' => $this->translations,
            'gallery_file_ids' => $this->galleryFileIds,
            'thumbnail_file_id' => $this->thumbnailFileId,
            'topping_groups' => $this->toppingGroups,
            'status' => $this->status->value,
            'cleared_locales' => $this->clearedLocales,
        ];
    }
}
