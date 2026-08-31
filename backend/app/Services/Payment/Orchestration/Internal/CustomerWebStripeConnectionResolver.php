<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\Branch;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
/**
 * Plan-048 Gate 2 (T2.1/T2.2) — resolve the Stripe connection/option identity
 * for a customer-web order through the real payment policy (HQ vs franchise
 * ownership included), instead of the synthetic per-org connection the
 * orchestration bootstrap fabricates.
 *
 * Transition contract: when the branch has no policy-backed effective Stripe
 * option (no HQ/franchise connection configured yet, unresolved ownership, or
 * no published policy revision), this falls back to the legacy bootstrap so
 * live card acceptance never regresses mid-migration. Every fallback is
 * logged on the `payment_orchestration` channel so the Gate 2 cutover can be
 * proven by the absence of `customer_web_stripe_bootstrap_fallback` entries.
 */
use Illuminate\Support\Facades\Log;

final class CustomerWebStripeConnectionResolver
{
    public function __construct(
        private readonly PaymentPolicyEvaluationService $evaluation,
        private readonly PaymentGatewayOrchestrationBootstrap $bootstrap,
    ) {}

    /**
     * plan-054 T3.6 — **Stripe only, permanently. Never call this for PayPay.**
     *
     * This does not return null when a branch has no policy. It falls back to
     * `PaymentGatewayOrchestrationBootstrap::resolveStripeCustomerWebForOrder()`,
     * which *creates* the `stripe` provider, the Stripe catalog option and an
     * org connection if they are missing, then hands back their ids. So a
     * non-Stripe caller gets no error at all — it gets a perfectly valid set of
     * Stripe ids stamped onto its attempt, and the mismatch only surfaces later
     * as webhooks that no longer match on `(connection_id, provider_object_id)`.
     *
     * PayPay has its own funnel: `PayPayCustomerWebBootstrap::resolveForOrder()`.
     * Same method shape, same return shape, different provider — which is
     * exactly why picking the wrong one is cheap and silent. Pinned by
     * `tests/Feature/Payment/Plan054StripeResolverIsStripeOnlyTest.php`.
     *
     * @return array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}
     */
    public function resolveForOrder(OrderRef $order): array
    {
        $resolved = $this->resolveFromPolicy($order);
        if ($resolved !== null) {
            return $resolved;
        }

        Log::channel('payment_orchestration')->info('customer_web_stripe_bootstrap_fallback', [
            'order_id' => (string) $order->orderId,
            'branch_id' => (string) $order->branchId,
            'organization_id' => (string) $order->organizationId,
        ]);

        return $this->bootstrap->resolveStripeCustomerWebForOrder($order);
    }

    /**
     * @return array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}|null
     */
    private function resolveFromPolicy(OrderRef $order): ?array
    {
        $branch = Branch::query()->find($order->branchId);
        if ($branch === null) {
            return null;
        }

        return $this->resolveForBranch($branch, (string) $order->orderId);
    }

    /**
     * Plan-048 T2.5 — the same policy walk, anchored on the branch alone so the
     * public payment-context endpoint can tell customer-web which revision +
     * option it is about to pay under (the client echoes the pair back on the
     * intent call for drift logging). Returns null when the branch has no
     * policy-backed effective Stripe option — callers decide their own
     * fallback; this method never falls back to the bootstrap.
     *
     * @return array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}|null
     */
    public function resolveForBranch(Branch $branch, ?string $orderId = null): ?array
    {
        try {
            $evaluation = $this->evaluation->effectiveOptions(
                $branch,
                null,
                PaymentChannelEnum::CustomerWeb,
            );
        } catch (\Throwable $exception) {
            Log::channel('payment_orchestration')->warning('customer_web_stripe_policy_resolution_failed', [
                'order_id' => $orderId,
                'branch_id' => (string) $branch->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $revision = (int) ($evaluation['revision'] ?? 0);
        if ($revision < 1) {
            return null;
        }

        foreach ($evaluation['options'] ?? [] as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (($option['effective'] ?? false) !== true
                || ($option['provider'] ?? null) !== PaymentGatewayProviderCodeEnum::Stripe->value) {
                continue;
            }

            $connectionId = $option['connection_id'] ?? null;
            $connectionOptionId = $option['connection_option_id'] ?? null;
            $optionId = $option['id'] ?? null;
            if (! is_string($connectionId) || ! is_string($connectionOptionId) || ! is_string($optionId)) {
                continue;
            }

            return [
                'connectionId' => $connectionId,
                'connectionOptionId' => $connectionOptionId,
                'optionId' => $optionId,
                'policyRevision' => $revision,
            ];
        }

        return null;
    }
}
