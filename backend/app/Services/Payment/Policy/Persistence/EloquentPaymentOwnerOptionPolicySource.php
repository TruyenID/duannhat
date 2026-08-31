<?php

namespace App\Services\Payment\Policy\Persistence;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;

/**
 * Brand-level allow/deny read from the row HQ actually writes.
 *
 * The bound implementation until now was `DefaultAllowedPaymentOwnerOptionPolicySource`,
 * a placeholder that answered `Allowed` for every brand and every option. So the
 * HQ "Chính sách tùy chọn" screen wrote rows nothing downstream read: HQ could
 * set an option to `blocked` and every shop went on offering it, with no error
 * anywhere. That is the second half of #F3 — the first half was brand policy
 * landing in a real shop's row, this is brand policy being ignored once it lands
 * in the right one.
 *
 * Reads are deliberately forgiving where the WRITE side is strict:
 *
 *  - No headquarters branch → `Allowed`. Writing brand policy without a policy
 *    store is a fault and 409s at the write; a read cannot invent a denial that
 *    was never recorded, and hard-failing here would take POS, kiosk and
 *    workstation down over a configuration problem in an admin screen.
 *  - Only `blocked` denies. `disabled` means "off by default", which the
 *    resolver already models through the preference tiers — treating it as a
 *    denial would stop a shop from re-enabling what HQ merely defaulted off.
 *
 * Caching: the whole brand's blocked set is read in ONE query the first time
 * that brand is asked about, because the candidate loader calls `resolve()` once
 * per connection-option row and per-option queries would be N round-trips for a
 * single table render. The cache lives for the container scope (one request, one
 * queue job) and is NOT invalidated by a write in the same request — a caller
 * that writes brand policy and then re-reads it within one request must resolve
 * a fresh instance.
 */
final class EloquentPaymentOwnerOptionPolicySource implements PaymentOwnerOptionPolicySource
{
    /** @var array<string, list<string>> brandId => blocked option ids */
    private array $blockedByBrand = [];

    public function resolve(string $brandId, string $optionId): UpstreamPolicyState
    {
        $blocked = $this->blockedByBrand[$brandId] ??= $this->loadBlocked($brandId);

        return in_array($optionId, $blocked, true)
            ? UpstreamPolicyState::Denied
            : UpstreamPolicyState::Allowed;
    }

    /** @return list<string> */
    private function loadBlocked(string $brandId): array
    {
        $policyBranchId = $this->policyBranchId($brandId);
        if ($policyBranchId === null) {
            return [];
        }

        return ShopPaymentOption::query()
            ->where('branch_id', $policyBranchId)
            ->where('preference', PaymentPolicyPreferenceEnum::Blocked->value)
            ->pluck('option_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function policyBranchId(string $brandId): ?string
    {
        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            return null;
        }

        $id = Branch::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_headquarters', true)
            ->where('is_active', true)
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
