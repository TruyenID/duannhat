"use client";

/**
 * HQ Void Reasons — plan-051 (#1149). Brand-scoped master of the reasons
 * staff pick when voiding an order item (pos-web/workstation render the
 * ACTIVE rows as the void-dialog picker).
 *
 * index/create/update only — deactivation is `update {is_active: false}`;
 * there is NO delete: historical order lines reference reasons by id.
 * While the brand has ZERO rows, the backend ships a `suggestions` array of
 * five built-in starter reasons — rendered as one-click "create" cards.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { EllipsisVertical, Lightbulb, Pencil, Plus, Power } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Badge, Button, StatusBadge } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import {
  useCreateVoidReason,
  useToggleVoidReasonStatus,
  useVoidReasons,
} from "@/hooks/api/use-void-reasons";
import type {
  VoidReason,
  VoidReasonSuggestion,
  VoidStockEffect,
} from "@/services/void-reason-service";
import { VoidReasonFormDialog } from "./components/void-reason-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";

const STOCK_EFFECT_BADGE_CLASS: Record<VoidStockEffect, string> = {
  restock: "border-transparent bg-emerald-50 text-emerald-700",
  waste: "border-transparent bg-red-50 text-red-700",
  none: "border-transparent bg-slate-100 text-slate-600",
};

export default function VoidReasonsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<VoidReason | null>(null);

  const { data: response, isLoading, refetch, isFetching } = useVoidReasons(brandSlug);
  const items = useMemo<VoidReason[]>(() => response?.data ?? [], [response]);
  const suggestions = response?.suggestions ?? [];

  const createMutation = useCreateVoidReason(brandSlug);
  const toggleStatus = useToggleVoidReasonStatus(brandSlug);

  // One-click create from a built-in suggestion — the suggestion carries a
  // full ja/en/vi label map, so the row lands fully translated.
  function createFromSuggestion(suggestion: VoidReasonSuggestion, index: number) {
    createMutation.mutate({
      label: suggestion.label.ja,
      ja: { label: suggestion.label.ja },
      en: { label: suggestion.label.en },
      vi: { label: suggestion.label.vi },
      stock_effect: suggestion.stock_effect,
      requires_note: suggestion.requires_note,
      is_active: true,
      sort_order: index,
    });
  }

  const columns: ColumnDef<VoidReason>[] = useMemo(
    () => [
      {
        id: "stt",
        header: t("hq.products.col.stt"),
        size: 50,
        cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.index + 1}</span>,
      },
      {
        accessorKey: "label",
        header: t("hq.void_reasons.col.label"),
        size: 240,
        cell: ({ row }) => (
          <button
            type="button"
            className="text-left font-medium text-primary hover:underline"
            onClick={() => setEditing(row.original)}
          >
            {row.original.label}
          </button>
        ),
      },
      {
        id: "stock_effect",
        header: t("hq.void_reasons.col.stock_effect"),
        size: 140,
        cell: ({ row }) => (
          <Badge
            variant="outline"
            className={`h-5 px-1.5 text-xs font-medium ${STOCK_EFFECT_BADGE_CLASS[row.original.stock_effect]}`}
          >
            {t(`hq.void_reasons.stock_effect.${row.original.stock_effect}`)}
          </Badge>
        ),
      },
      {
        id: "requires_note",
        header: t("hq.void_reasons.col.requires_note"),
        size: 110,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.requires_note ? t("common.yes") : t("common.no")}
          </span>
        ),
      },
      {
        accessorKey: "sort_order",
        header: t("hq.void_reasons.col.sort_order"),
        size: 90,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground tabular-nums">
            {row.original.sort_order}
          </span>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => (
          <StatusBadge status={row.original.is_active ? "active" : "inactive"} />
        ),
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 130,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {formatDate(row.original.updated_at, locale, timezone)}
          </span>
        ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const reason = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setEditing(reason)}>
                  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    toggleStatus.mutate({ id: reason.id, currentIsActive: reason.is_active })
                  }
                >
                  <Power className="mr-2 size-3.5" />{" "}
                  {reason.is_active ? t("common.deactivate") : t("common.activate")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [toggleStatus, t, locale, timezone]
  );

  return (
    <>
      <PageHeader
        title={t("hq.void_reasons.title")}
        description={t("hq.void_reasons.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
      </PageHeader>

      <PageContent>
        {/* Built-in starter suggestions — present ONLY while the brand has
            zero rows. One-click create; the first real row makes them vanish. */}
        {!isLoading && suggestions.length > 0 && (
          <div className="mb-4 rounded-lg border border-dashed bg-muted/30 p-4">
            <div className="mb-1 flex items-center gap-2 text-sm font-medium">
              <Lightbulb className="size-4 text-amber-500" />
              {t("hq.void_reasons.suggestions.title")}
            </div>
            <p className="mb-3 text-xs text-muted-foreground">
              {t("hq.void_reasons.suggestions.desc")}
            </p>
            <div className="flex flex-wrap gap-2">
              {suggestions.map((suggestion, index) => (
                <div
                  key={suggestion.label.ja}
                  className="flex items-center gap-2 rounded-md border bg-card px-3 py-2"
                >
                  <div className="flex flex-col">
                    <span className="text-sm font-medium">
                      {suggestion.label[locale as keyof typeof suggestion.label] ??
                        suggestion.label.ja}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                      {t(`hq.void_reasons.stock_effect.${suggestion.stock_effect}`)}
                      {suggestion.requires_note
                        ? ` · ${t("hq.void_reasons.col.requires_note")}`
                        : ""}
                    </span>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1 text-xs"
                    disabled={createMutation.isPending}
                    onClick={() => createFromSuggestion(suggestion, index)}
                  >
                    <Plus className="size-3" />
                    {t("hq.void_reasons.suggestions.add")}
                  </Button>
                </div>
              ))}
            </div>
          </div>
        )}

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.void_reasons.empty")} />
        )}
      </PageContent>

      {/* Create dialog */}
      <VoidReasonFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        voidReason={null}
      />

      {/* Edit dialog */}
      <VoidReasonFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        voidReason={editing}
      />
    </>
  );
}
