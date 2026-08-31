"use client";
import { useState } from "react";
import { notFound, useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useHqOrder, useHqVoidOrder } from "@/hooks/api/use-orders";
import {
  Button,
  Card,
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
import { OrderLineItems } from "@/components/shared/order-line-items";
import { OrderChargeSummary } from "@/components/shared/order-charge-summary";
import { OrderStatusBadge } from "@/components/shared/order-status-badge";
export default function HqOrderDetailPage() {
  const { brandSlug, id } = useParams<{ brandSlug: string; id: string }>();
  const { t } = useTranslation();
  const { data, isLoading, error } = useHqOrder(brandSlug, id);
  const voidMut = useHqVoidOrder(brandSlug);
  const [confirmVoid, setConfirmVoid] = useState(false);
  const [voidReason, setVoidReason] = useState("");
  const order = data?.data;
  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). Runs
  // before the loading guard so a missing id resolves to a proper 404 instead
  // of an inline "not found" line (TC-ORD-DET2).
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }
  if (isLoading) {
    return (
      <>
        <PageHeader
          title={t("hq.orders.detail.title_fallback")}
          backHref={`/hq/${brandSlug}/orders`}
        />
        <PageContent>
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
          </div>
        </PageContent>
      </>
    );
  }
  if (error || !order) {
    return (
      <>
        <PageHeader
          title={t("hq.orders.detail.title_fallback")}
          backHref={`/hq/${brandSlug}/orders`}
        />
        <PageContent>
          <div className="py-12 text-center text-sm text-muted-foreground">
            {t("hq.orders.detail.not_found")}
          </div>
        </PageContent>
      </>
    );
  }
  // Mirrors the backend void guard: anything but a settled or already-terminal
  // bill can be voided from HQ. The collected-payment guard stays server-side
  // and surfaces as a 409 toast telling staff to refund first.
  const canVoid =
    order.status !== "closed" && order.status !== "voided" && order.status !== "expired";
  return (
    <>
      <PageHeader title={order.order_code} backHref={`/hq/${brandSlug}/orders`}>
        {canVoid && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setConfirmVoid(true)}
            disabled={voidMut.isPending}
          >
            {t("common.cancel")}
          </Button>
        )}
      </PageHeader>
      <PageContent>
        <div className="max-w-2xl space-y-4">
          <Card className="p-4">
            <div className="mb-4 flex items-center gap-3">
              <OrderStatusBadge status={order.status} />
              <span className="text-sm text-muted-foreground">
                {order.order_type === "dine_in"
                  ? t("hq.orders.type.dine_in")
                  : t("hq.orders.type.takeaway")}
              </span>
            </div>
            <dl className="grid grid-cols-[140px_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">{t("hq.orders.detail.customer")}</dt>
              <dd>
                {order.customer
                  ? [order.customer.first_name, order.customer.last_name].filter(Boolean).join(" ")
                  : order.customer_takeaway_name || t("hq.orders.detail.walk_in")}
              </dd>
              {order.note && (
                <>
                  <dt className="text-muted-foreground">{t("hq.orders.detail.note")}</dt>
                  <dd>{order.note}</dd>
                </>
              )}
            </dl>
            <div className="mt-3 border-t pt-3">
              <OrderChargeSummary order={order} />
            </div>
          </Card>
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold">{t("hq.orders.detail.items")}</h2>
            <OrderLineItems
              items={order.items ?? []}
              currencyCode={order.currency}
              emptyLabel={t("hq.orders.detail.no_items")}
            />
          </Card>
        </div>
      </PageContent>
      <Dialog
        open={confirmVoid}
        onOpenChange={(open) => {
          setConfirmVoid(open);
          if (!open) setVoidReason("");
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("common.confirm")}</DialogTitle>
            <DialogDescription>{t("shop.orders.cancel_confirm")}</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <label htmlFor="hq-void-reason" className="text-xs text-muted-foreground">
              {t("shop.orders.cancel_reason_label")}
            </label>
            <Textarea
              id="hq-void-reason"
              rows={3}
              maxLength={500}
              value={voidReason}
              onChange={(e) => setVoidReason(e.target.value)}
              placeholder={t("shop.orders.cancel_reason_placeholder")}
              className="field-sizing-fixed"
            />
            <p className="text-right text-xs text-muted-foreground">{voidReason.length}/500</p>
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setConfirmVoid(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => {
                setConfirmVoid(false);
                voidMut.mutate(
                  { id, voidReason: voidReason.trim() },
                  { onSuccess: () => setVoidReason("") }
                );
              }}
              // The backend requires a reason, so block the round trip rather
              // than let staff submit into a 422.
              disabled={voidMut.isPending || !voidReason.trim()}
            >
              {voidMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
