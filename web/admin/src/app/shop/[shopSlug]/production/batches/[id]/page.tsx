"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, CheckCircle2, PlayCircle, SendHorizontal, Trash2, XCircle } from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useApproveMaterialBatch,
  useCancelMaterialBatch,
  useCompleteMaterialBatch,
  useDeleteMaterialBatch,
  useMaterialBatch,
  useMaterialBatchCostBreakdown,
  useStartMaterialBatch,
  useSubmitMaterialBatch,
} from "@/hooks/api/use-material-batches";
import { MaterialBatchStatus } from "@/services/material-batch-service";
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

export default function MaterialBatchDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const STATUS_LABELS: Record<MaterialBatchStatus, string> = {
    [MaterialBatchStatus.Draft]: t("shop.production.batches.status.draft"),
    [MaterialBatchStatus.Pending]: t("shop.production.batches.status.pending"),
    [MaterialBatchStatus.Approved]: t("shop.production.batches.status.approved"),
    [MaterialBatchStatus.InProgress]: t("shop.production.batches.status.in_progress"),
    [MaterialBatchStatus.Completed]: t("shop.production.batches.status.completed"),
    [MaterialBatchStatus.Cancelled]: t("shop.production.batches.status.cancelled"),
  };
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useMaterialBatch(shopSlug, id);
  const batch = data?.data;
  const { data: breakdownEnvelope } = useMaterialBatchCostBreakdown(shopSlug, id);
  const breakdown = breakdownEnvelope?.data;

  const submitMutation = useSubmitMaterialBatch(shopSlug);
  const approveMutation = useApproveMaterialBatch(shopSlug);
  const startMutation = useStartMaterialBatch(shopSlug);
  const completeMutation = useCompleteMaterialBatch(shopSlug);
  const cancelMutation = useCancelMaterialBatch(shopSlug);
  const deleteMutation = useDeleteMaterialBatch(shopSlug);

  const pending =
    submitMutation.isPending ||
    approveMutation.isPending ||
    startMutation.isPending ||
    completeMutation.isPending ||
    cancelMutation.isPending ||
    deleteMutation.isPending;

  const [completeOpen, setCompleteOpen] = useState(false);
  const [actualYield, setActualYield] = useState<string>("");
  const [varianceReason, setVarianceReason] = useState<string>("");
  const [pendingAction, setPendingAction] = useState<
    "submit" | "approve" | "start" | "cancel" | "delete" | null
  >(null);

  // Plan-022 (yield variance) — compute the % drift between user-entered
  // actual_yield and planned_yield. When it exceeds the recipe's
  // yield_variance_tolerance_pct, the dialog reveals the reason Textarea and
  // blocks submit until something is typed. BE re-validates regardless.
  const plannedYield = batch ? Number(batch.planned_yield) || 0 : 0;
  const actualYieldNumber = Number(actualYield);
  const proposedYield = Number.isFinite(actualYieldNumber) ? actualYieldNumber : 0;
  const variancePct =
    plannedYield > 0 ? (Math.abs(proposedYield - plannedYield) / plannedYield) * 100 : 0;
  const tolerancePct =
    batch?.recipe?.yield_variance_tolerance_pct != null
      ? Number(batch.recipe.yield_variance_tolerance_pct)
      : 0;
  const reasonRequired = proposedYield > 0 && variancePct > tolerancePct;

  async function handleConfirmAction() {
    if (!batch || !pendingAction) return;
    const action = pendingAction;
    setPendingAction(null);
    if (action === "submit") submitMutation.mutate(batch.id);
    else if (action === "approve") approveMutation.mutate(batch.id);
    else if (action === "start") startMutation.mutate(batch.id);
    else if (action === "cancel") cancelMutation.mutate(batch.id);
    else if (action === "delete") {
      await deleteMutation.mutateAsync(batch.id);
      router.push(`/shop/${shopSlug}/production/batches`);
    }
  }

  async function confirmComplete() {
    if (!batch) return;
    const n = Number(actualYield);
    if (!Number.isFinite(n) || n <= 0) return;
    if (reasonRequired && !varianceReason.trim()) return;
    try {
      await completeMutation.mutateAsync({
        id: batch.id,
        data: {
          actual_yield: n,
          yield_variance_reason: reasonRequired ? varianceReason.trim() : null,
        },
      });
      setCompleteOpen(false);
      setActualYield("");
      setVarianceReason("");
    } catch {
      // BE rejected (e.g. variance still over tolerance with empty reason on
      // the cross-validated path). Leave the dialog open — toast already
      // shown by the mutation hook so the user can correct the input.
    }
  }

  return (
    <>
      <PageHeader
        title={
          batch
            ? t("shop.production.batches.detail_title", { code: batch.batch_code })
            : t("shop.production.batches.detail_fallback")
        }
      >
        <Button
          variant="outline"
          size="sm"
          onClick={() => router.push(`/shop/${shopSlug}/production/batches`)}
        >
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
        {batch && batch.status === MaterialBatchStatus.Draft && (
          <Button size="sm" onClick={() => setPendingAction("submit")} disabled={pending}>
            <SendHorizontal className="mr-1.5 size-3.5" />
            {t("common.submit")}
          </Button>
        )}
        {batch && batch.status === MaterialBatchStatus.Pending && (
          <Button size="sm" onClick={() => setPendingAction("approve")} disabled={pending}>
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.approve")}
          </Button>
        )}
        {batch && batch.status === MaterialBatchStatus.Approved && (
          <Button size="sm" onClick={() => setPendingAction("start")} disabled={pending}>
            <PlayCircle className="mr-1.5 size-3.5" />
            {t("common.start")}
          </Button>
        )}
        {batch && batch.status === MaterialBatchStatus.InProgress && (
          <Button
            size="sm"
            onClick={() => {
              setActualYield(String(batch.planned_yield));
              setCompleteOpen(true);
            }}
            disabled={pending}
          >
            <CheckCircle2 className="mr-1.5 size-3.5" />
            {t("common.complete")}
          </Button>
        )}
        {batch &&
          (batch.status === MaterialBatchStatus.Draft ||
            batch.status === MaterialBatchStatus.Pending ||
            batch.status === MaterialBatchStatus.Approved ||
            batch.status === MaterialBatchStatus.InProgress) && (
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
        {batch &&
          (batch.status === MaterialBatchStatus.Draft ||
            batch.status === MaterialBatchStatus.Cancelled) && (
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
        {batch && (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-md border bg-card p-4 sm:grid-cols-4">
              <Field
                label={t("shop.production.batches.field.code")}
                value={batch.batch_code}
                mono
              />
              <Field
                label={t("shop.production.batches.field.warehouse")}
                value={batch.warehouse?.name ?? "—"}
              />
              <Field
                label={t("shop.production.batches.field.multiplier")}
                value={`×${formatNumber(batch.multiplier)}`}
              />
              <Field
                label={t("shop.production.batches.field.status")}
                value={<Badge variant="outline">{STATUS_LABELS[batch.status]}</Badge>}
              />
              <Field
                label={t("shop.production.batches.field.planned_yield")}
                value={`${formatNumber(batch.planned_yield)} ${batch.yield_unit}`}
              />
              <Field
                label={t("shop.production.batches.field.actual_yield")}
                value={
                  batch.actual_yield != null
                    ? `${formatNumber(batch.actual_yield)} ${batch.yield_unit}`
                    : "—"
                }
              />
              <Field
                label={t("shop.production.batches.field.started")}
                value={formatDate(batch.started_at, locale, timezone)}
              />
              <Field
                label={t("shop.production.batches.field.completed")}
                value={formatDate(batch.completed_at, locale, timezone)}
              />
              {batch.yield_variance_reason && (
                <Field
                  label={t("shop.production.batches.field.variance_reason")}
                  value={batch.yield_variance_reason}
                />
              )}
              {batch.note && (
                <Field
                  label={t("shop.production.batches.field.note")}
                  value={batch.note}
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
                      {t("shop.production.batches.col.component")}
                    </th>
                    <th className="w-32 px-2 py-2 text-right">
                      {t("shop.production.batches.col.planned_qty")}
                    </th>
                    <th className="w-32 px-2 py-2 text-right">
                      {t("shop.production.batches.col.actual_qty")}
                    </th>
                    <th className="w-20 px-2 py-2 text-left">
                      {t("shop.production.batches.col.unit")}
                    </th>
                    <th className="w-40 px-2 py-2 text-left">
                      {t("shop.production.batches.col.source_lot")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {(batch.items ?? []).map((item, i) => {
                    const label =
                      item.productSku?.name ??
                      item.productSku?.sku ??
                      item.material?.name ??
                      item.material?.sku ??
                      "—";
                    const lotId = (item as { material_lot_id?: string | null }).material_lot_id;
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
                        <td className="px-2 py-1.5 text-xs">
                          {lotId ? (
                            <Link
                              href={`/shop/${shopSlug}/material-lots/${lotId}`}
                              className="font-mono text-emerald-700 hover:underline"
                            >
                              {lotId.slice(0, 8)}…
                            </Link>
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                  {(batch.items?.length ?? 0) === 0 && (
                    <tr>
                      <td
                        colSpan={6}
                        className="px-2 py-6 text-center text-xs text-muted-foreground"
                      >
                        {t("shop.production.batches.empty_components")}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {(batch as unknown as { output_lot_id?: string | null }).output_lot_id ? (
              <div className="mt-4 space-y-3 rounded-md border border-emerald-200 bg-emerald-50 p-4">
                <div className="flex items-center justify-between">
                  <div className="text-sm font-medium text-emerald-900">
                    {t("shop.production.batches.output_lot_minted")}
                  </div>
                  <Link
                    href={`/shop/${shopSlug}/material-lots/${(batch as unknown as { output_lot_id: string }).output_lot_id}`}
                    className="font-mono text-xs text-emerald-700 underline"
                  >
                    {(batch as unknown as { output_lot_id: string }).output_lot_id.slice(0, 8)}…
                  </Link>
                </div>
                <p className="text-xs text-emerald-800">
                  {t("shop.production.batches.output_lot_help")}
                </p>

                {breakdown && breakdown.lines.length > 0 ? (
                  <div className="rounded border border-emerald-300 bg-white p-3">
                    <div className="mb-2 text-xs font-medium tracking-wide text-emerald-900 uppercase">
                      {t("shop.production.batches.cost_breakdown_title")}
                    </div>
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="border-b text-left text-muted-foreground">
                          <th className="py-1">{t("shop.production.batches.col.source_lot")}</th>
                          <th className="py-1 text-right">
                            {t("shop.production.batches.col.qty_consumed")}
                          </th>
                          <th className="py-1 text-right">
                            {t("shop.production.batches.col.lot_unit_cost")}
                          </th>
                          <th className="py-1 text-right">
                            {t("shop.production.batches.col.line_total")}
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {breakdown.lines.map((line) => (
                          <tr key={line.lot_id} className="border-b last:border-b-0">
                            <td className="py-1.5">
                              <div className="font-medium">
                                {line.lot_code ?? line.lot_id.slice(0, 8)}
                              </div>
                              {line.supplier_name ? (
                                <div className="text-[10px] text-muted-foreground">
                                  {line.supplier_name}
                                </div>
                              ) : null}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">
                              {formatNumber(line.qty_consumed)} {line.unit ?? ""}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">
                              {line.unit_cost !== null ? formatNumber(line.unit_cost) : "—"}
                            </td>
                            <td className="py-1.5 text-right font-medium tabular-nums">
                              {line.line_total !== null ? formatNumber(line.line_total) : "—"}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr className="border-t-2 border-emerald-400">
                          <td colSpan={3} className="py-2 text-right font-medium">
                            {t("shop.production.batches.total_consumed")}
                          </td>
                          <td className="py-2 text-right font-bold tabular-nums">
                            {formatNumber(breakdown.total_consumed_cost)} {breakdown.currency ?? ""}
                          </td>
                        </tr>
                        <tr>
                          <td colSpan={3} className="py-1 text-right text-muted-foreground">
                            {t("shop.production.batches.yield_qty")}
                          </td>
                          <td className="py-1 text-right tabular-nums">
                            {formatNumber(breakdown.yield_qty)} {batch.yield_unit}
                          </td>
                        </tr>
                        <tr className="bg-emerald-100">
                          <td colSpan={3} className="py-2 text-right font-medium">
                            {t("shop.production.batches.output_unit_cost")}
                          </td>
                          <td className="py-2 text-right font-bold text-emerald-900 tabular-nums">
                            {breakdown.output_unit_cost !== null
                              ? `${formatNumber(breakdown.output_unit_cost)} ${breakdown.currency ?? ""} / ${batch.yield_unit}`
                              : "—"}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                ) : null}
              </div>
            ) : null}
          </>
        )}
      </PageContent>

      <Dialog open={!!pendingAction} onOpenChange={(open) => !open && setPendingAction(null)}>
        <DialogContent aria-describedby={undefined} className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {pendingAction && t(`shop.production.batches.confirm.${pendingAction}`)}
            </DialogTitle>
            {pendingAction === "start" ? (
              <DialogDescription>
                {t("shop.production.batches.confirm.start_description")}
              </DialogDescription>
            ) : null}
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

      <Dialog
        open={completeOpen}
        onOpenChange={(open) => {
          setCompleteOpen(open);
          if (!open) {
            setActualYield("");
            setVarianceReason("");
          }
        }}
      >
        <DialogContent aria-describedby={undefined} className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shop.production.batches.complete_title")}</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.production.batches.actual_yield_label", {
                  unit: batch?.yield_unit ?? "",
                })}
              </label>
              <Input
                type="number"
                step="0.0001"
                min="0"
                value={actualYield}
                onChange={(e) => setActualYield(e.target.value)}
                className="h-9 text-sm"
              />
              {batch && proposedYield > 0 && (
                <p
                  className={`text-[11px] ${reasonRequired ? "text-amber-700" : "text-muted-foreground"}`}
                >
                  {t("shop.production.batches.variance_summary", {
                    planned: formatNumber(plannedYield),
                    unit: batch.yield_unit ?? "",
                    variance: variancePct.toFixed(2),
                    tolerance: tolerancePct.toFixed(2),
                  })}
                </p>
              )}
            </div>
            {reasonRequired && (
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-muted-foreground">
                  {t("shop.production.batches.variance_reason_label")}
                  <span className="ml-0.5 text-destructive">*</span>
                </label>
                <Textarea
                  value={varianceReason}
                  onChange={(e) => setVarianceReason(e.target.value)}
                  rows={3}
                  maxLength={1000}
                  className="field-sizing-fixed"
                  placeholder={t("shop.production.batches.variance_reason_placeholder")}
                />
                <p className="text-[11px] text-muted-foreground">
                  {t("shop.production.batches.variance_reason_help")}
                </p>
              </div>
            )}
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
              disabled={
                completeMutation.isPending ||
                !actualYield ||
                Number(actualYield) <= 0 ||
                (reasonRequired && !varianceReason.trim())
              }
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
