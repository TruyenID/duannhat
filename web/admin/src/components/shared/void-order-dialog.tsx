"use client";

import { useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrderStatus } from "@/services/order-service";

/** Backend `void_reason` validation limit (`max:500`). */
const VOID_REASON_MAX = 500;

/**
 * Mirrors the backend void guard: a settled bill can't be voided, and the two
 * terminal states are already voided. The collected-payment guard stays
 * server-side — it depends on the payment ledger the list pages don't load, so
 * it surfaces as a 409 toast telling staff to refund first.
 */
export function canVoidOrder(status: CustomerOrderStatus): boolean {
  return status !== "closed" && status !== "voided" && status !== "expired";
}

export interface VoidOrderDialogProps {
  /** The order being voided; `null` keeps the dialog closed. */
  orderCode: string | null;
  onOpenChange: (open: boolean) => void;
  onConfirm: (voidReason: string) => void;
  isPending?: boolean;
}

/**
 * Reason-capture dialog for voiding an order. Shared by the HQ and shop order
 * lists — the reason is mandatory on both, so the confirm button stays disabled
 * until one is typed rather than letting staff submit into a 422.
 */
export function VoidOrderDialog(props: VoidOrderDialogProps) {
  // Remount per target order so the reason box starts empty every time — a
  // reason typed for one order must never be submitted against another. Keying
  // the state to the order beats resetting it from an effect, which would
  // trigger a cascading render.
  return <VoidOrderDialogBody key={props.orderCode ?? "closed"} {...props} />;
}

function VoidOrderDialogBody({
  orderCode,
  onOpenChange,
  onConfirm,
  isPending = false,
}: VoidOrderDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");

  return (
    <Dialog open={orderCode !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md" data-slot="void-order-dialog">
        <DialogHeader>
          <DialogTitle>{t("common.confirm")}</DialogTitle>
          <DialogDescription>
            {orderCode
              ? t("shop.orders.void_confirm_code", { code: orderCode })
              : t("shop.orders.cancel_confirm")}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2">
          <label htmlFor="void-order-reason" className="text-xs text-muted-foreground">
            {t("shop.orders.cancel_reason_label")}
          </label>
          <Textarea
            id="void-order-reason"
            rows={3}
            maxLength={VOID_REASON_MAX}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={t("shop.orders.cancel_reason_placeholder")}
            className="field-sizing-fixed"
          />
          <p className="text-right text-xs text-muted-foreground">
            {reason.length}/{VOID_REASON_MAX}
          </p>
        </div>
        <DialogFooter>
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button
            variant="destructive"
            size="sm"
            onClick={() => onConfirm(reason.trim())}
            disabled={isPending || !reason.trim()}
          >
            {isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
