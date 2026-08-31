/**
 * React Query hook cho màn tra cứu giao dịch (#2880 · T3 của #2876).
 *
 * `retry: false` có chủ đích, cùng lý do với `use-settlements.ts`: đây là phép
 * đọc TIỀN, và khi request hỏng người vận hành phải THẤY nó hỏng ngay để quyết
 * có tin màn hình hay không. Ba lần thử lại im lặng biến một lỗi cứng thành
 * mười giây spinner rồi vẫn báo lỗi — và tệ hơn, với `placeholderData` đang bật,
 * nó giữ số liệu của trang TRƯỚC trên màn hình suốt lúc đó.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { transactionService, type TransactionFilters } from "@/services/transaction-service";
import { transactionKeys } from "./query-keys";

export function useTransactions(brandSlug: string, filters: TransactionFilters = {}) {
  return useQuery({
    queryKey: transactionKeys.list(brandSlug, filters),
    queryFn: () => transactionService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
    retry: false,
  });
}
