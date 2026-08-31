/**
 * Menu Section Hooks — React wrappers around menuSectionService.
 *
 * Sections are brand-level reusable entities; the N:N attachment to a menu is
 * managed via useSyncMenuSections (which targets PUT /menus/{menu}/sections).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  menuSectionService,
  type CreateMenuSectionInput,
  type MenuSectionFilters,
  type SyncMenuSectionsInput,
  type UpdateMenuSectionInput,
} from "@/services/menu-section-service";
import { useTranslation } from "@/providers/app-provider";
import { menuKeys, menuSectionKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useMenuSections(brandSlug: string, filters: MenuSectionFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: menuSectionKeys.list(brandSlug, locale, filters),
    queryFn: () => menuSectionService.list(brandSlug, filters),
    enabled: !!brandSlug,
  });
}

export function useMenuSection(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: menuSectionKeys.detail(brandSlug, locale, id),
    queryFn: () => menuSectionService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateMenuSection(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMenuSectionInput) => menuSectionService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Section created.");
      qc.invalidateQueries({ queryKey: menuSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create section."),
  });
}

export function useUpdateMenuSection(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuSectionInput }) =>
      menuSectionService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Section updated.");
      qc.invalidateQueries({ queryKey: menuSectionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update section."),
  });
}

export function useDeleteMenuSection(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuSectionService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Section deleted.");
      qc.invalidateQueries({ queryKey: menuSectionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete section."),
  });
}

// =========================================================================
//  Mutations — Menu attachment
// =========================================================================

export function useSyncMenuSections(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: SyncMenuSectionsInput }) =>
      menuSectionService.syncForMenu(brandSlug, menuId, data),
    onSuccess: () => {
      toast.success("Sections synced.");
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to sync sections."),
  });
}
