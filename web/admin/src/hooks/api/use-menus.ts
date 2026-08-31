/**
 * Menu Hooks — React wrappers around menuService.
 *
 * Pattern mirrors use-materials.ts:
 * - useMenus()              → useQuery   → menuService.list()
 * - useMenu()               → useQuery   → menuService.getById()
 * - useCreateMenu()         → useMutation → service.create() + toast
 * - useAddMenuProducts()    → useMutation → service.addProducts()
 * - useRemoveMenuProduct()  → useMutation → service.removeProduct()
 * - useToggleMenuProduct()  → useMutation → service.toggleProduct()
 * - useReorderMenuProducts()→ useMutation → service.reorderProducts()
 * - …
 *
 * Every mutation invalidates menuKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  menuService,
  type AddProductsInput,
  type CloneToBranchInput,
  type CreateMenuInput,
  type CreateMasterMenuInput,
  type MenuFilters,
  type ReorderProductsInput,
  type SyncLayoutInput,
  type UpdateMenuInput,
} from "@/services/menu-service";
import { useTranslation } from "@/providers/app-provider";
import { menuKeys, masterMenuKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useMenus(brandSlug: string, filters: MenuFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: menuKeys.list(brandSlug, locale, filters),
    queryFn: () => menuService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useMenu(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: menuKeys.detail(brandSlug, locale, id),
    queryFn: () => menuService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useMenuLookup(brandSlug: string, enabled = true) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [...menuKeys.all(brandSlug), "lookup", locale] as const,
    queryFn: () => menuService.lookup(brandSlug),
    enabled: !!brandSlug && enabled,
  });
}

export function useCurrentMenu(brandSlug: string, branchId: string, enabled = true) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: menuKeys.current(brandSlug, locale, branchId),
    queryFn: () => menuService.currentForBranch(brandSlug, branchId),
    enabled: !!brandSlug && !!branchId && enabled,
  });
}

export function useCheckSync(brandSlug: string, menuId: string, enabled = true) {
  return useQuery({
    queryKey: menuKeys.checkSync(brandSlug, menuId),
    queryFn: () => menuService.checkSync(brandSlug, menuId),
    enabled: !!brandSlug && !!menuId && enabled,
  });
}

// --- Master Menu Queries ---

export function useMasterMenus(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: masterMenuKeys.list(brandSlug, locale),
    queryFn: () => menuService.listMasterMenus(brandSlug),
    enabled: !!brandSlug,
  });
}

export function useMasterMenuLookup(brandSlug: string, enabled = true) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: masterMenuKeys.lookup(brandSlug, locale),
    queryFn: () => menuService.lookupMasterMenus(brandSlug),
    enabled: !!brandSlug && enabled,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMenuInput) => menuService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Menu created.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create menu."),
  });
}

export function useUpdateMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuInput }) =>
      menuService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Menu updated.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update menu."),
  });
}

export function useDeleteMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu deleted.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete menu."),
  });
}

export function useRestoreMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu restored.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore menu."),
  });
}

export function useBulkDeleteMenus(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => menuService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.menu.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.menu.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.menu.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateMenuTimeout(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (cartTimeoutMinutes: number | null) =>
      menuService.update(brandSlug, menuId, { cart_timeout_minutes: cartTimeoutMinutes }),
    onSuccess: () => {
      toast.success(t("hq.menus.timeout.toast_saved"));
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.timeout.toast_error")),
  });
}

/**
 * Set or clear a menu item's tax-type override (#1099).
 *
 * The one control that makes the single-rate model operable: a tax type is ONE
 * rate, so charging 8% on takeaway means assigning the reduced type to the
 * takeaway MENU's items. Until this shipped the endpoint existed but nothing in
 * the UI could reach it, so a shop that split its takeaway menu still billed
 * everything at the standard rate.
 */
export function useUpdateMenuProductTaxType(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      menuProductId,
      taxTypeId,
    }: {
      menuProductId: string;
      taxTypeId: string | null;
    }) => menuService.updateProductTaxType(brandSlug, menuId, menuProductId, taxTypeId),
    onSuccess: (_data, variables) => {
      toast.success(
        variables.taxTypeId === null
          ? t("hq.menus.items.tax.toast_cleared")
          : t("hq.menus.items.tax.toast_saved")
      );
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.items.tax.toast_error")),
  });
}

