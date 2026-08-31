import { useQueryClient } from "@tanstack/react-query";
import { customerKeys, orderKeys, orderPaymentKeys, tableKeys } from "@/hooks/api/query-keys";
import { orderPaymentService } from "@/services/order-payment-service";
import { workstationPrintService } from "@/services/workstation-print-service";
import type { PaymentBody } from "../components/payment-dialog";
import type { SplitPaymentBody } from "../components/split-bill-dialog";
import type { CustomerOrder } from "../types";

export interface UsePaymentActionsArgs {
  shopSlug: string;
  activeOrder: CustomerOrder | undefined;
}

export interface UsePaymentActionsResult {
  createPayment: (orderId: string, body: PaymentBody) => Promise<void>;
  createDebtPayment: (orderId: string, body: PaymentBody) => Promise<{ id: string }>;
  createSplitBillPayment: (body: SplitPaymentBody) => Promise<{ id: string }>;
  refundSplitBillPayment: (paymentId: string) => Promise<void>;
  /** `undefined` when no workstation is configured, so callers hide the button. */
  printSplitRowReceipt: ((paymentId: string) => Promise<void>) | undefined;
}

/**
 * Posting money against an order, and the cache invalidation that has to
 * follow it. No UI state and no tab lifecycle — those belong to the dialogs
 * that call these; errors propagate so a dialog can show a per-payment
 * failure on the row that caused it.
 */
export function usePaymentActions({
  shopSlug,
  activeOrder,
}: UsePaymentActionsArgs): UsePaymentActionsResult {
  const qc = useQueryClient();

  /**
   * Invalidate everything a new payment could have affected: the order
   * detail, the open-orders list, the tables (a close event releases
   * tables), the payments list for this order, and the outstanding-debt
   * cache (if we just paid — or created — a debt).
   */
  function invalidateAfterPayment(orderId: string) {
    qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
    qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
    qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    qc.invalidateQueries({
      queryKey: orderPaymentKeys.list(shopSlug, orderId),
    });
    if (activeOrder?.customer_id) {
      qc.invalidateQueries({
        queryKey: customerKeys.outstanding(shopSlug, activeOrder.customer_id),
      });
    }
  }

  /**
   * Fire a payment for ANY order id (current order or an old debt being
   * settled alongside). PaymentDialog orchestrates the sequence; this
   * handler just hits the backend + invalidates caches. Errors propagate
   * so the dialog can show per-payment failures.
   */
  async function createPayment(orderId: string, body: PaymentBody) {
    await orderPaymentService.create(shopSlug, orderId, body);
    invalidateAfterPayment(orderId);
  }

  /**
   * Twin of `createPayment` that hands the created payment id back to the
   * caller. DebtChargeButton needs it to print the PHIẾU GHI NỢ slip
   * against the row it just created.
   */
  async function createDebtPayment(
    orderId: string,
    body: PaymentBody,
  ): Promise<{ id: string }> {
    const response = await orderPaymentService.create(shopSlug, orderId, body);
    invalidateAfterPayment(orderId);
    return { id: response.data.id };
  }

  /**
   * Per-row payment fired from the SplitBillDialog. Same plumbing as
   * `createPayment` but always targets the active order — split-bill
   * never touches outstanding debt (that's a separate flow inside the
   * regular PaymentDialog), so the outstanding cache is left alone.
   */
  async function createSplitBillPayment(body: SplitPaymentBody): Promise<{ id: string }> {
    if (!activeOrder) {
      throw new Error("No active order to charge");
    }
    const response = await orderPaymentService.create(shopSlug, activeOrder.id, body);
    invalidateSplitBillCaches(activeOrder.id);
    return { id: response.data.id };
  }

  /**
   * Refund a single split-bill row's payment. The dialog keeps the row
   * but resets it back to idle so staff can re-collect from the right
   * customer. Cache invalidation here mirrors the create path so the
   * order's paid_amount + status (potentially reverted from `closed`
   * back to `paying` if this was the row that closed it) re-render
   * correctly while the dialog is still open.
   */
  async function refundSplitBillPayment(paymentId: string): Promise<void> {
    if (!activeOrder) return;
    await orderPaymentService.refund(shopSlug, activeOrder.id, paymentId, {
      // One split row can be rolled back only once. Deriving the key from the
      // immutable payment ID keeps an operator's explicit retry replay-safe.
      idempotency_key: `split-refund:${paymentId}`,
    });
    invalidateSplitBillCaches(activeOrder.id);
  }

  function invalidateSplitBillCaches(orderId: string) {
    qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
    qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
    qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    qc.invalidateQueries({
      queryKey: orderPaymentKeys.list(shopSlug, orderId),
    });
  }

  /**
   * C2: forward a per-row print request to the LAN workstation-app.
   * Stays `undefined` when no workstation is configured so the dialog
   * hides the print button entirely.
   */
  const printSplitRowReceipt = workstationPrintService.enabled
    ? async (paymentId: string) => {
        if (!activeOrder) return;
        await workstationPrintService.printPaymentReceipt({
          shopSlug,
          orderId: activeOrder.id,
          paymentId,
        });
      }
    : undefined;

  return {
    createPayment,
    createDebtPayment,
    createSplitBillPayment,
    refundSplitBillPayment,
    printSplitRowReceipt,
  };
}
