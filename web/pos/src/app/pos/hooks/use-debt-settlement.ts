/**
 * use-debt-settlement — records a "Thu nợ" against an open order.
 *
 * A settlement is an ordinary payment carrying `metadata.settles_payment_id`;
 * there is no dedicated collect endpoint. Three rules come from the backend and
 * are not negotiable from here:
 *
 *  - It posts against a LIVE order, never the order the debt sits on. That one
 *    closed the moment the debt was recorded, and `OrderPaymentService` refuses
 *    any payment on an order that is not `checkout`/`paying` — a guard inside
 *    the per-order lock and the till-session attribution block.
 *  - The order must belong to the SAME customer (`settles_wrong_customer`).
 *    DebtSearchDialog only offers the button when the active order matches, so
 *    the id handed in here has already satisfied it.
 *  - The amount is the debt's ORIGINAL figure, never its post-refund net: the
 *    backend compares against `orig->amount` and rejects
 *    `settles_amount_mismatch`. Debts where the two differ are marked
 *    un-settleable upstream and never reach this hook.
 *
 * Lives in a hook rather than in page.tsx because page.tsx is under a
 * only-ever-shrinks line budget (#1770) and this is state + handler, which the
 * convention puts here.
 */

import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { orderKeys } from "@/hooks/api/query-keys";
import { orderPaymentService } from "@/services/order-payment-service";
import type { DebtSettlementRequest } from "../components/debt-search-dialog";

export function useDebtSettlement(shopSlug: string) {
  const qc = useQueryClient();

  return useCallback(
    async (req: DebtSettlementRequest) => {
      await orderPaymentService.create(shopSlug, req.orderId, {
        payment_method_id: req.option.legacy_payment_method_id ?? "",
        amount: Number(req.debt.amount),
        ...(req.tenderedAmount !== undefined
          ? { tendered_amount: req.tenderedAmount }
          : {}),
        metadata: { settles_payment_id: req.debt.payment_id },
      });
      // The settlement lands on the ACTIVE order, so its totals and payment
      // list both moved. Without this the cart still shows pre-settlement
      // figures while the debt has already been collected.
      await qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
    },
    [qc, shopSlug],
  );
}
