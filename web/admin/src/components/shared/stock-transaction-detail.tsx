"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { ArrowLeft, CheckCircle2, SendHorizontal, Trash2, XCircle } from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useApproveStockTransaction,
  useCancelStockTransaction,
  useDeleteStockTransaction,
  useStockTransaction,
  useSubmitStockTransaction,
} from "@/hooks/api/use-stock-transactions";
import { StockTransactionStatus } from "@/services/stock-transaction-service";

/**
 * Shared detail view for any StockTransaction-backed entity:
 * stock-transactions, disposals (sub_type=disposal), and other wrappers
 * that share the same workflow. Callers pass a `backHref` so breadcrumbs
 * return to the correct list page after a mutation.
 */
export interface StockTransactionDetailViewProps {
  shopSlug: string;
  id: string;
  backHref: string;
  /** Override the page title; defaults to "<Entity> <transaction_code>". */
  entityLabel?: string;
}

const STATUS_LABELS: Record<StockTransactionStatus, string> = {
  [StockTransactionStatus.Draft]: "Draft",
  [StockTransactionStatus.Pending]: "Pending",
  [StockTransactionStatus.Approved]: "Approved",
  [StockTransactionStatus.Completed]: "Completed",
  [StockTransactionStatus.Cancelled]: "Cancelled",
};

function formatNumber(n: number | string | null | undefined): string {
  if (n == null) return "—";
  const num = Number(n);
  if (!Number.isFinite(num)) return "—";
  return num.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
  });
}

function canSubmit(s: StockTransactionStatus) {
  return s === StockTransactionStatus.Draft;
}
function canApprove(s: StockTransactionStatus) {
  return s === StockTransactionStatus.Pending;
}
function canCancel(s: StockTransactionStatus) {
  return s === StockTransactionStatus.Draft || s === StockTransactionStatus.Pending;
}
function canDelete(s: StockTransactionStatus) {
  return s === StockTransactionStatus.Draft || s === StockTransactionStatus.Cancelled;
}

export function StockTransactionDetailView({
  shopSlug,
  id,
  backHref,
  entityLabel = "Transaction",
}: StockTransactionDetailViewProps) {
  const router = useRouter();
  const { locale } = useTranslation();
  const { timezone } = useTimezone();
  const [pendingAction, setPendingAction] = useState<
    "submit" | "approve" | "cancel" | "delete" | null
  >(null);
  const { data, isLoading, error } = useStockTransaction(shopSlug, id);
  const tx = data?.data;

  const submitMutation = useSubmitStockTransaction(shopSlug);
  const approveMutation = useApproveStockTransaction(shopSlug);
  const cancelMutation = useCancelStockTransaction(shopSlug);
  const deleteMutation = useDeleteStockTransaction(shopSlug);

  const pending =
    submitMutation.isPending ||
    approveMutation.isPending ||
    cancelMutation.isPending ||
    deleteMutation.isPending;

  async function handleConfirmAction() {
    if (!tx || !pendingAction) return;
    const action = pendingAction;
    setPendingAction(null);
    if (action === "submit") submitMutation.mutate(tx.id);
    else if (action === "approve") approveMutation.mutate({ id: tx.id });
    else if (action === "cancel") cancelMutation.mutate(tx.id);
    else if (action === "delete") {
      await deleteMutation.mutateAsync(tx.id);
      router.push(backHref);
    }
  }

  return (
    <>
      <PageHeader title={tx ? `${entityLabel} ${tx.transaction_code}` : entityLabel}>
        <Button variant="outline" size="sm" onClick={() => router.push(backHref)}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          Back
        </Button>
        {tx && canSubmit(tx.status) && (
          <Button size="sm" onClick={() => setPendingAction("submit")} disabled={pending}>
            <SendHorizontal className="mr-1.5 size-3.5" />
            Submit
          </Button>
        )}
        {tx && canApprove(tx.status) && (
          <Button size="sm" onClick={() => setPendingAction("approve")} disabled={pending}>
            <CheckCircle2 className="mr-1.5 size-3.5" />
            Approve
          </Button>
        )}
        {tx && canCancel(tx.status) && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setPendingAction("cancel")}
            disabled={pending}
          >
            <XCircle className="mr-1.5 size-3.5" />
            Cancel
          </Button>
        )}
        {tx && canDelete(tx.status) && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setPendingAction("delete")}
            disabled={pending}
            className="text-red-600 hover:text-red-700"
          >
            <Trash2 className="mr-1.5 size-3.5" />
            Delete
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
            Failed to load. Please try again.
          </div>
        )}
        {tx && (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-md border bg-card p-4 sm:grid-cols-4">
              <Field label="Code" value={tx.transaction_code} mono />
              <Field label="Type" value={tx.type.replace(/_/g, " ")} capitalize />
              <Field label="Sub-type" value={tx.sub_type?.replace(/_/g, " ") ?? "—"} capitalize />
              <Field
                label="Status"
                value={<Badge variant="outline">{STATUS_LABELS[tx.status]}</Badge>}
              />
              <Field label="Warehouse" value={tx.warehouse?.name ?? "—"} />
              <Field label="Created" value={formatDate(tx.created_at, locale, timezone)} />
              <Field label="Approved" value={formatDate(tx.approved_at, locale, timezone)} />
              <Field label="Completed" value={formatDate(tx.completed_at, locale, timezone)} />
              {tx.note && <Field label="Note" value={tx.note} className="sm:col-span-4" />}
            </div>

            <div className="overflow-hidden rounded-md border">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs">
                  <tr>
                    <th className="w-10 px-2 py-2 text-left">#</th>
                    <th className="px-2 py-2 text-left">Item</th>
                    <th className="w-28 px-2 py-2 text-right">Quantity</th>
                    <th className="w-20 px-2 py-2 text-left">Unit</th>
                    <th className="w-28 px-2 py-2 text-right">Unit price</th>
                    <th className="px-2 py-2 text-left">Note</th>
                  </tr>
                </thead>
                <tbody>
                  {(tx.items ?? []).map((item, i) => {
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
                          {formatNumber(item.quantity)}
                        </td>
                        <td className="px-2 py-1.5">{item.unit ?? "—"}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {formatNumber(item.unit_price)}
                        </td>
                        <td className="px-2 py-1.5 text-xs text-muted-foreground">
                          {item.note ?? "—"}
                        </td>
                      </tr>
                    );
                  })}
                  {(tx.items?.length ?? 0) === 0 && (
                    <tr>
                      <td
                        colSpan={6}
                        className="px-2 py-6 text-center text-xs text-muted-foreground"
                      >
                        No items on this transaction.
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
              {pendingAction === "submit" && "Submit for approval?"}
              {pendingAction === "approve" && "Approve this? Stock impact cannot be undone."}
              {pendingAction === "cancel" && "Cancel this?"}
              {pendingAction === "delete" && "Delete permanently?"}
            </DialogTitle>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setPendingAction(null)}>
              Cancel
            </Button>
            <Button
              variant={pendingAction === "delete" ? "destructive" : "default"}
              size="sm"
              onClick={handleConfirmAction}
              disabled={pending}
            >
              {pending && <Spinner className="mr-1.5 size-3.5" />}
              Confirm
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
  capitalize,
  className,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean;
  capitalize?: boolean;
  className?: string;
}) {
  return (
    <div className={className}>
      <div className="text-xs tracking-wide text-muted-foreground uppercase">{label}</div>
      <div
        className={`mt-0.5 text-sm ${mono ? "font-mono" : ""} ${capitalize ? "capitalize" : ""}`}
      >
        {value}
      </div>
    </div>
  );
}
