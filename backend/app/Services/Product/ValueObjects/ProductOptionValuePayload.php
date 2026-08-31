<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductOptionValuePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $valueId;

    public string $label;

    /** @var list<LocalizedText> */
    public array $translations;

    public string $value;

    public array $clearedLocales;

    public function __construct(string $valueId, string $label, string $value, public int $position, public bool $active = true, array $translations = [], array $clearedLocales = [])
    {
        $this->valueId = MutationCommand::uuid($valueId, 'valueId');
        $this->label = MutationCommand::safeToken($label, 'label', 255);
        if ($position < 1) {
            throw new InvalidArgumentException('position must be at least one.');
        }
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        $this->value = MutationCommand::safeToken($value, 'value', 255);
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
        $this->clearedLocales = MutationCommand::canonicalSet($clearedLocales, static fn (string $locale): string => $locale, 'clearedLocales');
    }

    public function jsonSerialize(): array
    {
        return ['value_id' => $this->valueId, 'value' => $this->value, 'label' => $this->label, 'position' => $this->position, 'active' => $this->active, 'translations' => $this->translations, 'cleared_locales' => $this->clearedLocales];
    }
}
