"use client";

/**
 * Shop Tables page — the floor plan view.
 *
 * Layout:
 *   PageHeader (+ New Zone, + New Table buttons, manager-gated)
 *   PageContent
 *     └── one section per Zone
 *           └── CSS grid of Table cards
 *                 ├── code + name + seat count
 *                 ├── clickable status badge (TableStatusMenu)
 *                 ├── small QR preview (TableQr)
 *                 └── action menu (toggle-active, regenerate-qr)
 *
 * Japanese compact density: text-sm, h-8 buttons, tight gaps (gap-2/gap-3).
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import {
  Armchair,
  DownloadCloud,
  MoreHorizontal,
  Pencil,
  Plus,
  Printer,
  QrCode,
  Power,
  Trash2,
} from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { Card, CardContent, CardHeader, CardTitle } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { ApplyDefaultsDialog } from "./components/apply-defaults-dialog";
import { PrintQrPostersDialog } from "./components/print-qr-posters-dialog";
import { TableFormDialog } from "./components/table-form-dialog";
import { TableQr } from "./components/table-qr";
import { TableStatusMenu } from "./components/table-status-menu";
import { ZoneFormDialog } from "./components/zone-form-dialog";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useShop } from "@/hooks/api/use-shops";
import { useZones } from "@/hooks/api/use-zones";
import {
  useDeleteTable,
  useRegenerateTableQr,
  useTables,
  useToggleTableActive,
} from "@/hooks/api/use-tables";
import type { TableResource, ZoneResource } from "@/types/shop";
import { cn } from "@/lib/utils";

import { Spinner } from "@godxjp/ui";
// ---------------------------------------------------------------------------
// Permission gate
// ---------------------------------------------------------------------------

/**
 * Gate "New Zone" / "New Table" / "Regenerate QR" UI to Shop Managers and
 * Org Admins. The authoritative check lives on the backend policy; this
 * hook is a UX hint only. TODO(plan-001 follow-up): wire this to the role
 * field on /api/v1/me/context once it is exposed.
 */
