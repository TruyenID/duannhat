"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, CheckCircle2, PlayCircle, SendHorizontal, Trash2, XCircle } from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Spinner,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useApproveProductionOrder,
  useCancelProductionOrder,
  useCompleteProductionOrder,
  useDeleteProductionOrder,
  useProductionOrder,
  useStartProductionOrder,
  useSubmitProductionOrder,
} from "@/hooks/api/use-production-orders";
import { ProductionOrderStatus } from "@/services/production-order-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";

function formatNumber(n: number | string | null | undefined): string {
  if (n == null) return "—";
  const num = Number(n);
  if (!Number.isFinite(num)) return "—";
  return num.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
  });
}

export default function ProductionOrderDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const STATUS_LABELS: Record<ProductionOrderStatus, string> = {
    [ProductionOrderStatus.Draft]: t("shop.production.orders.status.draft"),
    [ProductionOrderStatus.Pending]: t("shop.production.orders.status.pending"),
    [ProductionOrderStatus.Approved]: t("shop.production.orders.status.approved"),
    [ProductionOrderStatus.InProgress]: t("shop.production.orders.status.in_progress"),
    [ProductionOrderStatus.Completed]: t("shop.production.orders.status.completed"),
    [ProductionOrderStatus.Cancelled]: t("shop.production.orders.status.cancelled"),
  };
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useProductionOrder(shopSlug, id);
  const order = data?.data;

  const submitMutation = useSubmitProductionOrder(shopSlug);
  const approveMutation = useApproveProductionOrder(shopSlug);
  const startMutation = useStartProductionOrder(shopSlug);
  const completeMutation = useCompleteProductionOrder(shopSlug);
  const cancelMutation = useCancelProductionOrder(shopSlug);
  const deleteMutation = useDeleteProductionOrder(shopSlug);

  const pending =
    submitMutation.isPending ||
    approveMutation.isPending ||
    startMutation.isPending ||
    completeMutation.isPending ||
    cancelMutation.isPending ||
    deleteMutation.isPending;

  const [completeOpen, setCompleteOpen] = useState(false);
  const [actualQty, setActualQty] = useState<string>("");
  const [pendingAction, setPendingAction] = useState<
    "submit" | "approve" | "start" | "cancel" | "delete" | null
  >(null);

  async function handleConfirmAction() {
    if (!order || !pendingAction) return;
    const action = pendingAction;
    setPendingAction(null);
    if (action === "submit") submitMutation.mutate(order.id);
    else if (action === "approve") approveMutation.mutate(order.id);
    else if (action === "start") startMutation.mutate(order.id);
    else if (action === "cancel") cancelMutation.mutate(order.id);
    else if (action === "delete") {
      await deleteMutation.mutateAsync(order.id);
      router.push(`/shop/${shopSlug}/production/orders`);
    }
  }

  async function confirmComplete() {
    if (!order) return;
    const n = Number(actualQty);
    if (!Number.isFinite(n) || n <= 0) return;
    await completeMutation.mutateAsync({
      id: order.id,
      data: { actual_quantity: n },
    });
    setCompleteOpen(false);
    setActualQty("");
  }

  return (
    <>
      <PageHeader
        title={
          order
            ? t("shop.production.orders.detail_title", { code: order.order_code })
            : t("shop.production.orders.detail_fallback")
        }
      >
        <Button
          variant="outline"
          size="sm"
          onClick={() => router.push(`/shop/${shopSlug}/production/orders`)}
        >
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
        {order && order.status === ProductionOrderStatus.Draft && (
          <Button size="sm" onClick={() => setPendingAction("submit")} disabled={pending}>
            <SendHorizontal className="mr-1.5 size-3.5" />
            {t("common.submit")}
          </Button>
        )}
        {order && order.status === ProductionOrderStatus.Pending && (
          <Button size="sm" onClick={() => setPendingAction("approve")} disabled={pending}>
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.approve")}
          </Button>
        )}
        {order && order.status === ProductionOrderStatus.Approved && (
          <Button size="sm" onClick={() => setPendingAction("start")} disabled={pending}>
            <PlayCircle className="mr-1.5 size-3.5" />
            {t("common.start")}
          </Button>
        )}
        {order && order.status === ProductionOrderStatus.InProgress && (
          <Button
            size="sm"
            onClick={() => {
              setActualQty(String(order.planned_quantity));
              setCompleteOpen(true);
            }}
            disabled={pending}
          >
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.complete")}
          </Button>
        )}
        {order &&
          (order.status === ProductionOrderStatus.Draft ||
            order.status === ProductionOrderStatus.Pending ||
            order.status === ProductionOrderStatus.Approved ||
            order.status === ProductionOrderStatus.InProgress) && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPendingAction("cancel")}
              disabled={pending}
            >
              <XCircle className="mr-1.5 size-3.5" />
              {t("common.cancel")}
            </Button>
          )}
        {order &&
          (order.status === ProductionOrderStatus.Draft ||
            order.status === ProductionOrderStatus.Cancelled) && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPendingAction("delete")}
              disabled={pending}
              className="text-red-600 hover:text-red-700"
            >
              <Trash2 className="mr-1.5 size-3.5" />
              {t("common.delete")}
            </Button>
          )}
      </PageHeader>
      <PageContent>
        {isLoading && (
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
          </div>
        )}
        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("common.failed_to_load")} {t("common.retry_hint")}
          </div>
        )}
        {order && (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-md border bg-card p-4 sm:grid-cols-4">
              <Field label={t("shop.production.orders.field.code")} value={order.order_code} mono />
              <Field
                label={t("shop.production.orders.field.warehouse")}
                value={order.warehouse?.name ?? "—"}
              />
              <Field
                label={t("shop.production.orders.field.output")}
                value={order.outputVariant?.name ?? order.outputVariant?.sku ?? "—"}
              />
              <Field
                label={t("shop.production.orders.field.status")}
                value={<Badge variant="outline">{STATUS_LABELS[order.status]}</Badge>}
              />
              <Field
                label={t("shop.production.orders.field.planned_qty")}
                value={`${formatNumber(order.planned_quantity)} ${order.output_unit}`}
              />
              <Field
                label={t("shop.production.orders.field.actual_qty")}
                value={
                  order.actual_quantity != null
                    ? `${formatNumber(order.actual_quantity)} ${order.output_unit}`
                    : "—"
                }
              />
              <Field
                label={t("shop.production.orders.field.started")}
                value={formatDate(order.started_at, locale, timezone)}
              />
              <Field
                label={t("shop.production.orders.field.completed")}
                value={formatDate(order.completed_at, locale, timezone)}
              />
              {order.note && (
                <Field
                  label={t("shop.production.orders.field.note")}
                  value={order.note}
                  className="sm:col-span-4"
                />
              )}
            </div>

            <div className="overflow-hidden rounded-md border">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs">
                  <tr>
                    <th className="w-10 px-2 py-2 text-left">#</th>
                    <th className="px-2 py-2 text-left">
                      {t("shop.production.orders.col.component")}
                    </th>
                    <th className="w-32 px-2 py-2 text-right">
                      {t("shop.production.orders.col.planned_qty")}
                    </th>
                    <th className="w-32 px-2 py-2 text-right">
                      {t("shop.production.orders.col.actual_qty")}
                    </th>
                    <th className="w-20 px-2 py-2 text-left">
                      {t("shop.production.orders.col.unit")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {(order.items ?? []).map((item, i) => {
                    const label =
                      item.productSku?.name ??
                      item.productSku?.sku ??
                      item.material?.name ??
                      item.material?.sku ??
                      "—";
                    return (
                      <tr key={item.id} className="border-t">
                        <td className="px-2 py-1.5 text-xs text-muted-foreground">{i + 1}</td>
                        <td className="px-2 py-1.5">{label}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {formatNumber(item.planned_quantity)}
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {formatNumber(item.actual_quantity)}
                        </td>
                        <td className="px-2 py-1.5">{item.unit ?? "—"}</td>
                      </tr>
                    );
                  })}
                  {(order.items?.length ?? 0) === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="px-2 py-6 text-center text-xs text-muted-foreground"
                      >
                        {t("shop.production.orders.empty_components")}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </PageContent>

      <Dialog open={!!pendingAction} onOpenChange={(open) => !open && setPendingAction(null)}>
        <DialogContent aria-describedby={undefined} className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {pendingAction && t(`shop.production.orders.confirm.${pendingAction}`)}
            </DialogTitle>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setPendingAction(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant={pendingAction === "delete" ? "destructive" : "default"}
              size="sm"
              onClick={handleConfirmAction}
              disabled={pending}
            >
              {pending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
        <DialogContent aria-describedby={undefined} className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>{t("shop.production.orders.complete_title")}</DialogTitle>
          </DialogHeader>
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.actual_qty_label", { unit: order?.output_unit ?? "" })}
            </label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={actualQty}
              onChange={(e) => setActualQty(e.target.value)}
              className="h-9 text-sm"
            />
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setCompleteOpen(false)}
              disabled={completeMutation.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              onClick={confirmComplete}
              disabled={completeMutation.isPending || !actualQty || Number(actualQty) <= 0}
            >
              {completeMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm_complete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function Field({
  label,
  value,
  mono,
  className,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean;
  className?: string;
}) {
  return (
    <div className={className}>
      <div className="text-xs tracking-wide text-muted-foreground uppercase">{label}</div>
      <div className={`mt-0.5 text-sm ${mono ? "font-mono" : ""}`}>{value}</div>
    </div>
  );
}
