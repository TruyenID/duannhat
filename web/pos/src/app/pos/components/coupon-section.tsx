"use client";

/**
 * CouponSection — POS payment-dialog coupon apply / release subsection
 * (plan-019 S5).
 *
 * Rendered inside the payment dialog body when an order is loaded. Shows
 * either a code input + Apply button (when no coupon attached) or a chip
 * with the applied code + Remove (when one is). Both flows surface server
 * 422 error codes via i18n keys (`coupon.error.*`).
 */

import { useState } from "react";
import { TicketIcon, XIcon } from "lucide-react";
import { Alert, AlertDescription, Badge, Button, Input, Spinner } from "@godxjp/ui";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { orderService } from "@/services/order-service";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrder } from "@/app/pos/types";

interface CouponSectionProps {
  shopSlug: string;
  order: CustomerOrder;
  /**
   * Called after a successful apply or release so the parent dialog can
   * refresh its outstanding totals + remaining_amount banner.
   */
  onChange: (next: CustomerOrder) => void;
  disabled?: boolean;
}

export function CouponSection({ shopSlug, order, onChange, disabled }: CouponSectionProps) {
  const { t } = useTranslation();
  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [errorCode, setErrorCode] = useState<string | null>(null);
  const [errorMeta, setErrorMeta] = useState<Record<string, unknown> | null>(null);

  const applied = !!order.coupon_id;

  async function handleApply() {
    if (!code.trim()) return;
    setPending(true);
    setErrorCode(null);
    setErrorMeta(null);
    try {
      const res = await orderService.applyCoupon(shopSlug, order.id, {
        code: code.trim().toUpperCase(),
        customer_id: order.customer_id,
      });
      onChange(res.data);
      setCode("");
      toast.success(t("pos.coupon.applied_toast"));
    } catch (err) {
      if (err instanceof ApiError) {
        const body = err.body as { error_code?: string; meta?: Record<string, unknown>; message?: string };
        if (body.error_code) {
          setErrorCode(body.error_code);
          setErrorMeta(body.meta ?? null);
        } else {
          toast.error(body.message ?? err.message);
        }
      } else {
        toast.error((err as Error).message);
      }
    } finally {
      setPending(false);
    }
  }

  async function handleRelease() {
    setPending(true);
    setErrorCode(null);
    try {
      const res = await orderService.releaseCoupon(shopSlug, order.id);
      onChange(res.data);
      toast.success(t("pos.coupon.released_toast"));
    } catch (err) {
      toast.error((err as Error).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <div data-slot="coupon-section" className="rounded-xl border bg-muted/20 p-3">
      <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase text-muted-foreground">
        <TicketIcon className="size-3.5" /> {t("pos.coupon.section_title")}
      </div>

      {applied ? (
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <Badge variant="default" className="font-mono">
              {order.coupon_code_snapshot ?? "?"}
            </Badge>
            <span className="text-xs text-muted-foreground">
              −{Number(order.discount_amount).toLocaleString()}
            </span>
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7"
            onClick={handleRelease}
            disabled={pending || disabled}
            aria-label={t("pos.coupon.remove")}
          >
            {pending ? <Spinner className="size-3.5" /> : <XIcon className="size-3.5" />}
          </Button>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <Input
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            placeholder={t("pos.coupon.placeholder")}
            className="h-8 font-mono uppercase"
            maxLength={50}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                handleApply();
              }
            }}
            disabled={pending || disabled}
          />
          <Button
            type="button"
            size="sm"
            className="h-8 shrink-0"
            onClick={handleApply}
            disabled={!code.trim() || pending || disabled}
          >
            {pending && <Spinner className="mr-1.5 size-3.5" />}
            {t("pos.coupon.apply")}
          </Button>
        </div>
      )}

      {errorCode && (
        <Alert variant="destructive" className="mt-2 text-xs">
          <AlertDescription>
            {t(`coupon.error.${errorCode}`) || t("pos.coupon.error_generic")}
            {errorCode === "coupon_excluded_by_active_promotion" &&
              Array.isArray(errorMeta?.exclusive_item_names) && (
                <ul className="mt-1 list-disc pl-4">
                  {(errorMeta?.exclusive_item_names as string[]).map((n) => (
                    <li key={n}>{n}</li>
                  ))}
                </ul>
              )}
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}
