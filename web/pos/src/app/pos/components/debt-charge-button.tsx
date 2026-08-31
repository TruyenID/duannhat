/**
 * DebtChargeButton — plan-038 T10.7+T10.8.
 *
 * Drop-in CTA for the payment surfaces. Two modes:
 *
 *   mode="full"      — "Ghi nợ toàn bộ" — single payment for the entire
 *                      remaining order amount. Shown on the main
 *                      PaymentDialog.
 *   mode="remaining" — "Ghi nợ phần còn lại" — second payment for what's
 *                      left after a partial cash-pay. Shown on the
 *                      PaymentReceiptDialog when remaining > 0.
 *
 * Both gate on `customerId` (Q-decision: debt requires a registered
 * customer; walk-in debt is rejected at the backend layer too with 422
 * `customer_required_for_debt`).
 *
 * On click: looks up the debt payment method (type==='on_account'), fires
 * onCreatePayment, and hands the new payment id back via `onCharged`.
 *
 * It resolves that method ITSELF, from `/pos/payment-methods`. It used to be
 * handed a `methods` prop that both call sites filled from
 * effective-payment-options — a list that structurally CANNOT contain
 * `on_account`, because on-account has no gateway catalog option at all (the
 * backend enricher says so in as many words). So `findDebtMethod` returned null
 * on every shop, the button sat permanently disabled behind a raw untranslated
 * tooltip, and "ghi nợ" was impossible. plan-047 T6.1 caused it by repointing
 * `paymentMethodService.list` at the new endpoint; owning the lookup here keeps
 * the CTA and its only possible source together.
 *
 * It does NOT print. Recording a debt and printing its slip are separate
 * decisions — the customer may not want paper, the printer may be unreachable,
 * and every print is written to print_history + the audit log. The parent shows
 * the result with an explicit "In phiếu nợ" action instead.
 */

import { useState } from "react";
import { Button } from "@godxjp/ui";
import { WalletIcon } from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import type { PaymentMethod } from "../types";
import { useShopPaymentMethods } from "@/hooks/api/use-shop-payment-methods";
// Type-only: payment-dialog imports this component, so a value import here
// would close a runtime cycle. `import type` is erased at compile time.
import type { PaymentBody } from "./payment-dialog";
import { Spinner } from "@/components/ui/spinner";

export interface DebtChargeButtonProps {
  mode: "full" | "remaining";
  /** Order id (drives auto-print after payment). */
  orderId: string | null;
  /** Order's customer_id — disable + warn when null. */
  customerId: string | null;
  /** Amount to charge to the customer's account (full or remaining). */
  amount: number;
  /** Shop slug — the component reads /pos/payment-methods itself. */
  shopSlug: string;
  /**
   * Test seam. Production leaves it unset and the component fetches; a test
   * can hand the rows in directly rather than standing up a query client.
   */
  methods?: PaymentMethod[];
  /** Same onCreatePayment as the rest of pos-web. Returns the created id.
   *  Debt rows carry no gateway option, so the optional plan-047 fields of
   *  `PaymentBody` stay unset here — see the note on that interface. */
  onCreatePayment: (body: PaymentBody) => Promise<{ id: string }>;
  /** Fired after the debt payment is created, with its id. The slip is NOT
   *  printed here — the parent decides, so the cashier gets an explicit
   *  print action instead of paper appearing unasked. */
  onCharged?: (paymentId: string) => void;
  disabled?: boolean;
}

function findDebtMethod(methods: PaymentMethod[]): PaymentMethod | null {
  // Try exact type match first; fall back to code==='debt' for older
  // installs that haven't yet pulled the type backfill.
  const byType = methods.find((m) => m.type === "on_account");
  if (byType) return byType;
  return methods.find((m) => m.code === "debt") ?? null;
}

function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `dbt-${Date.now().toString(36)}-${Math.random()
    .toString(36)
    .slice(2, 12)}`;
}

export function DebtChargeButton({
  mode,
  orderId,
  customerId,
  amount,
  shopSlug,
  methods,
  onCreatePayment,
  onCharged,
  disabled,
}: DebtChargeButtonProps) {
  const { t } = useTranslation();
  const [working, setWorking] = useState(false);
  const fetched = useShopPaymentMethods(methods ? "" : shopSlug);
  const debtMethod = findDebtMethod(methods ?? fetched.data ?? []);

  // No customer → button disabled with explanatory tooltip via title.
  const blockedNoCustomer = !customerId;
  const blockedNoMethod = !debtMethod;

  async function handleClick() {
    if (!orderId || !debtMethod) return;
    setWorking(true);
    try {
      const created = await onCreatePayment({
        payment_method_id: debtMethod.id,
        amount,
        idempotency_key: newIdempotencyKey(),
      });
      // Recording the debt and printing the slip are separate decisions —
      // the customer may not want a slip, and the printer may not even be
      // reachable. Hand the payment id back so the parent can show the
      // result with an explicit "In phiếu nợ" action, mirroring how
      // PaymentReceiptDialog already treats receipts.
      onCharged?.(created.id);
    } catch (err) {
      if (err instanceof ApiError && err.body) {
        const code = String(
          (err.body.errors as { payment_method_id?: string[] })?.payment_method_id?.[0] ??
            err.body.message ??
            "",
        );
        if (code.includes("customer_required_for_debt")) {
          toast.error(t("pos.payment.errors.customer_required_for_debt"));
          return;
        }
      }
      toast.error(t("pos.kitchen.fire_failed"));
    } finally {
      setWorking(false);
    }
  }

  const label =
    mode === "full"
      ? t("pos.debt.ghi_no_full")
      : t("pos.debt.ghi_no_remaining");

  const titleAttr = blockedNoCustomer
    ? t("pos.debt.no_customer_warning")
    : blockedNoMethod
      ? t("pos.debt.method_not_configured")
      : undefined;

  return (
    <Button
      type="button"
      variant="outline"
      onClick={handleClick}
      disabled={
        disabled || working || blockedNoCustomer || blockedNoMethod || amount <= 0
      }
      title={titleAttr}
      className="gap-1.5"
    >
      {working ? (
        <Spinner className="size-4" />
      ) : (
        <WalletIcon className="size-4" />
      )}
      {label}
    </Button>
  );
}

