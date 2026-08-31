/**
 * Disposal Service — thin wrapper around stockTransactionService.
 *
 * Architectural note: in the v1 shop API, a "disposal" is a
 * `StockTransaction` with `type = stock_out` and `sub_type = disposal`.
 * The DisposalRecord model (item-level log) is maintained automatically
 * by the backend whenever such a transaction is approved.
 *
 * This service forces those filters on top of the general
 * stock-transaction list so the disposals UX stays self-contained. All
 * workflow (submit/approve/cancel/delete) goes through the regular
 * stock-transaction endpoints — no duplicate backend code.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import {
  stockTransactionService,
  StockTransactionStatus,
  StockTransactionSubType,
  StockTransactionType,
  type StockTransaction,
  type StockTransactionFilters,
  type StockTransactionItemInput,
} from "./stock-transaction-service";

export interface WasteReportFilters {
  warehouse_id?: string;
  date_from: string;
  date_to: string;
  limit?: number;
}

export interface WasteReportByReason {
  disposal_reason: string;
  transaction_count: number;
  item_count: number;
  total_value: number;
}

export interface WasteReportTopItem {
  item_name: string | null;
  item_sku: string | null;
  total_quantity: number;
  unit: string | null;
  total_value: number;
  main_reason: string;
}

export interface WasteReportDailyPoint {
  date: string;
  total_value: number;
}

export interface WasteReportSummary {
  total_value: number;
  total_transactions: number;
  total_items: number;
}

export interface WasteReportData {
  by_reason: WasteReportByReason[];
  top_items: WasteReportTopItem[];
  daily_trend: WasteReportDailyPoint[];
  summary: WasteReportSummary;
}

export type { StockTransaction };
export { StockTransactionStatus };

export interface DisposalFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  status?: StockTransactionStatus | StockTransactionStatus[];
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface DisposalCreateInput {
  warehouse_id: string;
  note?: string | null;
  transaction_date?: string | null;
  items: StockTransactionItemInput[];
}

function asTxFilters(filters: DisposalFilters): StockTransactionFilters {
  return {
    page: filters.page,
    per_page: filters.per_page,
    warehouse_id: filters.warehouse_id,
    status: filters.status,
    date_from: filters.date_from,
    date_to: filters.date_to,
    search: filters.search,
    sort: filters.sort,
    type: StockTransactionType.StockOut,
    sub_type: StockTransactionSubType.Disposal,
  };
}

export const disposalService = {
  list: (
    shopSlug: string,
    filters: DisposalFilters = {}
  ): Promise<PaginatedResponse<StockTransaction>> =>
    stockTransactionService.list(shopSlug, asTxFilters(filters)),

  getById: (shopSlug: string, id: string) => stockTransactionService.getById(shopSlug, id),

  create: (shopSlug: string, data: DisposalCreateInput) =>
    stockTransactionService.create(shopSlug, {
      warehouse_id: data.warehouse_id,
      type: StockTransactionType.StockOut,
      sub_type: StockTransactionSubType.Disposal,
      note: data.note,
      transaction_date: data.transaction_date,
      items: data.items,
    }),

  update: stockTransactionService.update,
  delete: stockTransactionService.delete,

  // --- Workflow — delegated to stock-transaction endpoints ---
  submit: stockTransactionService.submit,
  approve: stockTransactionService.approve,
  cancel: stockTransactionService.cancel,

  // --- Reports ---

  wasteReport: (shopSlug: string, filters: WasteReportFilters) => {
    const params = new URLSearchParams();
    params.set("date_from", filters.date_from);
    params.set("date_to", filters.date_to);
    if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
    if (filters.limit) params.set("limit", String(filters.limit));
    return apiFetch<{ data: WasteReportData }>(
      `/api/v1/shops/${shopSlug}/disposals/waste-report?${params.toString()}`
    );
  },
};
