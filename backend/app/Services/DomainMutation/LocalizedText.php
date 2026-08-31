<?php

namespace App\Services\DomainMutation;

final readonly class LocalizedText implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $description;

    public function __construct(public SupportedLocale $locale, string $name, ?string $description = null)
    {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->description = $description === null ? null : MutationCommand::safeText($description, 'description', 4000);
    }

    public function jsonSerialize(): array
    {
        return ['locale' => $this->locale->value, 'name' => $this->name, 'description' => $this->description];
    }
}
