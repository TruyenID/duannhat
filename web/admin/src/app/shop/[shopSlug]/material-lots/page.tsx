"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";

import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { useDebounce } from "@/hooks/use-debounce";
import { useShopMaterialLots } from "@/hooks/api/use-material-lots";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import type { MaterialLot, MaterialLotStatus } from "@/services/material-lot-service";
import {
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  StatusBadge,
} from "@godxjp/ui";

const ALL_STATUSES = "__all__";
const LOT_STATUSES: MaterialLotStatus[] = [
  "active",
  "quarantined",
  "depleted",
  "expired",
  "disposed",
];

export default function ShopMaterialLotsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ shopSlug: string }>();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<string>(ALL_STATUSES);
  const debouncedSearch = useDebounce(search, 300);

  // Reset to page 1 whenever the search term or status filter changes.
  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, statusFilter]);

  const status = statusFilter === ALL_STATUSES ? undefined : (statusFilter as MaterialLotStatus);
  const hasActiveFilters = !!debouncedSearch || statusFilter !== ALL_STATUSES;

  const { data, isLoading } = useShopMaterialLots(params.shopSlug, {
    page,
    per_page: perPage,
    search: debouncedSearch || undefined,
    status,
  });

  const columns: ColumnDef<MaterialLot>[] = [
    {
      // #1962 — the row number must carry the PAGE OFFSET.
      //
      // `row.index + 1` restarts at 1 on every page, so lot #1 on page 2 is a
      // different lot from #1 on page 1 while wearing the same number. Staff use
      // this column to call a row out loud during a stock count, so a repeated
      // number is worse than no number: it reads as a reference and is not one.
      //
      // `meta.from` is the backend's 1-based index of the first row ON THIS
      // PAGE, which is exactly the offset — and it stays right when the last
      // page is short. Every other list screen already does this.
      header: t("hq.products.col.stt"),
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {(data?.meta?.from ?? 1) + row.index}
        </span>
      ),
      size: 60,
    },
    {
      accessorKey: "lot_code",
      header: t("material_lot.col_lot_code"),
      cell: ({ row }) => (
        <Link
          href={`/shop/${params.shopSlug}/material-lots/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {row.original.lot_code}
        </Link>
      ),
    },
    {
      accessorKey: "material",
      header: t("material_lot.col_material"),
      cell: ({ row }) => row.original.material?.sku ?? row.original.material_id,
    },
    {
      accessorKey: "qty_on_hand",
      header: t("material_lot.col_qty_on_hand"),
      cell: ({ row }) => (
        <span className="tabular-nums">
          {Number(row.original.qty_on_hand).toLocaleString(locale)} {row.original.unit}
        </span>
      ),
    },
    {
      accessorKey: "expiry_date",
      header: t("material_lot.col_expiry"),
      cell: ({ row }) => formatDate(row.original.expiry_date, locale, timezone),
    },
    {
      accessorKey: "status",
      header: t("material_lot.col_status"),
      cell: ({ row }) => <StatusBadge status={t(`material_lot.status.${row.original.status}`)} />,
    },
  ];

  return (
    <>
      <PageHeader
        title={t("material_lot.shop_list_title")}
        description={t("material_lot.shop_list_subtitle")}
      >
        <Link href={`/shop/${params.shopSlug}/material-lots/receive`}>
          <Button>{t("material_lot.receive_button")}</Button>
        </Link>
        <HelpPanel
          title={t("material_lot.shop_list_title")}
          subtitle={t("help.panel.shop_material_lots.subtitle")}
          purpose={t("help.panel.shop_material_lots.purpose")}
          usage={[
            t("help.panel.shop_material_lots.usage.1"),
            t("help.panel.shop_material_lots.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_material_lots.checks.1"),
            t("help.panel.shop_material_lots.checks.2"),
            t("help.panel.shop_material_lots.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_material_lots.glossary.quarantined.term"),
              description: t("help.panel.shop_material_lots.glossary.quarantined.desc"),
            },
            {
              term: t("help.panel.shop_material_lots.glossary.depleted.term"),
              description: t("help.panel.shop_material_lots.glossary.depleted.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("material_lot.search_placeholder")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            setSearch("");
            setStatusFilter(ALL_STATUSES);
          }}
        >
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-36 text-xs" aria-label={t("material_lot.col_status")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_STATUSES}>{t("common.all_statuses")}</SelectItem>
              {LOT_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`material_lot.status.${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>
        {isLoading ? (
          <DataTableSkeleton columns={columns.length} rows={8} />
        ) : (
          <>
            <DataTable columns={columns} data={data?.data ?? []} />
            {data?.meta ? (
              <Pagination
                meta={data.meta}
                page={page}
                onPageChange={setPage}
                perPage={perPage}
                onPerPageChange={(newPerPage) => {
                  setPerPage(newPerPage);
                  setPage(1);
                }}
              />
            ) : null}
          </>
        )}
      </PageContent>
    </>
  );
}
