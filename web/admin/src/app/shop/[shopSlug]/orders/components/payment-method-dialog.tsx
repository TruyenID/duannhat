"use client";
import { useState } from "react";
import { useTranslation } from "@/providers/app-provider";
import type { CompleteOrderInput, PaymentMethod } from "@/services/order-service";
import {
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Spinner,
} from "@godxjp/ui";
const METHOD_VALUES: PaymentMethod[] = ["cash", "card", "transfer", "other"];
export interface PaymentMethodDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  isPending?: boolean;
  onSubmit: (data: CompleteOrderInput) => void;
}
export function PaymentMethodDialog({
  open,
  onOpenChange,
  isPending,
  onSubmit,
}: PaymentMethodDialogProps) {
  const { t } = useTranslation();
  const [method, setMethod] = useState<PaymentMethod>("cash");
  const [discount, setDiscount] = useState("");
  function handleSubmit() {
    onSubmit({
      payment_method: method,
      discount_amount: discount ? Number(discount) : undefined,
    });
  }
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="payment-method-dialog"
        aria-describedby={undefined}
        className="max-w-sm"
      >
        <DialogHeader>
          <DialogTitle>{t("shop.orders.payment_dialog.title")}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label>{t("shop.orders.payment_dialog.method_label")}</Label>
            <div className="grid grid-cols-2 gap-2">
              {METHOD_VALUES.map((value) => (
                <Button
                  key={value}
                  type="button"
                  variant={method === value ? "default" : "outline"}
                  size="sm"
                  onClick={() => setMethod(value)}
                >
                  {t(`shop.orders.payment.${value}`)}
                </Button>
              ))}
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="pmd-discount">{t("shop.orders.payment_dialog.discount_label")}</Label>
            <Input
              id="pmd-discount"
              type="number"
              min={0}
              value={discount}
              onChange={(e) => setDiscount(e.target.value)}
              placeholder="0"
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isPending}>
            {t("common.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={isPending}>
            {isPending && <Spinner className="mr-2 size-3.5" />}
            {t("shop.orders.payment_dialog.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
