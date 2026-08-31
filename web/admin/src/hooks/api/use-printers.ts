/**
 * Printer Hooks — React Query wrappers for shop printer management.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  printerShopService,
  type CreatePrinterInput,
  type PrinterFilters,
  type UpdatePrinterInput,
} from "@/services/printer-service";
import { printerShopKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useShopPrinters(shopSlug: string, filters: PrinterFilters = {}) {
  return useQuery({
    queryKey: printerShopKeys.list(shopSlug, filters),
    queryFn: () => printerShopService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function useShopPrinter(shopSlug: string, id: string) {
  return useQuery({
    queryKey: printerShopKeys.detail(shopSlug, id),
    queryFn: () => printerShopService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateShopPrinter(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreatePrinterInput) => printerShopService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Printer created.");
      qc.invalidateQueries({ queryKey: printerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create printer."),
  });
}

export function useUpdateShopPrinter(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdatePrinterInput }) =>
      printerShopService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Printer updated.");
      qc.invalidateQueries({ queryKey: printerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update printer."),
  });
}

export function useDeleteShopPrinter(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => printerShopService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Printer deleted.");
      qc.invalidateQueries({ queryKey: printerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete printer."),
  });
}

export function useRestoreShopPrinter(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => printerShopService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Printer restored.");
      qc.invalidateQueries({ queryKey: printerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore printer."),
  });
}
