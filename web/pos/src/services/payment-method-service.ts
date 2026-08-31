/**
 * Effective payment options — resolver-backed list for POS PaymentDialog.
 *
 *   GET /api/v1/pos/effective-payment-options
 *
 * Returns the shop/device effective policy snapshot. POS renders only options
 * where `effective && client.supports_pos_checkout`.
 */

import { apiFetch } from "@/lib/api";
import type { EffectivePaymentOptionsEnvelope, PaymentMethod } from "@/app/pos/types";

export const effectivePaymentOptionsService = {
  list: (_shopSlug: string) =>
    apiFetch<{ data: EffectivePaymentOptionsEnvelope }>(
      "/api/v1/pos/effective-payment-options",
    ),
};

/**
 * The shop's raw `payment_methods` rows.
 *
 *   GET /api/v1/pos/payment-methods
 *
 * Not a duplicate of effective-payment-options, and not deprecated in the way
 * the alias below suggests. The two answer different questions:
 *
 *   effective-payment-options — what may this terminal CHARGE with, after
 *                               gateway policy. Built from the gateway catalog.
 *   payment-methods           — the rows `order_payments.payment_method_id`
 *                               actually points at.
 *
 * `on_account` (掛売 / ghi nợ) exists only in the second. It has no gateway, no
 * connection and no catalog option — the backend says so itself in
 * PosEffectivePaymentOptionEnricher: *"On-account has NO catalog option at all,
 * internal or otherwise"*. So a debt CTA that resolves its method out of the
 * effective-options list is searching a list that structurally cannot contain
 * it, and the button stays disabled forever. That is exactly what happened when
 * plan-047 (T6.1) repointed `paymentMethodService.list` at the new endpoint.
 */
export const posPaymentMethodService = {
  list: () => apiFetch<{ data: PaymentMethod[] }>("/api/v1/pos/payment-methods"),
};

/** @deprecated Use effectivePaymentOptionsService — kept for test import stability. */
export const paymentMethodService = {
  list: (shopSlug: string) => effectivePaymentOptionsService.list(shopSlug),
};
