<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class MenuLayoutPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var list<MenuSectionPayload> */
    public array $sections;

    /** @param list<MenuSectionPayload> $sections */
    public function __construct(array $sections)
    {
        foreach ($sections as $section) {
            if (! $section instanceof MenuSectionPayload) {
                throw new InvalidArgumentException('sections must contain MenuSectionPayload values.');
            }
        }

        $this->sections = MutationCommand::canonicalSet($sections, static fn (MenuSectionPayload $section): string => $section->sectionId, 'sections');
    }

    public function jsonSerialize(): array
    {
        return ['sections' => $this->sections];
    }
}
