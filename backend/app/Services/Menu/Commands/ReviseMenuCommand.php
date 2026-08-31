<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\RevisionPayloadMode;
use App\Services\Menu\ValueObjects\MenuDefinitionPayload;

final readonly class ReviseMenuCommand extends MutationCommand
{
    public string $menuId;

    public string $revisionFingerprint;

    public function __construct(MutationContext $context, string $menuId, public MenuDefinitionPayload $payload, string $revisionFingerprint, public RevisionPayloadMode $mode = RevisionPayloadMode::FullReplacement)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->revisionFingerprint = self::verifiedFingerprint($revisionFingerprint, 'revisionFingerprint', $payload);
    }
}
