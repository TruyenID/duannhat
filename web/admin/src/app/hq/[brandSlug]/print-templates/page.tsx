"use client";

/**
 * HQ print template registry — plan-053 M4 (#1171), T4.1.
 *
 * One row per slip kind. The point of the screen is TR-01: a brand that has
 * published nothing is not broken, it is printing the system default — so that
 * state gets a badge of its own instead of an empty cell that reads like a bug.
 */

import { useMemo } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Button } from "@godxjp/ui";
import { FileText, Pencil } from "lucide-react";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useTranslation } from "@/providers/app-provider";
import { useBrandPrintTemplates } from "@/hooks/api/use-print-templates";
import type { BrandPrintTemplateSummary } from "@/types/models/PrintTemplate";

export default function BrandPrintTemplatesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isFetching, refetch } = useBrandPrintTemplates(brandSlug);
  const rows = useMemo(() => data?.data ?? [], [data]);
  const publishedCount = rows.filter((row) => !row.is_system_default).length;

  const columns: ColumnDef<BrandPrintTemplateSummary>[] = useMemo(
    () => [
      {
        accessorKey: "kind",
        header: t("print_templates.col.kind"),
        size: 240,
        cell: ({ row }) => (
          <Link
            href={`/hq/${brandSlug}/print-templates/${row.original.kind}`}
            className="flex items-center gap-1.5 font-medium text-primary hover:underline"
          >
            <FileText className="size-3.5" />
            {t(`print_templates.kind.${row.original.kind}`)}
          </Link>
        ),
      },
      {
        id: "status",
        header: t("print_templates.col.in_force"),
        size: 220,
        cell: ({ row }) => {
          const item = row.original;
          if (item.is_system_default) {
            return (
              <Badge
                color="info"
                variant="soft"
                className="text-[10px]"
                data-testid={`system-default-${item.kind}`}
              >
                {t("print_templates.badge.system_default")}
              </Badge>
            );
          }
          return (
            <span className="flex items-center gap-1.5">
              <Badge color="success" variant="soft" className="text-[10px]">
                {t("print_templates.badge.brand_version", { version: item.published_version ?? 0 })}
              </Badge>
            </span>
          );
        },
      },
      {
        id: "draft",
        header: t("print_templates.col.draft"),
        size: 110,
        cell: ({ row }) =>
          row.original.has_draft ? (
            <Badge color="warning" variant="soft" className="text-[10px]">
              {t("print_templates.badge.draft")}
            </Badge>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          ),
      },
      {
        id: "effective_from",
        header: t("print_templates.col.effective_from"),
        size: 170,
        cell: ({ row }) => (
          <span className="font-mono text-xs">
            {row.original.effective_from ?? t("print_templates.immediately")}
          </span>
        ),
      },
      {
        id: "shop_editable",
        header: t("print_templates.col.delegated"),
        size: 220,
        cell: ({ row }) => {
          const paths = row.original.shop_editable;
          if (paths.length === 0) {
            return (
              <span className="text-xs text-muted-foreground">
                {t("print_templates.delegated_none")}
              </span>
            );
          }
          return (
            <span className="text-xs" title={paths.join(", ")}>
              {t("print_templates.delegated_count", { count: paths.length })}
            </span>
          );
        },
      },
      {
        id: "actions",
        header: t("common.action"),
        size: 90,
        cell: ({ row }) => (
          <Button asChild size="sm" variant="ghost" className="h-7 gap-1 text-xs">
            <Link href={`/hq/${brandSlug}/print-templates/${row.original.kind}`}>
              <Pencil className="size-3.5" />
              {t("common.edit")}
            </Link>
          </Button>
        ),
      },
    ],
    [t, brandSlug]
  );

  return (
    <>
      <PageHeader
        title={t("print_templates.hq.title")}
        description={t("print_templates.hq.summary", {
          total: rows.length,
          published: publishedCount,
        })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      />

      <PageContent className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">{t("print_templates.hq.intro")}</p>

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={6} />
        ) : (
          <DataTable columns={columns} data={rows} emptyMessage={t("print_templates.hq.empty")} />
        )}
      </PageContent>
    </>
  );
}
