"use client";
import { useState } from "react";
import { useParams } from "next/navigation";
import { QRCodeSVG } from "qrcode.react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useOrder,
  useConfirmOrder,
  useCompleteOrder,
  useVoidOrder,
} from "@/hooks/api/use-orders";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { CustomerOrderStatus } from "@/services/order-service";
import { OrderLineItems } from "@/components/shared/order-line-items";
import { OrderChargeSummary } from "@/components/shared/order-charge-summary";
import { OrderItemStatusBadge } from "../components/order-item-status-badge";
import { OrderStatusBadge } from "@/components/shared/order-status-badge";
import { PaymentMethodDialog } from "../components/payment-method-dialog";
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
export default function OrderDetailPage() {
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  // Poll: a dine-in order is closed by the kiosk/customer-web when fully paid,
  // so admin-web's cache never invalidates on its own — without polling the
  // status stays stale (never flips to "Hoàn thành") until a manual refresh.
  const {
    data: orderData,
    isLoading,
    error,
  } = useOrder(shopSlug, id, {
    refetchInterval: 15_000,
  });
  const confirmMut = useConfirmOrder(shopSlug);
  const completeMut = useCompleteOrder(shopSlug);
  const cancelMut = useVoidOrder(shopSlug);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [cancelReason, setCancelReason] = useState("");
  const order = orderData?.data;
  if (isLoading) {
    return (
      <>
        <PageHeader title={t("shop.orders.detail_title")} backHref={`/shop/${shopSlug}/orders`} />
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
        <PageHeader title={t("shop.orders.detail_title")} backHref={`/shop/${shopSlug}/orders`} />
        <PageContent>
          <div className="py-12 text-center text-sm text-muted-foreground">
            {t("shop.orders.not_found")}
          </div>
        </PageContent>
      </>
    );
  }
  const status: CustomerOrderStatus = order.status;
  const canConfirm = status === "pending";
  const canComplete = status === "open";
  // Mirrors the backend void guard: anything but a settled bill can be voided,
  // including a served order — staff just have to say why. Already-voided and
  // expired orders are terminal, so the button would be a no-op there. The
  // collected-payment guard stays server-side: it depends on the payment ledger
  // this screen doesn't load, and surfaces as a 409 toast telling staff to
  // refund first.
  const canCancel = status !== "closed" && status !== "voided" && status !== "expired";
  const isReadOnly = status === "closed" || status === "voided";
  // Email may arrive on the linked customer (logged-in) or on the guest
  // takeaway fields. The list-row `CustomerRef` has no `email`, so narrow the
  // union before reading it, then fall back to the guest email.
  const customerEmail =
    (order.customer && "email" in order.customer ? order.customer.email : null) ??
    order.customer_takeaway_email;
  // Pickup time: prefer the customer's scheduled slot, then fall back to the
  // kitchen ready-time. `immediate` takeaway orders (and legacy rows created
  // before the backend defaulted the slot) leave `scheduled_pickup_time` null,
  // so without this fallback the row would read "not set" even though the order
  // has a ready-time — which is exactly what customer-web shows the guest.
  const pickupTime =
    order.scheduled_pickup_time ?? order.estimated_ready_time ?? order.actual_ready_time;
  return (
    <>
      <PageHeader title={order.order_code} backHref={`/shop/${shopSlug}/orders`}>
        {canConfirm && (
          <Button size="sm" onClick={() => confirmMut.mutate(id)} disabled={confirmMut.isPending}>
            {confirmMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("shop.orders.confirm")}
          </Button>
        )}
        {canComplete && (
          <Button size="sm" onClick={() => setPaymentOpen(true)}>
            {t("shop.orders.complete")}
          </Button>
        )}
        {canCancel && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setConfirmCancel(true)}
            disabled={cancelMut.isPending}
          >
            {t("common.cancel")}
          </Button>
        )}
      </PageHeader>
      <PageContent>
        <div className="max-w-2xl space-y-4">
          <Card className="p-4">
            <div className="mb-4 flex items-center gap-3">
              <OrderStatusBadge status={status} />
              <span className="text-sm text-muted-foreground">
                {t(`shop.orders.type.${order.order_type}`)}
              </span>
            </div>
            <dl className="grid grid-cols-[140px_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">{t("shop.orders.col.customer")}</dt>
              <dd>
                {order.customer
                  ? [order.customer.first_name, order.customer.last_name].filter(Boolean).join(" ")
                  : order.customer_takeaway_name || t("common.walk_in")}
              </dd>
              {!order.customer && order.customer_takeaway_phone && (
                <>
                  <dt className="text-muted-foreground">{t("shop.orders.field.takeaway_phone")}</dt>
                  <dd>{order.customer_takeaway_phone}</dd>
                </>
              )}
              {customerEmail && (
                <>
                  <dt className="text-muted-foreground">{t("shop.orders.field.email")}</dt>
                  <dd>{customerEmail}</dd>
                </>
              )}
              <dt className="text-muted-foreground">{t("shop.orders.field.ordered_at")}</dt>
              <dd>{formatDateTime(order.created_at, locale, timezone)}</dd>
              {order.order_type === "takeaway" && (
                <>
                  <dt className="text-muted-foreground">{t("shop.orders.field.pickup_time")}</dt>
                  <dd>
                    {pickupTime ? formatDateTime(pickupTime, locale, timezone) : t("common.not_set")}
                  </dd>
                </>
              )}
              {order.tables && order.tables.length > 0 && (
                <>
                  <dt className="text-muted-foreground">{t("shop.orders.field.table")}</dt>
                  <dd>{order.tables.map((tb) => tb.code).join(", ")}</dd>
                </>
              )}
              {order.note && (
                <>
                  <dt className="text-muted-foreground">{t("shop.orders.field.note")}</dt>
                  <dd>{order.note}</dd>
                </>
              )}
            </dl>
            <div className="mt-3 border-t pt-3">
              <OrderChargeSummary order={order} />
            </div>
          </Card>
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold">{t("shop.orders.items")}</h2>
            <OrderLineItems
              items={order.items ?? []}
              statusSlot={(item) => <OrderItemStatusBadge status={item.status} />}
              currencyCode={order.currency}
              emptyLabel={t("shop.orders.no_items")}
            />
          </Card>
          {order.order_type === "dine_in" && order.tables && order.tables.length > 0 && (
            <Card className="p-4">
              <h2 className="mb-3 text-sm font-semibold">{t("shop.orders.field.table_qr")}</h2>
              <div className="flex flex-wrap gap-6">
                {order.tables.map((tb) => (
                  <div key={tb.id} className="flex flex-col items-center gap-2">
                    <div className="rounded-lg border bg-white p-2">
                      <QRCodeSVG
                        value={tb.qr_token}
                        size={120}
                        level="M"
                        marginSize={1}
                        bgColor="#ffffff"
                        fgColor="#111827"
                      />
                    </div>
                    <span className="text-xs font-medium text-muted-foreground">{tb.code}</span>
                  </div>
                ))}
              </div>
            </Card>
          )}

          {isReadOnly && (
            <p className="text-center text-xs text-muted-foreground">
              {t("shop.orders.readonly_note", { status: t(`shop.orders.status.${status}`) })}
            </p>
          )}
        </div>
      </PageContent>
      <Dialog
        open={confirmCancel}
        onOpenChange={(open) => {
          setConfirmCancel(open);
          if (!open) setCancelReason("");
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("common.confirm")}</DialogTitle>
            <DialogDescription>{t("shop.orders.cancel_confirm")}</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <label htmlFor="cancel-reason" className="text-xs text-muted-foreground">
              {t("shop.orders.cancel_reason_label")}
            </label>
            <Textarea
              id="cancel-reason"
              rows={3}
              maxLength={500}
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder={t("shop.orders.cancel_reason_placeholder")}
              className="field-sizing-fixed"
            />
            <p className="text-right text-xs text-muted-foreground">{cancelReason.length}/500</p>
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setConfirmCancel(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => {
                setConfirmCancel(false);
                cancelMut.mutate(
                  { id, voidReason: cancelReason.trim() },
                  { onSuccess: () => setCancelReason("") },
                );
              }}
              // The backend requires a reason, so block the round trip rather
              // than let staff submit into a 422.
              disabled={cancelMut.isPending || !cancelReason.trim()}
            >
              {cancelMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <PaymentMethodDialog
        open={paymentOpen}
        onOpenChange={setPaymentOpen}
        isPending={completeMut.isPending}
        onSubmit={(data) => {
          completeMut.mutate({ id, data }, { onSuccess: () => setPaymentOpen(false) });
        }}
      />
    </>
  );
}
