"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useTranslation } from "@/providers/app-provider";
import { Plus, Search } from "lucide-react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import {
  useDeleteWarehouse,
  useRestoreWarehouse,
  useToggleWarehouseActive,
  useWarehouses,
} from "@/hooks/api/use-warehouses";
import { WarehouseType, type Warehouse } from "@/services/warehouse-service";
import { WarehouseListTable } from "./components/warehouse-table";

const ANY_TYPE = "__any_type__";
const ANY_STATUS = "__any_status__";
const ANY_BRANCH = "__any_branch__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

export default function WarehousesPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();
  const router = useRouter();

  const [search, setSearch] = useState("");
  const [type, setType] = useState<string>(ANY_TYPE);
  const [branchId, setBranchId] = useState<string>(ANY_BRANCH);
  const [activeStatus, setActiveStatus] = useState<string>(ANY_STATUS);
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebounce(search, 300);

  const [confirmDelete, setConfirmDelete] = useState<Warehouse | null>(null);
  const [confirmDeactivate, setConfirmDeactivate] = useState<Warehouse | null>(null);

  const deleteMutation = useDeleteWarehouse(shopSlug);
  const restoreMutation = useRestoreWarehouse(shopSlug);
  const toggleMutation = useToggleWarehouseActive(shopSlug);

  const filters = useMemo(() => {
    let is_active: boolean | undefined;
    let trashed: "with" | "only" | undefined;
    switch (activeStatus) {
      case "active":
        is_active = true;
        break;
      case "inactive":
        is_active = false;
        break;
      case "deleted":
        trashed = "only";
        break;
      default:
        // ANY_STATUS: no filter
        break;
    }
    return {
      page,
      search: debouncedSearch || undefined,
      type: type === ANY_TYPE ? undefined : (type as WarehouseType),
      branch_id: branchId === ANY_BRANCH ? undefined : branchId,
      is_active,
      trashed,
    };
  }, [page, debouncedSearch, type, branchId, activeStatus]);

  const { data, isLoading, error, refetch, isFetching } = useWarehouses(shopSlug, filters);
  const warehouses = data?.data ?? [];
  const meta = data?.meta;

  // Dedupe branches that appear on the current page's warehouses so the
  // branch filter exposes only options that will actually return rows.
  // The generated `Branch` type has no `name` yet (schema placeholder);
  // cast through unknown so we can display whatever the backend returns
  // until the schema is fleshed out.
  const branchOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const w of warehouses) {
      const b = w.branch as { id: string; name?: string | null } | null | undefined;
      if (b?.id && !seen.has(b.id)) {
        seen.set(b.id, b.name ?? b.id.slice(0, 8));
      }
    }
    return Array.from(seen.entries()).map(([id, name]) => ({ id, name }));
  }, [warehouses]);

  function openCreate() {
    router.push(`/shop/${shopSlug}/warehouses/new`);
  }

  function openEdit(w: Warehouse) {
    router.push(`/shop/${shopSlug}/warehouses/${w.id}/edit`);
  }

  return (
    <>
      <PageHeader title={t("shop.warehouses.title")} onRefresh={refetch} isRefreshing={isFetching}>
        <Button size="sm" onClick={openCreate}>
          <Plus className="mr-1.5 size-3.5" />
          {t("shop.warehouses.new")}
        </Button>
        <HelpPanel
          title={t("shop.warehouses.title")}
          subtitle={t("help.panel.shop_warehouses.subtitle")}
          purpose={t("help.panel.shop_warehouses.purpose")}
          usage={[t("help.panel.shop_warehouses.usage.1"), t("help.panel.shop_warehouses.usage.2")]}
          checks={[
            t("help.panel.shop_warehouses.checks.1"),
            t("help.panel.shop_warehouses.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_warehouses.glossary.types.term"),
              description: t("help.panel.shop_warehouses.glossary.types.desc"),
            },
            {
              term: t("help.panel.shop_warehouses.glossary.members.term"),
              description: t("help.panel.shop_warehouses.glossary.members.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Select
            value={type}
            onValueChange={(v) => {
              setType(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-36 text-sm">
              <SelectValue placeholder={t("common.all_types")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_TYPE}>{t("common.all_types")}</SelectItem>
              <SelectItem value={WarehouseType.Main}>{t("shop.warehouses.type.main")}</SelectItem>
              <SelectItem value={WarehouseType.Branch}>
                {t("shop.warehouses.type.branch")}
              </SelectItem>
              <SelectItem value={WarehouseType.Production}>
                {t("shop.warehouses.type.production")}
              </SelectItem>
            </SelectContent>
          </Select>

          <Select
            value={branchId}
            onValueChange={(v) => {
              setBranchId(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-40 text-sm">
              <SelectValue placeholder={t("shop.warehouses.filter.all_branches")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_BRANCH}>{t("shop.warehouses.filter.all_branches")}</SelectItem>
              {branchOptions.map((b) => (
                <SelectItem key={b.id} value={b.id}>
                  {b.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select
            value={activeStatus}
            onValueChange={(v) => {
              setActiveStatus(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-36 text-sm">
              <SelectValue placeholder={t("common.all_statuses")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_STATUS}>{t("common.all_statuses")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
              <SelectItem value="deleted">{t("shop.warehouses.filter.status_deleted")}</SelectItem>
            </SelectContent>
          </Select>

          <div className="relative w-64">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t("shop.warehouses.search_placeholder")}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="h-8 pl-8 text-sm"
            />
          </div>
        </div>

        {isLoading && (
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
            <span className="ml-2 text-sm text-muted-foreground">{t("common.loading")}</span>
          </div>
        )}

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("shop.warehouses.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <WarehouseListTable
              data={warehouses}
              emptyMessage={t("shop.warehouses.empty")}
              onEdit={openEdit}
              onDelete={(w) => setConfirmDelete(w)}
              onRestore={(w) => restoreMutation.mutate(w.id)}
              onToggleActive={(w) => {
                if (w.is_active) {
                  setConfirmDeactivate(w);
                } else {
                  toggleMutation.mutate(w.id);
                }
              }}
            />
            {meta && meta.last_page > 1 && (
              <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                <span>
                  {t("common.showing", {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                  })}
                </span>
                <div className="flex gap-1">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    {t("common.previous")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    {t("common.next")}
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </PageContent>

      <DeleteConfirmDialog
        open={!!confirmDelete}
        onOpenChange={(open) => {
          if (!open) setConfirmDelete(null);
        }}
        description={
          confirmDelete ? t("shop.warehouses.delete_confirm", { name: confirmDelete.name }) : ""
        }
        onConfirm={() => {
          if (confirmDelete) {
            deleteMutation.mutate(confirmDelete.id);
            setConfirmDelete(null);
          }
        }}
        isPending={deleteMutation.isPending}
      />

      <Dialog
        open={!!confirmDeactivate}
        onOpenChange={(open) => !open && setConfirmDeactivate(null)}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("common.confirm")}</DialogTitle>
            <DialogDescription>
              {confirmDeactivate &&
                t("shop.warehouses.deactivate_confirm", { name: confirmDeactivate.name })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setConfirmDeactivate(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() => {
                if (confirmDeactivate) {
                  toggleMutation.mutate(confirmDeactivate.id);
                  setConfirmDeactivate(null);
                }
              }}
              disabled={toggleMutation.isPending}
            >
              {toggleMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