/**
 * #1218 tier 3 — one tax type for a WHOLE menu.
 *
 * This is what makes "the takeaway menu is 8%" a single action instead of an
 * edit per item. It beats the product by ruling, so it does re-rate items whose
 * catalog type says otherwise — including tax-exempt ones. The escape hatch is
 * the per-item override above, which still wins.
 */
export function useUpdateMenuTaxType(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (taxTypeId: string | null) =>
      menuService.updateMenuTaxType(brandSlug, menuId, taxTypeId),
    onSuccess: (_data, taxTypeId) => {
      toast.success(
        taxTypeId === null
          ? t("hq.menus.tax.toast_cleared")
          : t("hq.menus.tax.toast_saved")
      );
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.tax.toast_error")),
  });
}

/**
 * #1218 tier 2 — a tax type for one section WITHIN this menu.
 *
 * Writes to the menu↔section pivot, so the same section keeps its own value in
 * every other menu that shows it. Null inherits from the menu.
 */
export function useUpdateMenuSectionTaxType(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      menuSectionId,
      taxTypeId,
    }: {
      menuSectionId: string;
      taxTypeId: string | null;
    }) => menuService.updateSectionTaxType(brandSlug, menuId, menuSectionId, taxTypeId),
    onSuccess: (_data, variables) => {
      toast.success(
        variables.taxTypeId === null
          ? t("hq.menus.sections.tax.toast_cleared")
          : t("hq.menus.sections.tax.toast_saved")
      );
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.sections.tax.toast_error")),
  });
}

// =========================================================================
//  Mutations — State Transitions
// =========================================================================

export function useSubmitMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.submit(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu submitted for approval.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit menu."),
  });
}

export function useApproveMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.approve(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu approved.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to approve menu."),
  });
}

export function useRejectMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      menuService.reject(brandSlug, id, reason),
    onSuccess: () => {
      toast.success("Menu rejected.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to reject menu."),
  });
}

export function useActivateMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.activate(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu activated.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to activate menu."),
  });
}

export function useDeactivateMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuService.deactivate(brandSlug, id),
    onSuccess: () => {
      toast.success("Menu deactivated.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to deactivate menu."),
  });
}

// =========================================================================
//  Mutations — Menu Products
// =========================================================================

export function useAddMenuProducts(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: AddProductsInput }) =>
      menuService.addProducts(brandSlug, menuId, data),
    onSuccess: () => {
      toast.success("Products added.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to add products."),
  });
}

export function useRemoveMenuProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, menuProductId }: { menuId: string; menuProductId: string }) =>
      menuService.removeProduct(brandSlug, menuId, menuProductId),
    onSuccess: () => {
      toast.success("Product removed.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to remove product."),
  });
}

export function useToggleMenuProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, menuProductId }: { menuId: string; menuProductId: string }) =>
      menuService.toggleProduct(brandSlug, menuId, menuProductId),
    onSuccess: () => {
      toast.success("Product toggled.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle product."),
  });
}

export function useReorderMenuProducts(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: ReorderProductsInput }) =>
      menuService.reorderProducts(brandSlug, menuId, data),
    onSuccess: () => {
      toast.success("Products reordered.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to reorder products."),
  });
}

export function useSyncMenuLayout(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: SyncLayoutInput }) =>
      menuService.syncLayout(brandSlug, menuId, data),
    onSuccess: () => {
      toast.success("Menu layout saved.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to save menu layout."),
  });
}

// =========================================================================
//  Mutations — Master Menu Operations
// =========================================================================

export function useCloneMenuToBranch(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ masterMenuId, data }: { masterMenuId: string; data: CloneToBranchInput }) =>
      menuService.cloneToBranch(brandSlug, masterMenuId, data),
    onSuccess: () => {
      toast.success("Menu cloned from master.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to clone menu."),
  });
}

export function useDuplicateMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (menuId: string) => menuService.duplicate(brandSlug, menuId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to duplicate menu."),
  });
}

export function useSyncFromMaster(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (menuId: string) => menuService.syncFromMaster(brandSlug, menuId),
    onSuccess: () => {
      toast.success("Synced from master menu.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to sync from master."),
  });
}

export function useCreateMasterMenu(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMasterMenuInput) => menuService.createMasterMenu(brandSlug, data),
    onSuccess: () => {
      toast.success("Master menu created.");
      qc.invalidateQueries({ queryKey: masterMenuKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create master menu."),
  });
}
