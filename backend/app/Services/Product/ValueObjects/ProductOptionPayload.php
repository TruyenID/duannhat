<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductOptionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $optionId;

    public string $name;

    /** @var list<ProductOptionValuePayload> */
    public array $values;

    /** @var list<LocalizedText> */
    public array $translations;

    public string $key;

    /** @param list<ProductOptionValuePayload> $values */
    public array $clearedLocales;

    public function __construct(string $optionId, string $name, array $values, string $key, public int $position, public bool $active = true, array $translations = [], array $clearedLocales = [])
    {
        foreach ($values as $value) {
            if (! $value instanceof ProductOptionValuePayload) {
                throw new InvalidArgumentException('values must contain ProductOptionValuePayload values.');
            }
        }

        $this->optionId = MutationCommand::uuid($optionId, 'optionId');
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->values = MutationCommand::canonicalSet($values, static fn (ProductOptionValuePayload $value): string => $value->valueId, 'values');
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        if ($position < 1 || $position > 3) {
            throw new InvalidArgumentException('position must be between one and three.');
        }
        $this->key = MutationCommand::safeToken($key, 'key', 100);
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->clearedLocales = MutationCommand::canonicalSet($clearedLocales, static fn (string $locale): string => $locale, 'clearedLocales');
    }

    public function jsonSerialize(): array
    {
        return ['option_id' => $this->optionId, 'key' => $this->key, 'name' => $this->name, 'position' => $this->position, 'active' => $this->active, 'translations' => $this->translations, 'cleared_locales' => $this->clearedLocales, 'values' => $this->values];
    }
}
