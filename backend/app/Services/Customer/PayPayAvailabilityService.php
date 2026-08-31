<?php

namespace App\Services\Customer;

use App\Models\Branch;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use Database\Seeders\PaymentGatewayCatalogSeeder;

/**
 * Whether customer-web may offer the PayPay QR flow for a given branch.
 *
 * Mirrors how Stripe is gated: `StripeConfigController` publishes the
 * publishable key and the checkout renders the card form only when it is
 * present. The PayPay radio likewise ALWAYS renders — a branch without PayPay
 * configured keeps today's behaviour, where `qr_pay` is a label the staff
 * settle by hand. This service only decides whether that label is upgraded to
 * the API flow, so switching PayPay off can never make a working button vanish.
 *
 * Fail-closed on purpose: everything here must be true, and each falsehood
 * names itself, because the failure this replaces was PayPay silently not
 * appearing with no error anywhere.
 */
final class PayPayAvailabilityService
{
    /** PayPay OPA settles in JPY only. */
    private const SUPPORTED_CURRENCY = 'JPY';

    /**
     * @return array{enabled: bool, reason: ?string}
     */
    public function forBranch(Branch $branch): array
    {
        if (! $this->credentialsConfigured()) {
            return ['enabled' => false, 'reason' => 'credentials_missing'];
        }

        // Checked here rather than left to the policy engine: the policy request
        // hardcodes 'JPY' regardless of the branch, so a VND branch would be told
        // PayPay is available and then have a VND figure submitted as yen.
        if (strtoupper((string) $branch->currency) !== self::SUPPORTED_CURRENCY) {
            return ['enabled' => false, 'reason' => 'currency_unsupported'];
        }

        if ($this->shopOptedOut($branch)) {
            return ['enabled' => false, 'reason' => 'disabled_for_shop'];
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * Honour the shop-level off switch (D9: the brand enables, a shop may opt out).
     *
     * Read here rather than left to the policy engine, because this path never
     * reaches it — `PayPayCustomerWebBootstrap` provisions directly, so the one
     * place `shop_payment_options` is consulted (the policy candidate loader) is
     * never on the PayPay path. Without this the row exists, an operator sets it,
     * and nothing changes: a switch wired to nothing is worse than no switch.
     *
     * Absent row means inherit, which means enabled — matching the policy
     * engine's own reading, where only Disabled and Blocked deny.
     */
    private function shopOptedOut(Branch $branch): bool
    {
        // An unsaved branch has no rows to opt out with. Guarding here keeps the
        // credential/currency rules testable without a database.
        if ($branch->id === null) {
            return false;
        }

        return ShopPaymentOption::query()
            ->where('branch_id', $branch->id)
            ->whereIn('preference', [
                PaymentPolicyPreferenceEnum::Disabled->value,
                PaymentPolicyPreferenceEnum::Blocked->value,
            ])
            ->whereHas('option', fn ($query) => $query->where('code', PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE))
            ->exists();
    }

    /**
     * Merchant id is required alongside the key pair: it is the assume-merchant
     * identity every OPA call carries, so a half-configured deployment must not
     * advertise PayPay.
     */
    private function credentialsConfigured(): bool
    {
        foreach (['api_key', 'api_secret', 'merchant_id'] as $key) {
            if (trim((string) config("services.paypay.{$key}")) === '') {
                return false;
            }
        }

        return true;
    }
}
