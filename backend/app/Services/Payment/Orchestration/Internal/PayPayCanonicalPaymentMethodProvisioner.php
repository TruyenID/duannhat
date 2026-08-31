<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\PaymentMethod;

/**
 * Ensures the org-scoped canonical PayPay PaymentMethod row exists.
 *
 * Provisioned at runtime rather than assumed from a seeder: `db:seed` never runs
 * on staging or production, so an org whose payment methods were never seeded
 * would have no row for the webhook to write against. Stripe carries the same
 * provisioner for the same reason.
 *
 * A dedicated `paypay` code rather than reusing the seeded `e_wallet` row:
 * `e_wallet` is mapped to the 電子マネー (tap-IC) tender category during shift
 * reconciliation, and customer-web PayPay is not that. Keeping its own code also
 * lets reporting tell an online wallet payment apart from a card tapped at the
 * counter, which no other column can express — `order_payments.tender_key` is
 * written but never read back by the money readers.
 */
final class PayPayCanonicalPaymentMethodProvisioner
{
    public const CODE = 'paypay';

    public function resolveForOrganization(string $organizationId): PaymentMethod
    {
        return PaymentMethod::updateOrCreate(
            [
                'code' => self::CODE,
                'organization_id' => $organizationId,
                'branch_id' => null,
            ],
            [
                'name:en' => 'PayPay',
                'name:ja' => 'PayPay',
                'name:vi' => 'PayPay',
                // Matches the seeded `e_wallet` row's type, which is the value
                // TenderKind::Qr resolves from — the authority port rejects a
                // prepare whose tender kind disagrees with the method type.
                'type' => 'qr',
                'is_active' => true,
                // The provider confirms the payment; there is no staff step.
                'is_auto_confirm' => true,
                'requires_tendered' => false,
                'sort_order' => 55,
            ]
        );
    }
}
