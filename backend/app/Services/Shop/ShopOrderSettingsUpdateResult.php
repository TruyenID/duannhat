<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\ShopOrderSetting;

/**
 * #1687 — what {@see ShopOrderSettingsService::update()} hands back.
 *
 * Two values, and the second one is the reason this class exists. The
 * controller used to pass `&$serviceChargeTaxRateWarning` INTO the
 * `DB::transaction` closure by reference: the flag is computed inside the
 * transaction (where the pre-write value is read under the same lock as the
 * guards) but consumed after it, for the response `meta`. A by-reference
 * capture cannot survive the move to a service without leaking the writing
 * mechanism into the caller, so the transaction now returns BOTH values
 * together and the caller reads them off this object.
 *
 * The advisory is NOT a block — see #1129 in the service. The write already
 * happened by the time this object exists.
 */
final class ShopOrderSettingsUpdateResult
{
    public function __construct(
        public readonly ShopOrderSetting $setting,
        public readonly bool $serviceChargeTaxRateWarning,
    ) {}
}