function useCanManageTables(): boolean {
  // Backend enforces the real check; until /me/context exposes role info we
  // optimistically show the manager controls for every signed-in user.
  return true;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function TablesPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();
  const canManage = useCanManageTables();

  const zonesQuery = useZones(shopSlug, { per_page: 100 });
  // #890 — HQ brand switch: when on, HQ-origin tables become editable here.
  const shopQuery = useShop(shopSlug);
  const canEditHqTables = shopQuery.data?.allow_shop_edit_hq_tables ?? false;
  // Poll the floor plan: table status is flipped by other apps (a kiosk /
  // customer-web payment closes an order → backend sets the table to
  // `cleaning`), so without polling admin-web shows stale status until a
  // manual refresh.
  const tablesQuery = useTables(shopSlug, { per_page: 500 }, { refetchInterval: 20_000 });
  const { refetch, isFetching } = tablesQuery;

  const zones = useMemo<ZoneResource[]>(() => zonesQuery.data?.data ?? [], [zonesQuery.data]);
  const tables = useMemo<TableResource[]>(() => tablesQuery.data?.data ?? [], [tablesQuery.data]);

  const tablesByZone = useMemo(() => {
    const map = new Map<string, TableResource[]>();
    for (const t of tables) {
      const list = map.get(t.zone.id) ?? [];
      list.push(t);
      map.set(t.zone.id, list);
    }
    return map;
  }, [tables]);

  const isLoading = zonesQuery.isLoading || tablesQuery.isLoading;
  const isEmpty = !isLoading && zones.length === 0;

  // Modal form state. The actual mutations live inside the dialog components
  // so the page does not need to know how zones / tables are created.
  const [zoneDialogOpen, setZoneDialogOpen] = useState(false);
  const [tableDialogOpen, setTableDialogOpen] = useState(false);
  const [tableDialogZoneId, setTableDialogZoneId] = useState<string | undefined>();
  const [editingTable, setEditingTable] = useState<TableResource | null>(null);
  const [defaultsDialogOpen, setDefaultsDialogOpen] = useState(false);
  const [printDialogOpen, setPrintDialogOpen] = useState(false);

  const openZoneDialog = () => {
    if (!canManage) return;
    setZoneDialogOpen(true);
  };

  const openTableDialog = (zoneId?: string, table?: TableResource) => {
    if (!canManage) return;
    setEditingTable(table ?? null);
    setTableDialogZoneId(zoneId);
    setTableDialogOpen(true);
  };

  return (
    <>
      <PageHeader
        title={t("shop.tables.title")}
        description={
          isLoading
            ? t("shop.tables.loading")
            : t("shop.tables.summary", { zones: zones.length, tables: tables.length })
        }
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        {canManage && (
          <>
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1 text-xs"
              onClick={() => setPrintDialogOpen(true)}
              disabled={tables.length === 0}
            >
              <Printer className="size-3.5" />
              {t("shop.tables.print_qr.button")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1 text-xs"
              onClick={() => setDefaultsDialogOpen(true)}
            >
              <DownloadCloud className="size-3.5" />
              {t("shop.tables.defaults.button")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1 text-xs"
              onClick={openZoneDialog}
            >
              <Plus className="size-3.5" />
              {t("shop.tables.new_zone")}
            </Button>
            <Button
              size="sm"
              className="h-8 gap-1 text-xs"
              onClick={() => openTableDialog()}
              disabled={zones.length === 0}
            >
              <Plus className="size-3.5" />
              {t("shop.tables.new_table")}
            </Button>
          </>
        )}
        <HelpPanel
          title={t("shop.tables.title")}
          subtitle={t("help.panel.shop_tables.subtitle")}
          purpose={t("help.panel.shop_tables.purpose")}
          usage={[
            t("help.panel.shop_tables.usage.1"),
            t("help.panel.shop_tables.usage.2"),
            t("help.panel.shop_tables.usage.3"),
            t("help.panel.shop_tables.usage.4"),
          ]}
          checks={[
            t("help.panel.shop_tables.checks.1"),
            t("help.panel.shop_tables.checks.2"),
            t("help.panel.shop_tables.checks.3"),
            t("help.panel.shop_tables.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_tables.glossary.hq_badge.term"),
              description: t("help.panel.shop_tables.glossary.hq_badge.desc"),
            },
            {
              term: t("help.panel.shop_tables.glossary.inactive.term"),
              description: t("help.panel.shop_tables.glossary.inactive.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("shop.tables.loading")}
          </div>
        )}

        {isEmpty && (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-10 text-center">
            <Armchair className="size-8 text-muted-foreground" />
            <p className="mt-3 text-sm font-medium">{t("shop.tables.empty_title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {t("shop.tables.empty_description")}
            </p>
            {canManage && (
              <Button size="sm" className="mt-4 h-8 gap-1 text-xs" onClick={openZoneDialog}>
                <Plus className="size-3.5" />
                {t("shop.tables.new_zone")}
              </Button>
            )}
          </div>
        )}

        <div className="space-y-6">
          {zones.map((zone) => (
            <ZoneSection
              key={zone.id}
              shopSlug={shopSlug}
              zone={zone}
              tables={tablesByZone.get(zone.id) ?? []}
              canManage={canManage}
              canEditHqTables={canEditHqTables}
              onAddTable={() => openTableDialog(zone.id)}
              onEditTable={(table) => openTableDialog(zone.id, table)}
            />
          ))}
        </div>
      </PageContent>

      {canManage && (
        <>
          <ZoneFormDialog
            shopSlug={shopSlug}
            open={zoneDialogOpen}
            onOpenChange={setZoneDialogOpen}
          />
          <TableFormDialog
            shopSlug={shopSlug}
            zones={zones}
            table={editingTable}
            defaultZoneId={tableDialogZoneId}
            open={tableDialogOpen}
            onOpenChange={setTableDialogOpen}
          />
          <ApplyDefaultsDialog
            shopSlug={shopSlug}
            open={defaultsDialogOpen}
            onOpenChange={setDefaultsDialogOpen}
          />
          <PrintQrPostersDialog
            shopSlug={shopSlug}
            brandLabel={shopQuery.data?.name ?? undefined}
            zones={zones}
            tables={tables}
            open={printDialogOpen}
            onOpenChange={setPrintDialogOpen}
          />
        </>
      )}
    </>
  );
}

// ---------------------------------------------------------------------------
// Zone section
// ---------------------------------------------------------------------------

interface ZoneSectionProps {
  shopSlug: string;
  zone: ZoneResource;
  tables: TableResource[];
  canManage: boolean;
  canEditHqTables: boolean;
  onAddTable: () => void;
  onEditTable: (table: TableResource) => void;
}

function ZoneSection({
  shopSlug,
  zone,
  tables,
  canManage,
  canEditHqTables,
  onAddTable,
  onEditTable,
}: ZoneSectionProps) {
  const { t } = useTranslation();

  return (
    <section>
      <header className="mb-2 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <h2 className="text-sm font-semibold">{zone.name}</h2>
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
            {zone.code}
          </code>
          <span className="text-xs text-muted-foreground">
            {t("shop.tables.zone_tables_count", { count: tables.length })}
          </span>
          {!zone.is_active && (
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-400">
              {t("shop.tables.inactive")}
            </span>
          )}
        </div>
        {canManage && (
          <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={onAddTable}>
            <Plus className="size-3.5" />
            {t("shop.tables.add_table")}
          </Button>
        )}
      </header>

      {tables.length === 0 ? (
        <div className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
          {t("shop.tables.zone_empty")}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5">
          {tables.map((table) => (
            <TableCard
              key={table.id}
              shopSlug={shopSlug}
              table={table}
              canManage={canManage}
              canEditHqTables={canEditHqTables}
              onEdit={() => onEditTable(table)}
            />
          ))}
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------
// Table card
// ---------------------------------------------------------------------------

interface TableCardProps {
  shopSlug: string;
  table: TableResource;
  canManage: boolean;
  /** #890 — HQ brand switch: HQ-origin tables editable when true. */
  canEditHqTables: boolean;
  onEdit: () => void;
}

function TableCard({ shopSlug, table, canManage, canEditHqTables, onEdit }: TableCardProps) {
  const { t } = useTranslation();
  const [showLargeQr, setShowLargeQr] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const toggleActive = useToggleTableActive(shopSlug);
  const regenerateQr = useRegenerateTableQr(shopSlug);
  const deleteTable = useDeleteTable(shopSlug);

  // issue #890 / BR-T09: tables copied from the HQ default layout cannot be
  // deleted by the shop (backend returns 409) — hide the action entirely.
  const isFromHq = table.table_template_id != null;

  return (
    <Card
      className={cn("overflow-hidden transition-opacity", !table.is_active && "opacity-60")}
      data-testid={`table-card-${table.id}`}
    >
      <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 px-3 pt-3 pb-1">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5">
            <CardTitle className="truncate text-sm font-semibold">{table.code}</CardTitle>
            {isFromHq && (
              <span
                className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                title={t("shop.tables.hq_badge_hint")}
              >
                {t("shop.tables.hq_badge")}
              </span>
            )}
          </div>
          {table.name && <p className="truncate text-xs text-muted-foreground">{table.name}</p>}
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger className="inline-flex size-6 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
            <MoreHorizontal className="size-3.5" />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-44">
            <DropdownMenuItem
              className="h-8 gap-2 text-sm"
              onClick={() => setShowLargeQr((v) => !v)}
            >
              <QrCode className="size-3.5" />
              {showLargeQr ? t("shop.tables.hide_large_qr") : t("shop.tables.show_large_qr")}
            </DropdownMenuItem>
            {canManage && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="h-8 gap-2 text-sm"
                  onClick={() => toggleActive.mutate(table.id)}
                  disabled={toggleActive.isPending}
                >
                  <Power className="size-3.5" />
                  {table.is_active ? t("shop.tables.deactivate") : t("shop.tables.activate")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  className="h-8 gap-2 text-sm"
                  onClick={() => regenerateQr.mutate(table.id)}
                  disabled={regenerateQr.isPending}
                >
                  <QrCode className="size-3.5" />
                  {t("shop.tables.regenerate_qr")}
                </DropdownMenuItem>
                {(!isFromHq || canEditHqTables) && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem className="h-8 gap-2 text-sm" onClick={onEdit}>
                      <Pencil className="size-3.5" />
                      {t("common.edit")}
                    </DropdownMenuItem>
                  </>
                )}
                {!isFromHq && (
                  <DropdownMenuItem
                    className="h-8 gap-2 text-sm text-destructive focus:text-destructive"
                    onClick={() => setConfirmDelete(true)}
                    disabled={deleteTable.isPending}
                  >
                    <Trash2 className="size-3.5" />
                    {t("common.delete")}
                  </DropdownMenuItem>
                )}
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>

      <DeleteConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        description={t("shop.tables.delete_table_confirm", { code: table.code })}
        isPending={deleteTable.isPending}
        onConfirm={() => {
          deleteTable.mutate(table.id, { onSuccess: () => setConfirmDelete(false) });
        }}
      />

      <CardContent className="flex items-center gap-3 px-3 pt-1 pb-3">
        <TableQr
          qrToken={table.qr_token}
          shopSlug={shopSlug}
          size={showLargeQr ? 128 : 56}
          className="shrink-0 rounded border bg-white p-1"
          title={t("shop.tables.qr_alt", { code: table.code })}
        />
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex items-center gap-1 text-xs text-muted-foreground">
            <Armchair className="size-3" />
            {t("shop.tables.seats", { count: table.seat_count })}
          </div>
          <TableStatusMenu
            shopSlug={shopSlug}
            tableId={table.id}
            currentStatus={table.status}
            disabled={!table.is_active}
          />
        </div>
      </CardContent>
    </Card>
  );
}
