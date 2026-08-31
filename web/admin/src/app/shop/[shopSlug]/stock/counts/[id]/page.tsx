"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import {
  ArrowLeft,
  CheckCircle2,
  PlayCircle,
  Plus,
  Save,
  SendHorizontal,
  XCircle,
} from "lucide-react";
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
  useApproveStockCount,
  useCancelStockCount,
  useStartStockCount,
  useStockCount,
  useSubmitStockCount,
  useUpdateStockCountItems,
} from "@/hooks/api/use-stock-counts";
import {
  StockCountScope,
  StockCountStatus,
  type StockCountItem,
} from "@/services/stock-count-service";
import { AddItemsDialog } from "./components/add-items-dialog";
import { useTranslation } from "@/providers/app-provider";

type EditState = Record<string, { counted: string; note: string }>;

function toNum(v: string): number | null {
  if (v === "") return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function formatNumber(n: number | string | null | undefined): string {
  if (n == null) return "—";
  const num = Number(n);
  if (!Number.isFinite(num)) return "—";
  return num.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
  });
}

function itemName(item: StockCountItem): string {
  return (
    item.productSku?.name ??
    item.productSku?.sku ??
    item.material?.name ??
    item.material?.sku ??
    "—"
  );
}

export default function StockCountDetailPage() {
  const { t } = useTranslation();
  const STATUS_LABELS: Record<StockCountStatus, string> = {
    [StockCountStatus.Draft]: t("shop.stock.counts.status.draft"),
    [StockCountStatus.InProgress]: t("shop.stock.counts.status.in_progress"),
    [StockCountStatus.PendingApproval]: t("shop.stock.counts.status.pending_approval"),
    [StockCountStatus.Approved]: t("shop.stock.counts.status.approved"),
    [StockCountStatus.Cancelled]: t("shop.stock.counts.status.cancelled"),
  };
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useStockCount(shopSlug, id);
  const count = data?.data;

  const startMutation = useStartStockCount(shopSlug);
  const updateItemsMutation = useUpdateStockCountItems(shopSlug);
  const submitMutation = useSubmitStockCount(shopSlug);
  const approveMutation = useApproveStockCount(shopSlug);
  const cancelMutation = useCancelStockCount(shopSlug);

  const pending =
    startMutation.isPending ||
    updateItemsMutation.isPending ||
    submitMutation.isPending ||
    approveMutation.isPending ||
    cancelMutation.isPending;

  const [addItemsOpen, setAddItemsOpen] = useState(false);
  const [pendingAction, setPendingAction] = useState<
    "start" | "submit" | "approve" | "cancel" | null
  >(null);

  // Local edit buffer keyed by item id so the user can edit multiple rows
  // before saving. Reset whenever the backend count payload reloads.
  const [edits, setEdits] = useState<EditState>({});
  useEffect(() => {
    if (!count?.items) return;
    const next: EditState = {};
    for (const it of count.items) {
      next[it.id] = {
        counted: it.counted_quantity != null ? String(it.counted_quantity) : "",
        note: it.note ?? "",
      };
    }
    setEdits(next);
  }, [count?.items]);

  const isInProgress = count?.status === StockCountStatus.InProgress;
  const canStart = count?.status === StockCountStatus.Draft;
  const canSubmit = count?.status === StockCountStatus.InProgress;
  const canApprove = count?.status === StockCountStatus.PendingApproval;
  const canCancel =
    count?.status === StockCountStatus.Draft ||
    count?.status === StockCountStatus.InProgress ||
    count?.status === StockCountStatus.PendingApproval;
  // Partial-scope counts accept additional items while still in_progress.
  // Full-scope counts snapshot everything at creation and are immutable.
  const canAddItems = isInProgress && count?.scope === StockCountScope.Partial;

  const existingSelections =
    count?.items?.map((it) =>
      it.product_sku_id ? `variant:${it.product_sku_id}` : `material:${it.material_id}`
    ) ?? [];

  const hasEdits = useMemo(() => {
    if (!count?.items) return false;
    for (const it of count.items) {
      const e = edits[it.id];
      if (!e) continue;
      const countedOrig = it.counted_quantity != null ? String(it.counted_quantity) : "";
      if (e.counted !== countedOrig || e.note !== (it.note ?? "")) {
        return true;
      }
    }
    return false;
  }, [edits, count?.items]);

  async function handleConfirmAction() {
    if (!count || !pendingAction) return;
    const action = pendingAction;
    setPendingAction(null);
    if (action === "start") startMutation.mutate(count.id);
    else if (action === "submit") {
      if (hasEdits) await handleSave();
      submitMutation.mutate(count.id);
    } else if (action === "approve") approveMutation.mutate(count.id);
    else if (action === "cancel") cancelMutation.mutate(count.id);
  }

  async function handleSave() {
    if (!count || !hasEdits) return;
    const items = Object.entries(edits)
      .map(([itemId, e]) => {
        const counted = toNum(e.counted);
        if (counted == null && e.note === "") return null;
        return {
          id: itemId,
          counted_quantity: counted,
          note: e.note || null,
        };
      })
      .filter((x): x is NonNullable<typeof x> => x !== null);
    await updateItemsMutation.mutateAsync({ id: count.id, data: { items } });
  }

  function variance(item: StockCountItem): {
    value: number | null;
    tone: "surplus" | "shortage" | "match" | "none";
  } {
    const e = edits[item.id];
    const counted =
      e?.counted !== "" && e?.counted != null ? toNum(e.counted) : (item.counted_quantity ?? null);
    if (counted == null) return { value: null, tone: "none" };
    const diff = counted - Number(item.system_quantity);
    if (diff > 0) return { value: diff, tone: "surplus" };
    if (diff < 0) return { value: diff, tone: "shortage" };
    return { value: 0, tone: "match" };
  }

  return (
    <>
      <PageHeader
        title={
          count
            ? t("shop.stock.counts.detail.title", { code: count.count_code })
            : t("shop.stock.counts.detail.title_fallback")
        }
      >
        <Button
          variant="outline"
          size="sm"
          onClick={() => router.push(`/shop/${shopSlug}/stock/counts`)}
        >
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
        {count && canStart && (
          <Button size="sm" onClick={() => setPendingAction("start")} disabled={pending}>
            <PlayCircle className="mr-1.5 size-3.5" />
            {t("shop.stock.counts.detail.start_counting")}
          </Button>
        )}
        {count && canAddItems && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setAddItemsOpen(true)}
            disabled={pending}
          >
            <Plus className="mr-1.5 size-3.5" />
            {t("shop.stock.counts.detail.add_items")}
          </Button>
        )}
        {count && isInProgress && (
          <Button variant="outline" size="sm" onClick={handleSave} disabled={pending || !hasEdits}>
            <Save className="mr-1.5 size-3.5" />
            {t("common.save")}
          </Button>
        )}
        {count && canSubmit && (
          <Button size="sm" onClick={() => setPendingAction("submit")} disabled={pending}>
            <SendHorizontal className="mr-1.5 size-3.5" />
            {t("common.submit")}
          </Button>
        )}
        {count && canApprove && (
          <Button size="sm" onClick={() => setPendingAction("approve")} disabled={pending}>
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.approve")}
          </Button>
        )}
        {count && canCancel && (
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
        {count && (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-md border bg-card p-4 sm:grid-cols-4">
              <Field
                label={t("shop.stock.counts.detail.field.code")}
                value={count.count_code}
                mono
              />
              <Field
                label={t("shop.stock.counts.detail.field.warehouse")}
                value={count.warehouse?.name ?? "—"}
              />
              <Field
                label={t("shop.stock.counts.detail.field.scope")}
                value={
                  count.scope === StockCountScope.Full
                    ? t("shop.stock.counts.scope.full")
                    : t("shop.stock.counts.scope.partial")
                }
              />
              <Field
                label={t("shop.stock.counts.detail.field.status")}
                value={<Badge variant="outline">{STATUS_LABELS[count.status]}</Badge>}
              />
              {count.note && (
                <Field
                  label={t("shop.stock.counts.detail.field.note")}
                  value={count.note}
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
                      {t("shop.stock.counts.detail.col.item")}
                    </th>
                    <th className="w-24 px-2 py-2 text-left">
                      {t("shop.stock.counts.detail.col.unit")}
                    </th>
                    <th className="w-32 px-2 py-2 text-right">
                      {t("shop.stock.counts.detail.col.system_qty")}
                    </th>
                    <th className="w-36 px-2 py-2 text-right">
                      {t("shop.stock.counts.detail.col.counted_qty")}
                    </th>
                    <th className="w-28 px-2 py-2 text-right">
                      {t("shop.stock.counts.detail.col.variance")}
                    </th>
                    <th className="px-2 py-2 text-left">
                      {t("shop.stock.counts.detail.col.note")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {(count.items ?? []).map((item, i) => {
                    const e = edits[item.id] ?? { counted: "", note: "" };
                    const v = variance(item);
                    const toneClass =
                      v.tone === "surplus"
                        ? "text-emerald-600 dark:text-emerald-400"
                        : v.tone === "shortage"
                          ? "text-red-600 dark:text-red-400"
                          : "text-muted-foreground";
                    return (
                      <tr key={item.id} className="border-t">
                        <td className="px-2 py-1.5 text-xs text-muted-foreground">{i + 1}</td>
                        <td className="px-2 py-1.5">{itemName(item)}</td>
                        <td className="px-2 py-1.5 text-xs text-muted-foreground">
                          {item.unit ?? "—"}
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {formatNumber(item.system_quantity)}
                        </td>
                        <td className="px-2 py-1.5">
                          {isInProgress ? (
                            <Input
                              type="number"
                              step="0.0001"
                              min="0"
                              value={e.counted}
                              onChange={(ev) =>
                                setEdits((prev) => ({
                                  ...prev,
                                  [item.id]: {
                                    ...e,
                                    counted: ev.target.value,
                                  },
                                }))
                              }
                              className="h-8 text-right text-sm tabular-nums"
                            />
                          ) : (
                            <span className="block text-right tabular-nums">
                              {formatNumber(item.counted_quantity)}
                            </span>
                          )}
                        </td>
                        <td className={`px-2 py-1.5 text-right tabular-nums ${toneClass}`}>
                          {v.value == null
                            ? "—"
                            : v.value > 0
                              ? `+${formatNumber(v.value)}`
                              : formatNumber(v.value)}
                        </td>
                        <td className="px-2 py-1.5">
                          {isInProgress ? (
                            <Input
                              value={e.note}
                              onChange={(ev) =>
                                setEdits((prev) => ({
                                  ...prev,
                                  [item.id]: {
                                    ...e,
                                    note: ev.target.value,
                                  },
                                }))
                              }
                              className="h-8 text-sm"
                            />
                          ) : (
                            <span className="block text-xs text-muted-foreground">
                              {item.note ?? "—"}
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                  {(count.items?.length ?? 0) === 0 && (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-2 py-6 text-center text-xs text-muted-foreground"
                      >
                        {t("shop.stock.counts.detail.no_items")}
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
              {pendingAction === "start" && t("shop.stock.counts.detail.confirm_start")}
              {pendingAction === "submit" && t("shop.stock.counts.detail.confirm_submit")}
              {pendingAction === "approve" && t("shop.stock.counts.detail.confirm_approve")}
              {pendingAction === "cancel" && t("shop.stock.counts.detail.confirm_cancel")}
            </DialogTitle>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setPendingAction(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant={pendingAction === "cancel" ? "destructive" : "default"}
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

      {count && (
        <AddItemsDialog
          shopSlug={shopSlug}
          countId={count.id}
          open={addItemsOpen}
          onOpenChange={setAddItemsOpen}
          existingSelections={existingSelections}
        />
      )}
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
