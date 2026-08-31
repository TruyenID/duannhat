"use client";

import { useParams, useRouter } from "next/navigation";
import {
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  PackageCheck,
  SendHorizontal,
  Trash2,
  XCircle,
} from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useApproveStockTransfer,
  useCancelStockTransfer,
  useDeleteStockTransfer,
  useReceiveStockTransfer,
  useStockTransfer,
  useSubmitStockTransfer,
} from "@/hooks/api/use-stock-transfers";
import { StockTransferStatus } from "@/services/stock-transfer-service";
import { useState } from "react";
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

function canSubmit(s: StockTransferStatus) {
  return s === StockTransferStatus.Draft;
}
function canApprove(s: StockTransferStatus) {
  return s === StockTransferStatus.Pending;
}
function canReceive(s: StockTransferStatus) {
  // Approve stock-outs first (Approved → InTransit handled backend-side),
  // then receive moves InTransit → Completed. Keep both guards permissive
  // so users who operate in sequence are not surprised.
  return s === StockTransferStatus.Approved || s === StockTransferStatus.InTransit;
}
function canCancel(s: StockTransferStatus) {
  return (
    s === StockTransferStatus.Draft ||
    s === StockTransferStatus.Pending ||
    s === StockTransferStatus.Approved
  );
}
function canDelete(s: StockTransferStatus) {
  return s === StockTransferStatus.Draft || s === StockTransferStatus.Cancelled;
}

export default function StockTransferDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const [pendingAction, setPendingAction] = useState<
    "submit" | "approve" | "receive" | "cancel" | "delete" | null
  >(null);
  const STATUS_LABELS: Record<StockTransferStatus, string> = {
    [StockTransferStatus.Draft]: t("shop.stock.transfers.status.draft"),
    [StockTransferStatus.Pending]: t("shop.stock.transfers.status.pending"),
    [StockTransferStatus.Approved]: t("shop.stock.transfers.status.approved"),
    [StockTransferStatus.InTransit]: t("shop.stock.transfers.status.in_transit"),
    [StockTransferStatus.Completed]: t("shop.stock.transfers.status.completed"),
    [StockTransferStatus.Cancelled]: t("shop.stock.transfers.status.cancelled"),
  };
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useStockTransfer(shopSlug, id);
  const tr = data?.data;

  const submitMutation = useSubmitStockTransfer(shopSlug);
  const approveMutation = useApproveStockTransfer(shopSlug);
  const receiveMutation = useReceiveStockTransfer(shopSlug);
  const cancelMutation = useCancelStockTransfer(shopSlug);
  const deleteMutation = useDeleteStockTransfer(shopSlug);

  const pending =
    submitMutation.isPending ||
    approveMutation.isPending ||
    receiveMutation.isPending ||
    cancelMutation.isPending ||
    deleteMutation.isPending;

  async function handleConfirmAction() {
    if (!tr || !pendingAction) return;
    const action = pendingAction;
    setPendingAction(null);
    if (action === "submit") submitMutation.mutate(tr.id);
    else if (action === "approve") approveMutation.mutate(tr.id);
    else if (action === "receive") receiveMutation.mutate(tr.id);
    else if (action === "cancel") cancelMutation.mutate(tr.id);
    else if (action === "delete") {
      await deleteMutation.mutateAsync(tr.id);
      router.push(`/shop/${shopSlug}/stock/transfers`);
    }
  }

  return (
    <>
      <PageHeader
        title={
          tr
            ? t("shop.stock.transfers.detail.title", { code: tr.transfer_code })
            : t("shop.stock.transfers.detail.title_fallback")
        }
      >
        <Button
          variant="outline"
          size="sm"
          onClick={() => router.push(`/shop/${shopSlug}/stock/transfers`)}
        >
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
        {tr && canSubmit(tr.status) && (
          <Button size="sm" onClick={() => setPendingAction("submit")} disabled={pending}>
            <SendHorizontal className="mr-1.5 size-3.5" />
            {t("common.submit")}
          </Button>
        )}
        {tr && canApprove(tr.status) && (
          <Button size="sm" onClick={() => setPendingAction("approve")} disabled={pending}>
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.approve")}
          </Button>
        )}
        {tr && canReceive(tr.status) && (
          <Button size="sm" onClick={() => setPendingAction("receive")} disabled={pending}>
            <PackageCheck className="mr-1.5 size-3.5" />
            {t("shop.stock.transfers.detail.receive")}
          </Button>
        )}
        {tr && canCancel(tr.status) && (
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
        {tr && canDelete(tr.status) && (
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
        {tr && (
          <>
            <div className="mb-4 rounded-md border bg-card p-4">
              <div className="mb-3 flex items-center gap-3 text-sm">
                <span className="font-medium">{tr.sourceWarehouse?.name ?? "—"}</span>
                <ArrowRight className="size-4 text-muted-foreground" />
                <span className="font-medium">{tr.destinationWarehouse?.name ?? "—"}</span>
                <Badge variant="outline" className="ml-auto">
                  {STATUS_LABELS[tr.status]}
                </Badge>
              </div>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Field
                  label={t("shop.stock.transfers.detail.field.code")}
                  value={tr.transfer_code}
                  mono
                />
                <Field
                  label={t("shop.stock.transfers.detail.field.created")}
                  value={formatDate(tr.created_at, locale, timezone)}
                />
                <Field
                  label={t("shop.stock.transfers.detail.field.approved")}
                  value={formatDate(tr.approved_at, locale, timezone)}
                />
                <Field
                  label={t("shop.stock.transfers.detail.field.received")}
                  value={formatDate(tr.received_at, locale, timezone)}
                />
                {tr.note && (
                  <Field
                    label={t("shop.stock.transfers.detail.field.note")}
                    value={tr.note}
                    className="sm:col-span-4"
                  />
                )}
              </div>
            </div>

            <div className="overflow-hidden rounded-md border">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs">
                  <tr>
                    <th className="w-10 px-2 py-2 text-left">#</th>
                    <th className="px-2 py-2 text-left">
                      {t("shop.stock.transfers.detail.col.item")}
                    </th>
                    <th className="w-28 px-2 py-2 text-right">
                      {t("shop.stock.transfers.detail.col.quantity")}
                    </th>
                    <th className="w-20 px-2 py-2 text-left">
                      {t("shop.stock.transfers.detail.col.unit")}
                    </th>
                    <th className="px-2 py-2 text-left">
                      {t("shop.stock.transfers.detail.col.note")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {(tr.items ?? []).map((item, i) => {
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
                          {formatNumber(item.sent_quantity)}
                        </td>
                        <td className="px-2 py-1.5">{item.unit ?? "—"}</td>
                        <td className="px-2 py-1.5 text-xs text-muted-foreground">
                          {item.note ?? "—"}
                        </td>
                      </tr>
                    );
                  })}
                  {(tr.items?.length ?? 0) === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="px-2 py-6 text-center text-xs text-muted-foreground"
                      >
                        {t("shop.stock.transfers.detail.no_items")}
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
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {pendingAction && t(`shop.stock.transfers.detail.confirm.${pendingAction}.title`)}
            </DialogTitle>
            <DialogDescription>
              {pendingAction &&
                tr &&
                t(`shop.stock.transfers.detail.confirm.${pendingAction}.body`, {
                  code: tr.transfer_code,
                  source: tr.sourceWarehouse?.name ?? "—",
                  destination: tr.destinationWarehouse?.name ?? "—",
                })}
            </DialogDescription>
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
              {pendingAction
                ? t(`shop.stock.transfers.detail.confirm.${pendingAction}.cta`)
                : t("common.confirm")}
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
