<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\TillTenderType;
use App\Services\Till\Contracts\OrgTenderVocabulary;

/**
 * #962 — hiện thực Eloquent của {@see OrgTenderVocabulary}. Hai câu
 * truy vấn chép nguyên từ `PeripheralDeviceService`, không gộp.
 */
final class EloquentOrgTenderVocabulary implements OrgTenderVocabulary
{
    public function hasActiveOrgKey(string $organizationId, string $tenderKey): bool
    {
        return TillTenderType::query()
            ->where('organization_id', $organizationId)
            ->whereNull('branch_id')
            ->where('tender_key', $tenderKey)
            ->where('is_active', true)
            ->exists();
    }

    public function activeOrgKeysAmong(string $organizationId, array $tenderKeys): array
    {
        if ($tenderKeys === []) {
            return [];
        }

        return TillTenderType::query()
            ->where('organization_id', $organizationId)
            ->whereNull('branch_id')
            ->where('is_active', true)
            ->whereIn('tender_key', $tenderKeys)
            ->pluck('tender_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();
    }
}
