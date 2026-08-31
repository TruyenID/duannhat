<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\PaymentMethod;

/** Ensures the org-scoped canonical Stripe PaymentMethod row exists. */
final class StripeCanonicalPaymentMethodProvisioner
{
    public function resolveForOrganization(string $organizationId): PaymentMethod
    {
        return PaymentMethod::updateOrCreate(
            [
                'code' => 'stripe',
                'organization_id' => $organizationId,
                'branch_id' => null,
            ],
            [
                'name:en' => 'Stripe',
                'name:ja' => 'Stripe',
                'name:vi' => 'Stripe',
                'type' => 'card',
                'is_active' => true,
                'is_auto_confirm' => true,
                'requires_tendered' => false,
                'sort_order' => 50,
            ]
        );
    }
}
