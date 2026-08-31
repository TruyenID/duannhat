/**
 * Topping Group Hooks — React-Query wrappers around toppingGroupService.
 *
 * Pattern mirrors use-categories.ts: every mutation toasts on success/failure
 * and invalidates toppingGroupKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import {
  toppingGroupService,
  type ToppingGroupFilters,
  type CreateToppingGroupInput,
  type UpdateToppingGroupInput,
  type OverrideSyncRow,
} from "@/services/topping-group-service";
import { useTranslation } from "@/providers/app-provider";
import { toppingGroupKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useToppingGroups(brandSlug: string, filters: ToppingGroupFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: toppingGroupKeys.list(brandSlug, locale, filters),
    queryFn: () => toppingGroupService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useToppingGroup(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: toppingGroupKeys.detail(brandSlug, locale, id),
    queryFn: () => toppingGroupService.getById(brandSlug, id),
    enabled: !!id,
  });
}

export function useToppingGroupLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: toppingGroupKeys.lookup(brandSlug, locale),
    queryFn: () => toppingGroupService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

export function useToppingGroupItems(brandSlug: string, groupId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: toppingGroupKeys.items(brandSlug, locale, groupId),
    queryFn: () => toppingGroupService.listItems(brandSlug, groupId),
    enabled: !!groupId,
  });
}

export function useToppingGroupItemSkus(brandSlug: string, groupId: string, itemId: string) {
  return useQuery({
    queryKey: toppingGroupKeys.itemSkus(brandSlug, groupId, itemId),
    queryFn: () => toppingGroupService.listItemSkus(brandSlug, groupId, itemId),
    enabled: !!itemId,
  });
}

export function useToppingGroupsForProduct(brandSlug: string, productId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: toppingGroupKeys.forProduct(brandSlug, locale, productId),
    queryFn: () => toppingGroupService.listForProduct(brandSlug, productId),
    enabled: !!productId,
  });
}

// =========================================================================
//  Mutations — ToppingGroup CRUD
// =========================================================================

export function useCreateToppingGroup(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateToppingGroupInput) => toppingGroupService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("toast.topping_group.created"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateToppingGroup(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateToppingGroupInput }) =>
      toppingGroupService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("toast.topping_group.updated"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteToppingGroup(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => toppingGroupService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success(t("toast.topping_group.deleted"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    // 409 TOPPING_GROUP_IN_USE is surfaced by the caller (detail page) via
    // a Dialog that lists the referencing products, so the caller catches
    // status 409 to render the richer UI. We still toast for non-409 errors.
    onError: (e: Error) => {
      if (e instanceof ApiError && e.status === 409) return;
      toast.error(e.message);
    },
  });
}

export function useBulkDeleteToppingGroups(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => toppingGroupService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });

      const inUseErrors = data.errors.filter((e) => e.error === "TOPPING_GROUP_IN_USE");
      const otherErrors = data.errors.filter((e) => e.error !== "TOPPING_GROUP_IN_USE");

      if (data.deleted > 0 && data.errors.length === 0) {
        toast.success(t("toast.topping_group.bulk_deleted", { n: data.deleted }));
        return;
      }

      if (data.deleted === 0 && inUseErrors.length > 0 && otherErrors.length === 0) {
        toast.error(t("toast.topping_group.bulk_in_use_all", { n: inUseErrors.length }), {
          description: summarizeInUse(inUseErrors),
        });
        return;
      }

      if (data.deleted > 0 && inUseErrors.length > 0 && otherErrors.length === 0) {
        toast.warning(
          t("toast.topping_group.bulk_partial_in_use", {
            deleted: data.deleted,
            skipped: inUseErrors.length,
          }),
          { description: summarizeInUse(inUseErrors) }
        );
        return;
      }

      // Mixed: at least one non-in-use error (e.g. authorization, not found).
      toast.error(
        t("toast.topping_group.bulk_partial_failed", {
          deleted: data.deleted,
          failed: data.errors.length,
        })
      );
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

/**
 * Build a short, human-readable description of which groups were skipped
 * because they're still attached to products. Caps the displayed product
 * names at 5 to avoid an unreadable toast — anything past that gets a
 * "…and N more" tail.
 */
function summarizeInUse(errors: Array<{ used_by?: { id: string; name: string }[] }>): string {
  const names = errors.flatMap((e) => (e.used_by ?? []).map((p) => p.name));
  const unique = Array.from(new Set(names));
  if (unique.length === 0) return "";
  if (unique.length <= 5) return unique.join(", ");
  return `${unique.slice(0, 5).join(", ")} +${unique.length - 5}`;
}

