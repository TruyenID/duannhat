<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;

/**
 * plan-053 (#1171) — the answer {@see TemplateResolver} gives: ONE definition,
 * already merged across system → brand → shop, plus the provenance a client
 * needs to explain it ("đang dùng mẫu hệ thống", "shop đang override 3 mục").
 *
 * `checksum` is what the workstation compares to decide whether a pulled
 * payload is new, and what it verifies a download against before it is
 * allowed to overwrite a good cache (TR-24).
 *
 * Every definition Cloud hands out is funnelled through
 * {@see DefinitionNormalizer} here — the single choke point where a PHP array
 * becomes a document another language has to parse (#1181).
 */
final class ResolvedTemplate
{
    /** @var array<string, mixed> */
    public readonly array $definition;

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $shopOverriddenPaths
     */
    public function __construct(
        public readonly PrintTemplateKind $kind,
        array $definition,
        public readonly PrintTemplateScope $sourceScope,
        public readonly ?int $version,
        public readonly ?string $effectiveFrom,
        public readonly ?string $brandTemplateId,
        public readonly ?string $shopTemplateId,
        public readonly array $shopOverriddenPaths,
        public readonly ?string $updatedAt,
    ) {
        $this->definition = DefinitionNormalizer::forTransport($definition);
    }

    /** sha256 of the canonical (recursively key-sorted) definition. */
    public function checksum(): string
    {
        return TemplateChecksum::of($this->definition);
    }

    /** True when nothing is configured and the code-shipped default is in force (TR-01). */
    public function isSystemDefault(): bool
    {
        return $this->sourceScope === PrintTemplateScope::System;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'scope' => $this->sourceScope->value,
            'version' => $this->version,
            'effective_from' => $this->effectiveFrom,
            'checksum' => $this->checksum(),
            'is_system_default' => $this->isSystemDefault(),
            'shop_overridden_paths' => $this->shopOverriddenPaths,
            'definition' => $this->definition,
            'updated_at' => $this->updatedAt,
        ];
    }
}
