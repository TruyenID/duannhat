/**
 * Disposal Hooks — wraps stock-transaction hooks with the disposal
 * sub_type baked in. Mutation hooks re-export the stock-transaction
 * mutations verbatim because disposals share the same workflow
 * (submit/approve/cancel/delete).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  disposalService,
  type DisposalCreateInput,
  type DisposalFilters,
  type WasteReportFilters,
} from "@/services/disposal-service";
import {
  StockTransactionStatus,
  StockTransactionSubType,
  StockTransactionType,
} from "@/services/stock-transaction-service";
import { useTranslation } from "@/providers/app-provider";
import { stockTransactionKeys } from "./query-keys";

// Query key factory for disposals. We nest under the stock-transaction
// tree so invalidating stock-transactions.all also refreshes disposals.
const disposalListKey = (shopSlug: string, locale: string, filters?: object) =>
  [
    ...stockTransactionKeys.all(shopSlug),
    "list",
    {
      ...filters,
      type: StockTransactionType.StockOut,
      sub_type: StockTransactionSubType.Disposal,
    },
    locale,
  ] as const;

// =========================================================================
//  Queries
// =========================================================================

export function useDisposals(shopSlug: string, filters: DisposalFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: disposalListKey(shopSlug, locale, filters),
    queryFn: () => disposalService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useDisposal(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockTransactionKeys.detail(shopSlug, locale, id),
    queryFn: () => disposalService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

/**
 * #3076 — báo cáo hao hụt.
 *
 * Hook này TỪNG không tồn tại, và cả trang `waste-report` bị comment sạch để
 * build đi qua — `export default function WasteReportPage() { return null }`.
 * Nút dẫn tới nó vẫn hiển thị ở màn danh sách, nên quán bấm vào và nhận một
 * TRANG TRẮNG: không lỗi, không thông báo, không dấu vết cho ai đi tìm.
 *
 * Đó là dạng hỏng qua được mọi cổng — TypeScript xanh, lint xanh, build xanh —
 * nên nó sống được vô hạn. Phần khó (endpoint + service) đã có sẵn từ lâu;
 * thiếu đúng mười dòng này ở giữa.
 */
export function useDisposalWasteReport(
  shopSlug: string,
  filters: WasteReportFilters
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [
      ...stockTransactionKeys.all(shopSlug),
      "waste-report",
      filters,
      locale,
    ] as const,
    queryFn: () => disposalService.wasteReport(shopSlug, filters),
    // `date_from`/`date_to` là bắt buộc ở backend (`required|date`), nên gọi khi
    // còn thiếu là một lượt 422 chắc chắn.
    enabled: !!shopSlug && !!filters.date_from && !!filters.date_to,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateDisposal(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: DisposalCreateInput) => disposalService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Disposal created.");
      qc.invalidateQueries({ queryKey: stockTransactionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create disposal."),
  });
}

export function useDeleteDisposal(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => disposalService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Disposal deleted.");
      qc.invalidateQueries({ queryKey: stockTransactionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete disposal."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useSubmitDisposal(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => disposalService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Disposal submitted for approval.");
      qc.invalidateQueries({ queryKey: stockTransactionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit disposal."),
  });
}

export function useApproveDisposal(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => disposalService.approve(shopSlug, id),
    onSuccess: () => {
      toast.success("Disposal approved — stock written off.");
      qc.invalidateQueries({ queryKey: stockTransactionKeys.all(shopSlug) });
    },
    // Approve may fail with a shortages response — leave the error
    // unhandled so the detail page can surface a modal later.
  });
}

export function useCancelDisposal(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => disposalService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Disposal cancelled.");
      qc.invalidateQueries({ queryKey: stockTransactionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel disposal."),
  });
}

// Re-export for convenience so callers don't need two imports.
export { StockTransactionStatus };
