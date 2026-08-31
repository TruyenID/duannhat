"use client";

/**
 * Shop print template overrides — plan-053 M4 (#1171), T4.2.
 *
 * The screen answers two questions the brand layer cannot:
 *   TR-02  "what does THIS shop change?" — the overridden paths, spelled out,
 *          not a bare "customised" flag.
 *   TR-04  "why did my change stop applying?" — when the brand narrows its
 *          `shop_editable`, the shop's stored override survives but stops
 *          taking effect. A published override that currently changes nothing
 *          is therefore a warning, not an empty cell.
 */

import { useMemo } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Button } from "@godxjp/ui";
import { FileText, Lock, Pencil, TriangleAlert } from "lucide-react";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { useTranslation } from "@/providers/app-provider";
import { useShopPrintTemplates } from "@/hooks/api/use-print-templates";
import type { ShopPrintTemplateSummary } from "@/types/models/PrintTemplate";

/** A published override that currently changes nothing = the brand narrowed the allow-list. */
function isSilencedOverride(row: ShopPrintTemplateSummary): boolean {
  return row.override_version !== null && row.overridden_paths.length === 0;
}

export default function ShopPrintTemplatesPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isFetching, refetch } = useShopPrintTemplates(shopSlug);
  const rows = useMemo(() => data?.data ?? [], [data]);
  const silenced = rows.filter(isSilencedOverride);

  const columns: ColumnDef<ShopPrintTemplateSummary>[] = useMemo(
    () => [
      {
        accessorKey: "kind",
        header: t("print_templates.col.kind"),
        size: 220,
        cell: ({ row }) => (
          <Link
            href={`/shop/${shopSlug}/print-templates/${row.original.kind}`}
            className="flex items-center gap-1.5 font-medium text-primary hover:underline"
          >
            <FileText className="size-3.5" />
            {t(`print_templates.kind.${row.original.kind}`)}
          </Link>
        ),
      },
      {
        id: "effective_scope",
        header: t("print_templates.col.in_force"),
        size: 160,
        cell: ({ row }) => {
          const scope = row.original.effective_scope;
          const color = scope === "shop" ? "success" : scope === "brand" ? "info" : "warning";
          return (
            <Badge color={color} variant="soft" className="text-[10px]">
              {t(`print_templates.scope.${scope}`)}
            </Badge>
          );
        },
      },
      {
        id: "versions",
        header: t("print_templates.col.versions"),
        size: 170,
        cell: ({ row }) => (
          <span className="font-mono text-xs text-muted-foreground">
            {t("print_templates.col.brand_short")} v{row.original.brand_version ?? "—"}
            {" · "}
            {t("print_templates.col.shop_short")} v{row.original.override_version ?? "—"}
          </span>
        ),
      },
      {
        id: "overrides",
        header: t("print_templates.col.overrides"),
        size: 300,
        cell: ({ row }) => {
          const item = row.original;
          if (isSilencedOverride(item)) {
            return (
              <span
                className="flex items-center gap-1 text-xs text-amber-600"
                data-testid={`silenced-${item.kind}`}
              >
                <TriangleAlert className="size-3.5" />
                {t("print_templates.shop.override_silenced_short", {
                  version: item.override_version ?? 0,
                })}
              </span>
            );
          }
          if (item.overridden_paths.length === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <span className="text-xs" data-testid={`overrides-${item.kind}`}>
              {t("print_templates.shop.overriding", { count: item.overridden_paths.length })}
              <span className="ml-1 font-mono text-[11px] text-muted-foreground">
                {item.overridden_paths.join(", ")}
              </span>
            </span>
          );
        },
      },
      {
        id: "allow_list",
        header: t("print_templates.col.allow_list"),
        size: 190,
        cell: ({ row }) =>
          row.original.is_locked_by_brand ? (
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Lock className="size-3.5" />
              {t("print_templates.shop.locked_by_brand")}
            </span>
          ) : (
            <span className="text-xs" title={row.original.shop_editable.join(", ")}>
              {t("print_templates.delegated_count", { count: row.original.shop_editable.length })}
            </span>
          ),
      },
      {
        id: "actions",
        header: t("common.action"),
        size: 90,
        cell: ({ row }) => (
          <Button
            asChild
            size="sm"
            variant="ghost"
            className="h-7 gap-1 text-xs"
            disabled={row.original.is_locked_by_brand}
          >
            <Link href={`/shop/${shopSlug}/print-templates/${row.original.kind}`}>
              <Pencil className="size-3.5" />
              {t("common.edit")}
            </Link>
          </Button>
        ),
      },
    ],
    [t, shopSlug]
  );

  return (
    <>
      <PageHeader
        title={t("print_templates.shop.title")}
        description={t("print_templates.shop.summary", {
          overriding: rows.filter((row) => row.overridden_paths.length > 0).length,
          total: rows.length,
        })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("print_templates.shop.title")}
          subtitle={t("help.panel.shop_print_templates.subtitle")}
          purpose={t("help.panel.shop_print_templates.purpose")}
          usage={[
            t("help.panel.shop_print_templates.usage.1"),
            t("help.panel.shop_print_templates.usage.2"),
            t("help.panel.shop_print_templates.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_print_templates.checks.1"),
            t("help.panel.shop_print_templates.checks.2"),
            t("help.panel.shop_print_templates.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_print_templates.glossary.versions.term"),
              description: t("help.panel.shop_print_templates.glossary.versions.desc"),
            },
            {
              term: t("help.panel.shop_print_templates.glossary.allow_list.term"),
              description: t("help.panel.shop_print_templates.glossary.allow_list.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">{t("print_templates.shop.intro")}</p>

        {silenced.length > 0 && (
          <div
            className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
            data-testid="silenced-banner"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
              {t("print_templates.shop.override_silenced_banner", {
                kinds: silenced.map((row) => t(`print_templates.kind.${row.kind}`)).join("、"),
              })}
            </span>
          </div>
        )}

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={6} />
        ) : (
          <DataTable columns={columns} data={rows} emptyMessage={t("print_templates.hq.empty")} />
        )}
      </PageContent>
    </>
  );
}
