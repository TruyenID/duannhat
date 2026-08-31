"use client";

/**
 * HQ Tables page (issue #890) — brand-level default floor layout.
 *
 * CRUD over zone/table TEMPLATES. These are not physical tables: each shop
 * pulls them down via "Nhận bàn mặc định từ HQ" on Shop → Tables, which
 * copies them into real zones/tables (idempotent by code). Shops keep full
 * CRUD over their own tables afterwards.
 *
 * Layout mirrors the Shop Tables floor plan minus runtime concerns
 * (no QR, no status): one section per zone template, grid of table cards.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { Armchair, MoreHorizontal, Pencil, Plus, Power, Trash2 } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button, Card, CardContent, CardHeader, CardTitle, Spinner } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useTranslation } from "@/providers/app-provider";
import {
  useDeleteZoneTemplate,
  useToggleZoneTemplateActive,
  useZoneTemplates,
} from "@/hooks/api/use-zone-templates";
import {
  useDeleteTableTemplate,
  useTableTemplates,
  useToggleTableTemplateActive,
} from "@/hooks/api/use-table-templates";
import type { TableTemplateResource, ZoneTemplateResource } from "@/types/hq-tables";
import { cn } from "@/lib/utils";
import { TableTemplateFormDialog } from "./components/table-template-form-dialog";
import { ZoneTemplateFormDialog } from "./components/zone-template-form-dialog";
import { useShops } from "@/hooks/api/use-shops";

export default function HqTablesPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();

  const zonesQuery = useZoneTemplates(brandSlug, { per_page: 100 });
  const tablesQuery = useTableTemplates(brandSlug, { per_page: 500 });
  // Branch options for the "Chi nhánh áp dụng" select on both forms.
  const shopsQuery = useShops(brandSlug, { per_page: 100 });
  const shops = shopsQuery.data?.data ?? [];
  const { refetch, isFetching } = tablesQuery;

  const deleteZone = useDeleteZoneTemplate(brandSlug);
  const deleteTable = useDeleteTableTemplate(brandSlug);

  const zones = useMemo<ZoneTemplateResource[]>(
    () => zonesQuery.data?.data ?? [],
    [zonesQuery.data]
  );
  const tables = useMemo<TableTemplateResource[]>(
    () => tablesQuery.data?.data ?? [],
    [tablesQuery.data]
  );

  const tablesByZone = useMemo(() => {
    const map = new Map<string, TableTemplateResource[]>();
    for (const table of tables) {
      const list = map.get(table.zone_template.id) ?? [];
      list.push(table);
      map.set(table.zone_template.id, list);
    }
    return map;
  }, [tables]);

  const isLoading = zonesQuery.isLoading || tablesQuery.isLoading;
  const isEmpty = !isLoading && zones.length === 0;

  // Dialog state — create/edit share one dialog per entity; `editing*` null
  // means create mode.
  const [zoneDialogOpen, setZoneDialogOpen] = useState(false);
  const [editingZone, setEditingZone] = useState<ZoneTemplateResource | null>(null);
  const [tableDialogOpen, setTableDialogOpen] = useState(false);
  const [editingTable, setEditingTable] = useState<TableTemplateResource | null>(null);
  const [tableDialogZoneId, setTableDialogZoneId] = useState<string | undefined>();
  const [deletingZone, setDeletingZone] = useState<ZoneTemplateResource | null>(null);
  const [deletingTable, setDeletingTable] = useState<TableTemplateResource | null>(null);

  const openZoneDialog = (zone?: ZoneTemplateResource) => {
    setEditingZone(zone ?? null);
    setZoneDialogOpen(true);
  };

  const openTableDialog = (zoneId?: string, table?: TableTemplateResource) => {
    setEditingTable(table ?? null);
    setTableDialogZoneId(zoneId);
    setTableDialogOpen(true);
  };

  return (
    <>
      <PageHeader
        title={t("hq.tables.title")}
        description={
          isLoading
            ? t("hq.tables.loading")
            : t("hq.tables.summary", { zones: zones.length, tables: tables.length })
        }
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-1 text-xs"
          onClick={() => openZoneDialog()}
        >
          <Plus className="size-3.5" />
          {t("hq.tables.new_zone")}
        </Button>
        <Button
          size="sm"
          className="h-8 gap-1 text-xs"
          onClick={() => openTableDialog()}
          disabled={zones.length === 0}
        >
          <Plus className="size-3.5" />
          {t("hq.tables.new_table")}
        </Button>
      </PageHeader>

      <PageContent>
        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("hq.tables.loading")}
          </div>
        )}

        {isEmpty && (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-10 text-center">
            <Armchair className="size-8 text-muted-foreground" />
            <p className="mt-3 text-sm font-medium">{t("hq.tables.empty_title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {t("hq.tables.empty_description")}
            </p>
            <Button size="sm" className="mt-4 h-8 gap-1 text-xs" onClick={() => openZoneDialog()}>
              <Plus className="size-3.5" />
              {t("hq.tables.new_zone")}
            </Button>
          </div>
        )}

        <div className="space-y-6">
          {zones.map((zone) => (
            <ZoneTemplateSection
              key={zone.id}
              brandSlug={brandSlug}
              zone={zone}
              tables={tablesByZone.get(zone.id) ?? []}
              onAddTable={() => openTableDialog(zone.id)}
              onEditZone={() => openZoneDialog(zone)}
              onDeleteZone={() => setDeletingZone(zone)}
              onEditTable={(table) => openTableDialog(zone.id, table)}
              onDeleteTable={(table) => setDeletingTable(table)}
            />
          ))}
        </div>
      </PageContent>

      <ZoneTemplateFormDialog
        brandSlug={brandSlug}
        shops={shops}
        template={editingZone}
        open={zoneDialogOpen}
        onOpenChange={setZoneDialogOpen}
      />
      <TableTemplateFormDialog
        brandSlug={brandSlug}
        shops={shops}
        zoneTemplates={zones}
        template={editingTable}
        defaultZoneTemplateId={tableDialogZoneId}
        open={tableDialogOpen}
        onOpenChange={setTableDialogOpen}
      />

      <DeleteConfirmDialog
        open={!!deletingZone}
        onOpenChange={(open) => !open && setDeletingZone(null)}
        description={
          deletingZone ? t("hq.tables.delete_zone_confirm", { name: deletingZone.name }) : ""
        }
        isPending={deleteZone.isPending}
        onConfirm={() => {
          if (!deletingZone) return;
          deleteZone.mutate(deletingZone.id, { onSuccess: () => setDeletingZone(null) });
        }}
      />
      <DeleteConfirmDialog
        open={!!deletingTable}
        onOpenChange={(open) => !open && setDeletingTable(null)}
        description={
          deletingTable ? t("hq.tables.delete_table_confirm", { code: deletingTable.code }) : ""
        }
        isPending={deleteTable.isPending}
        onConfirm={() => {
          if (!deletingTable) return;
          deleteTable.mutate(deletingTable.id, { onSuccess: () => setDeletingTable(null) });
        }}
      />
    </>
  );
}

// ---------------------------------------------------------------------------
// Zone template section
// ---------------------------------------------------------------------------

interface ZoneTemplateSectionProps {
  brandSlug: string;
  zone: ZoneTemplateResource;
  tables: TableTemplateResource[];
  onAddTable: () => void;
  onEditZone: () => void;
  onDeleteZone: () => void;
  onEditTable: (table: TableTemplateResource) => void;
  onDeleteTable: (table: TableTemplateResource) => void;
}

function ZoneTemplateSection({
  brandSlug,
  zone,
  tables,
  onAddTable,
  onEditZone,
  onDeleteZone,
  onEditTable,
  onDeleteTable,
}: ZoneTemplateSectionProps) {
  const { t } = useTranslation();
  const toggleZoneActive = useToggleZoneTemplateActive(brandSlug);

  return (
    <section>
      <header className="mb-2 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <h2 className="text-sm font-semibold">{zone.name}</h2>
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
            {zone.code}
          </code>
          <span className="text-xs text-muted-foreground">
            {t("hq.tables.zone_tables_count", { count: tables.length })}
          </span>
          <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
            {zone.branch ? zone.branch.name : t("hq.tables.all_branches_badge")}
          </span>
          {!zone.is_active && (
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-400">
              {t("hq.tables.inactive")}
            </span>
          )}
        </div>
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={onAddTable}>
            <Plus className="size-3.5" />
            {t("hq.tables.add_table")}
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger className="inline-flex size-7 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
              <MoreHorizontal className="size-3.5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuItem className="h-8 gap-2 text-sm" onClick={onEditZone}>
                <Pencil className="size-3.5" />
                {t("common.edit")}
              </DropdownMenuItem>
              <DropdownMenuItem
                className="h-8 gap-2 text-sm"
                onClick={() => toggleZoneActive.mutate(zone.id)}
                disabled={toggleZoneActive.isPending}
              >
                <Power className="size-3.5" />
                {zone.is_active ? t("hq.tables.deactivate") : t("hq.tables.activate")}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="h-8 gap-2 text-sm text-destructive focus:text-destructive"
                onClick={onDeleteZone}
              >
                <Trash2 className="size-3.5" />
                {t("common.delete")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {tables.length === 0 ? (
        <div className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
          {t("hq.tables.zone_empty")}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5">
          {tables.map((table) => (
            <TableTemplateCard
              key={table.id}
              brandSlug={brandSlug}
              table={table}
              onEdit={() => onEditTable(table)}
              onDelete={() => onDeleteTable(table)}
            />
          ))}
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------
// Table template card
// ---------------------------------------------------------------------------

interface TableTemplateCardProps {
  brandSlug: string;
  table: TableTemplateResource;
  onEdit: () => void;
  onDelete: () => void;
}

function TableTemplateCard({ brandSlug, table, onEdit, onDelete }: TableTemplateCardProps) {
  const { t } = useTranslation();
  const toggleActive = useToggleTableTemplateActive(brandSlug);

  return (
    <Card
      className={cn("overflow-hidden transition-opacity", !table.is_active && "opacity-60")}
      data-testid={`table-template-card-${table.id}`}
    >
      <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 px-3 pt-3 pb-1">
        <div className="min-w-0">
          <CardTitle className="truncate text-sm font-semibold">{table.code}</CardTitle>
          {table.name && <p className="truncate text-xs text-muted-foreground">{table.name}</p>}
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger className="inline-flex size-6 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
            <MoreHorizontal className="size-3.5" />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-44">
            <DropdownMenuItem className="h-8 gap-2 text-sm" onClick={onEdit}>
              <Pencil className="size-3.5" />
              {t("common.edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              className="h-8 gap-2 text-sm"
              onClick={() => toggleActive.mutate(table.id)}
              disabled={toggleActive.isPending}
            >
              <Power className="size-3.5" />
              {table.is_active ? t("hq.tables.deactivate") : t("hq.tables.activate")}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="h-8 gap-2 text-sm text-destructive focus:text-destructive"
              onClick={onDelete}
            >
              <Trash2 className="size-3.5" />
              {t("common.delete")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>

      <CardContent className="flex flex-col gap-1 px-3 pt-1 pb-3 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          <Armchair className="size-3" />
          {t("hq.tables.seats", { count: table.seat_count })}
        </span>
        <span className="truncate">
          {table.branch ? table.branch.name : t("hq.tables.all_branches_badge")}
        </span>
      </CardContent>
    </Card>
  );
}
