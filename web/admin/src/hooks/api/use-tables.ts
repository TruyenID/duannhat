/**
 * Table Hooks — React Query wrappers around tableService.
 *
 * Every mutation shows toast.success or toast.error and invalidates
 * related queries (MANDATORY per frontend.md).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { tableService } from "@/services/table-service";
import type {
  ChangeTableStatusInput,
  CreateTableInput,
  TableFilters,
  UpdateTableInput,
} from "@/types/shop";
import { useTranslation } from "@/providers/app-provider";
import { tableKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useTables(
  shopSlug: string,
  filters: TableFilters = {},
  options: { refetchInterval?: number } = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: tableKeys.list(shopSlug, locale, filters),
    queryFn: () => tableService.list(shopSlug, filters),
    enabled: !!shopSlug,
    // Opt-in polling for the live floor plan. Table status is changed by OTHER
    // apps (kiosk/customer-web close an order → backend flips the table to
    // `cleaning`), so admin-web's cache never invalidates on its own. Without
    // polling the floor plan shows stale "Đang phục vụ" instead of "Đang dọn"
    // until a manual refresh.
    refetchInterval: options.refetchInterval,
    refetchOnWindowFocus: options.refetchInterval != null,
  });
}

export function useTable(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: tableKeys.detail(shopSlug, locale, id),
    queryFn: () => tableService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

export function useTableStatusHistory(shopSlug: string, id: string) {
  return useQuery({
    queryKey: tableKeys.statusHistory(shopSlug, id),
    queryFn: () => tableService.statusHistory(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateTable(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateTableInput) => tableService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Table created.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create table."),
  });
}

export function useUpdateTable(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTableInput }) =>
      tableService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Table updated.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update table."),
  });
}

export function useDeleteTable(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => tableService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Table deleted.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete table."),
  });
}

export function useRestoreTable(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => tableService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Table restored.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore table."),
  });
}

export function useToggleTableActive(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => tableService.toggleActive(shopSlug, id),
    onSuccess: (result) => {
      toast.success(result.data.is_active ? "Table activated." : "Table deactivated.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle table."),
  });
}

// =========================================================================
//  Workflow Mutations
// =========================================================================

export function useChangeTableStatus(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ChangeTableStatusInput }) =>
      tableService.changeStatus(shopSlug, id, data),
    onSuccess: (result) => {
      toast.success(`Table status: ${result.data.status.replace(/_/g, " ")}`);
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to change table status."),
  });
}

export function useRegenerateTableQr(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => tableService.regenerateQr(shopSlug, id),
    onSuccess: () => {
      toast.success("QR token regenerated.");
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to regenerate QR token."),
  });
}