export function useReorderToppingGroups(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => toppingGroupService.reorder(brandSlug, ids),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(t("toast.topping_group.reorder_failed")),
  });
}

export function useToggleToppingGroupStatus(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, is_active }: { id: string; is_active: boolean }) =>
      toppingGroupService.update(brandSlug, id, { is_active }),
    onSuccess: (_data, variables) => {
      toast.success(
        variables.is_active
          ? t("toast.topping_group.activated")
          : t("toast.topping_group.deactivated")
      );
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useRestoreToppingGroup(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => toppingGroupService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success(t("toast.topping_group.restored"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Mutations — Items
// =========================================================================

export function useAddToppingGroupItem(brandSlug: string, groupId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: { product_id: string; sort_order?: number }) =>
      toppingGroupService.addItem(brandSlug, groupId, data),
    onSuccess: () => {
      toast.success(t("toast.topping_group.item_added"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteToppingGroupItem(brandSlug: string, groupId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (itemId: string) => toppingGroupService.deleteItem(brandSlug, groupId, itemId),
    onSuccess: () => {
      toast.success(t("toast.topping_group.item_removed"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

/** Full-sync the product panel in one save (add + reorder + remove). The detail
 *  page stages drag/remove/reorder client-side and calls this once on "Lưu". */
export function useSyncToppingGroupItems(brandSlug: string, groupId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (productIds: string[]) =>
      toppingGroupService.syncItems(brandSlug, groupId, productIds),
    onSuccess: () => {
      toast.success(t("toast.topping_group.items_saved"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useReorderToppingGroupItems(brandSlug: string, groupId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (itemIds: string[]) =>
      toppingGroupService.reorderItems(brandSlug, groupId, itemIds),
    onSuccess: () => {
      toast.success(t("toast.topping_group.items_reordered"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Mutations — Item SKUs
// =========================================================================

export function useAddToppingGroupItemSku(brandSlug: string, groupId: string, itemId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: { product_sku_id?: string | null; extra_price: number }) =>
      toppingGroupService.addItemSku(brandSlug, groupId, itemId, data),
    onSuccess: () => {
      toast.success(t("toast.topping_group.sku_added"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.itemSkus(brandSlug, groupId, itemId) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateToppingGroupItemSku(brandSlug: string, groupId: string, itemId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ itemSkuId, extra_price }: { itemSkuId: string; extra_price: number }) =>
      toppingGroupService.updateItemSku(brandSlug, groupId, itemId, itemSkuId, { extra_price }),
    onSuccess: () => {
      toast.success(t("toast.topping_group.sku_updated"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.itemSkus(brandSlug, groupId, itemId) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteToppingGroupItemSku(brandSlug: string, groupId: string, itemId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (itemSkuId: string) =>
      toppingGroupService.deleteItemSku(brandSlug, groupId, itemId, itemSkuId),
    onSuccess: () => {
      toast.success(t("toast.topping_group.sku_removed"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.itemSkus(brandSlug, groupId, itemId) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Mutations — Product ↔ ToppingGroup sync
// =========================================================================

export function useSyncProductToppingGroups(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: { topping_group_ids: string[]; sort_orders?: Record<string, number> }) =>
      toppingGroupService.syncForProduct(brandSlug, productId, data),
    onSuccess: () => {
      toast.success(t("toast.topping_group.synced"));
      qc.invalidateQueries({ queryKey: toppingGroupKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Queries + Mutations — Per-product item overrides
// =========================================================================

export function useToppingGroupOverrides(brandSlug: string, productId: string, groupId: string) {
  return useQuery({
    queryKey: toppingGroupKeys.overrides(brandSlug, productId, groupId),
    queryFn: () => toppingGroupService.listOverrides(brandSlug, productId, groupId),
    enabled: !!productId && !!groupId,
  });
}

export function useSyncToppingGroupOverrides(
  brandSlug: string,
  productId: string,
  groupId: string
) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (overrides: OverrideSyncRow[]) =>
      toppingGroupService.syncOverrides(brandSlug, productId, groupId, overrides),
    onSuccess: () => {
      toast.success(t("toast.topping_group.overrides_saved"));
      qc.invalidateQueries({
        queryKey: toppingGroupKeys.overrides(brandSlug, productId, groupId),
      });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
