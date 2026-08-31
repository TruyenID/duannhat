<?php

namespace App\Services\Payment\Policy\Admin;

use App\Models\Branch;
use App\Models\PaymentGatewayOption;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Customer\PayPayAvailabilityService;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The shop-level PayPay off switch (plan-054 D9 / T5.6).
 *
 * D9: the brand enables PayPay for every branch it owns, and an individual
 * shop may opt out. `PayPayAvailabilityService::shopOptedOut()` honours that
 * by reading `shop_payment_options`, but nothing could WRITE the row from a
 * screen — plan-047's generic shop payment-policy API has no UI consumer, and
 * would not have listed the PayPay QR option anyway (see `stateFor()`).
 *
 * This class owns no writes of its own on purpose. `shop_payment_options` and
 * `payment_gateway_options` are guarded tables (`config/domain-mutation-guard`
 * → aggregate `payment`), so the row is written by
 * `PaymentPolicyEvaluationService` and the catalog identity by
 * `PayPayCustomerWebBootstrap` — both already registered boundaries. Adding a
 * second writer would have meant a third place that can disagree about what a
 * shop preference means.
 */
final class PayPayShopSwitchService
{
    /**
     * The only two preferences a shop may express.
     *
     * `PaymentPolicyPreferenceEnum` has four cases, but `Enabled` and
     * `Blocked` are refused by `PaymentPolicyEvaluationService::updateShopOption()`
     * — a shop can never widen past what the brand granted, and `Blocked` is
     * the upstream's word, not the shop's. Presenting four states in the UI
     * would offer two the API rejects.
     *
     * @var list<string>
     */
    public const SHOP_PREFERENCES = ['inherit', 'disabled'];

    public function __construct(
        private readonly PayPayAvailabilityService $availability,
        private readonly PayPayCustomerWebBootstrap $bootstrap,
        private readonly PaymentPolicyEvaluationService $policy,
    ) {}

    /**
     * @return array{
     *     preference: string,
     *     available_preferences: list<string>,
     *     effective_enabled: bool,
     *     brand_enabled: bool,
     *     reason: ?string,
     * }
     */
    public function stateFor(Branch $shop): array
    {
        $availability = $this->availability->forBranch($shop);

        // `credentials_missing` and `currency_unsupported` are the two reasons
        // the shop cannot do anything about. Reporting them separately from
        // `effective_enabled` keeps the screen honest: the switch stays usable
        // (the intent is durable and applies the day the brand is ready) while
        // the operator is told plainly that flipping it changes nothing today.
        $brandEnabled = ! in_array(
            $availability['reason'],
            ['credentials_missing', 'currency_unsupported'],
            true,
        );

        return [
            'preference' => $this->currentPreference($shop)->value,
            'available_preferences' => self::SHOP_PREFERENCES,
            'effective_enabled' => $availability['enabled'],
            'brand_enabled' => $brandEnabled,
            'reason' => $availability['reason'],
        ];
    }

    /**
     * @return array{
     *     preference: string,
     *     available_preferences: list<string>,
     *     effective_enabled: bool,
     *     brand_enabled: bool,
     *     reason: ?string,
     * }
     *
     * @throws ValidationException
     */
    public function setPreference(Branch $shop, string $preference): array
    {
        if (! in_array($preference, self::SHOP_PREFERENCES, true)) {
            throw ValidationException::withMessages([
                'preference' => ['The preference must be one of: '.implode(', ', self::SHOP_PREFERENCES).'.'],
            ]);
        }

        $option = $this->catalogOption();

        // Nothing to inherit back to when the capability has never been
        // provisioned: skip rather than mint catalog rows to express "no
        // opinion", which is what their absence already says.
        if ($option === null && $preference === PaymentPolicyPreferenceEnum::Inherit->value) {
            return $this->stateFor($shop);
        }

        $option ??= $this->bootstrap->ensureCatalogIdentity();

        $this->policy->updateShopOption($shop, $option, ['preference' => $preference]);

        return $this->stateFor($shop);
    }

    private function currentPreference(Branch $shop): PaymentPolicyPreferenceEnum
    {
        $option = $this->catalogOption();
        if ($option === null || $shop->id === null) {
            return PaymentPolicyPreferenceEnum::Inherit;
        }

        $row = ShopPaymentOption::query()
            ->where('branch_id', $shop->id)
            ->where('option_id', $option->id)
            ->first();

        // Absent row = inherit, matching both the policy resolver and
        // `PayPayAvailabilityService::shopOptedOut()`.
        if ($row === null) {
            return PaymentPolicyPreferenceEnum::Inherit;
        }

        $stored = $row->preference instanceof PaymentPolicyPreferenceEnum
            ? $row->preference
            : PaymentPolicyPreferenceEnum::tryFrom((string) $row->preference);

        return $stored ?? PaymentPolicyPreferenceEnum::Inherit;
    }

    private function catalogOption(): ?PaymentGatewayOption
    {
        return PaymentGatewayOption::query()
            ->where('code', PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE)
            ->first();
    }
}
