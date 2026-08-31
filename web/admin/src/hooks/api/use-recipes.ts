/**
 * Recipe Hooks — React wrappers around recipeService.
 *
 * Pattern mirrors use-materials.ts:
 * - useRecipes()       → useQuery    → recipeService.list()
 * - useRecipeLookup()  → useQuery    → recipeService.lookup()
 * - useCreateRecipe()  → useMutation → service.create() + toast
 * - …
 *
 * Every mutation invalidates recipeKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  recipeService,
  type CreateRecipeInput,
  type RecipeFilters,
  type UpdateRecipeInput,
} from "@/services/recipe-service";
import { useTranslation } from "@/providers/app-provider";
import { recipeKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useRecipes(brandSlug: string, filters: RecipeFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: recipeKeys.list(brandSlug, locale, filters),
    queryFn: () => recipeService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useRecipe(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: recipeKeys.detail(brandSlug, locale, id),
    queryFn: () => recipeService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useRecipeLookup(brandSlug: string, enabled = true) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [...recipeKeys.all(brandSlug), "lookup", locale] as const,
    queryFn: () => recipeService.lookup(brandSlug),
    enabled: !!brandSlug && enabled,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateRecipeInput) => recipeService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Recipe created.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create recipe."),
  });
}

export function useUpdateRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateRecipeInput }) =>
      recipeService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Recipe updated.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update recipe."),
  });
}

export function useDeleteRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => recipeService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Recipe deleted.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete recipe."),
  });
}

export function useRestoreRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => recipeService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Recipe restored.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore recipe."),
  });
}

// =========================================================================
//  Approval workflow (Phase 5 / plan-003)
// =========================================================================

export function useSubmitRecipeForApproval(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => recipeService.submitForApproval(brandSlug, id),
    onSuccess: () => {
      toast.success("Recipe submitted for approval.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit for approval."),
  });
}

export function useApproveRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => recipeService.approve(brandSlug, id),
    onSuccess: () => {
      toast.success("Recipe approved.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to approve recipe."),
  });
}

export function useRejectRecipe(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      recipeService.reject(brandSlug, id, reason),
    onSuccess: () => {
      toast.success("Recipe rejected.");
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to reject recipe."),
  });
}

export function useBulkDeleteRecipes(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => recipeService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.recipe.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.recipe.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.recipe.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: recipeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
