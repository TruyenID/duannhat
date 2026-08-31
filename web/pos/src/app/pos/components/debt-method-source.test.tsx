/**
 * The debt CTA must read `/pos/payment-methods`, and nothing else.
 *
 * `on_account` (掛売 / ghi nợ) exists ONLY in `payment_methods`. It has no
 * gateway, no connection and no catalog option — the backend enricher says so
 * itself: *"On-account has NO catalog option at all, internal or otherwise"*.
 *
 * So a debt CTA that resolves its method out of `effective-payment-options` is
 * searching a list that structurally cannot contain it. That is not a bug that
 * shows up as an error: `findDebtMethod` returns null, the button renders
 * DISABLED, and recording a debt is simply impossible — silently, on every
 * shop. plan-047 T6.1 caused it by repointing `paymentMethodService.list` at
 * the new endpoint, and it stayed that way because every test handed the CTA a
 * `methods` array containing a debt row by hand.
 *
 * These start from the real hook.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";

const methodsHook = vi.hoisted(() => ({ current: [] as unknown[] }));
vi.mock("@/hooks/api/use-shop-payment-methods", () => ({
  useShopPaymentMethods: () => ({ data: methodsHook.current }),
}));

vi.mock("@/providers/app-provider", () => ({
  useTranslation: () => ({ t: (k: string) => k, locale: "vi" }),
  // Same shape as useTranslation — the help `?` inside the dialog reads it.
  useOptionalTranslation: () => ({ t: (k: string) => k, locale: "vi" }),
}));

import { DebtChargeButton } from "./debt-charge-button";
import type { PaymentMethod } from "../types";

const CASH = {
  id: "pm-cash",
  code: "cash",
  name: "Tiền mặt",
  type: "cash",
  is_active: true,
} as unknown as PaymentMethod;

const DEBT = {
  id: "pm-debt",
  code: "debt",
  name: "Ghi nợ",
  type: "on_account",
  is_active: true,
} as unknown as PaymentMethod;

function renderCta(over: Record<string, unknown> = {}) {
  const onCreatePayment = vi.fn().mockResolvedValue({ id: "pay-new" });
  render(
    <DebtChargeButton
      mode="full"
      orderId="ord-1"
      customerId="cus-1"
      amount={2496}
      shopSlug="ningyocho"
      onCreatePayment={onCreatePayment}
      {...over}
    />,
  );
  return { onCreatePayment };
}

beforeEach(() => {
  vi.clearAllMocks();
  methodsHook.current = [CASH, DEBT];
});

describe("DebtChargeButton — where the on_account method comes from", () => {
  it("is ENABLED when /pos/payment-methods carries an on_account row", () => {
    renderCta();

    expect(screen.getByRole("button")).toBeEnabled();
  });

  it("posts that row's id, not some cash lookalike", async () => {
    const { onCreatePayment } = renderCta();

    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(onCreatePayment.mock.calls[0][0].payment_method_id).toBe("pm-debt");
    expect(onCreatePayment.mock.calls[0][0].amount).toBe(2496);
  });

  it("is DISABLED — with a translated reason — when the list has no on_account row", () => {
    // This is the state every shop was actually in: the effective-options list
    // holds cash and card_terminal and can hold nothing else, so the CTA had
    // nothing to find. It must not merely be inert; it must say why.
    methodsHook.current = [CASH];
    renderCta();

    const button = screen.getByRole("button");
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute("title", "pos.debt.method_not_configured");
  });

  it("falls back to code==='debt' for installs predating the type backfill", () => {
    methodsHook.current = [CASH, { ...DEBT, type: "other" }];
    renderCta();

    expect(screen.getByRole("button")).toBeEnabled();
  });

  it("stays disabled without a customer — debt needs one, and the backend agrees", () => {
    // `customer_required_for_debt` is a 422 on the server. Offering the button
    // would mean discovering that at the counter.
    renderCta({ customerId: null });

    expect(screen.getByRole("button")).toBeDisabled();
  });
});
