/**
 * HQ gateway settlement read API (#1157 · plan-050 M5 T5.1).
 *
 * Backing controller: `backend/app/Http/Controllers/Api/V1/HQ/SettlementController.php`
 * — four GET endpoints, four shapes, deliberately not merged behind a `?mode=`.
 *
 * Two rules this file exists to hold:
 *
 * 1. **No stub fallback.** Sibling payment services swallow 404/501 and return
 *    fixtures so UI work can proceed before the backend lands. These endpoints
 *    are already shipped, and inventing a reconciliation figure is far worse
 *    than an error panel: the reader cannot tell a fixture from a real payout.
 *    Errors propagate.
 *
 * 2. **No arithmetic.** Every amount crosses this boundary exactly as the
 *    server sent it — an integer in the currency's minor unit. The UI scales it
 *    for display only (`lib/money-minor.ts`); nothing here sums, nets, or
 *    converts. The server's own contract (G1) is that these figures come from
 *    the provider's report, never from the L1 estimate taken at sale time.
 */

import { apiFetch } from "@/lib/api";

/** Page meta as this controller emits it — no `links`, no `from`/`to`. */
export interface SettlementPageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface SettlementPage<T> {
  data: T[];
  meta: SettlementPageMeta;
}

/**
 * One settlement line as the gateway reported it.
 *
 * `order_payment_id === null` is the "orphan" shape — the gateway billed money
 * we cannot tie to any payment we recorded. That is the `unmatched=1` slice,
 * not a separate table.
 */
export interface SettlementRow {
  id: string;
  connection_id: string;
  provider: string;
  kind: string;
  order_payment_id: string | null;
  gross_minor: number;
  fee_minor: number;
  fee_tax_minor: number;
  net_minor: number;
  currency: string;
  source: string | null;
  external_ref: string | null;
  report_batch_id: string | null;
  payout_id: string | null;
  status: string;
  provider_settled_at: string | null;
}

/** An imported provider report file, with its match/orphan tally. */
export interface SettlementBatchRow {
  id: string;
  connection_id: string;
  provider: string;
  cycle_label: string | null;
  row_count: number;
  matched_count: number;
  orphan_count: number;
  status: string;
  imported_at: string | null;
}

/** Money that actually left the gateway for a bank account. */
export interface SettlementPayoutRow {
  id: string;
  connection_id: string;
  provider: string;
  external_payout_id: string | null;
  gross_minor: number;
  fee_minor: number;
  net_minor: number;
  currency: string;
  status: string;
  expected_arrival_date: string | null;
  paid_at: string | null;
  reconciled_at: string | null;
  bank_ref: string | null;
}

/**
 * Money still sitting at the gateway, bucketed by days pending.
 *
 * `buckets` keys are built from `payments.settlement.aging_buckets` config
 * (e.g. `"0-3d"`, `"4-7d"`, `"31d+"`), so the UI must derive its columns from
 * the response — hardcoding bucket labels here would silently drop a column the
 * day operations retunes the config.
 */
export interface SettlementAgingRow {
  connection_id: string;
  provider: string;
  currency: string;
  total_net_minor: number;
  row_count: number;
  oldest_age_days: number | null;
  over_threshold: boolean;
  buckets: Record<string, { net_minor: number; row_count: number }>;
}

export interface SettlementListFilters {
  page?: number;
  per_page?: number;
  connection_id?: string;
  status?: string;
  kind?: string;
  provider?: string;
  currency?: string;
  /** Only lines the reconciler could not tie to an order payment. */
  unmatched?: boolean;
  /** ISO date/datetime compared against `provider_settled_at`. */
  settled_from?: string;
  settled_to?: string;
}

export interface SettlementBatchFilters {
  page?: number;
  per_page?: number;
  connection_id?: string;
  status?: string;
}

export interface SettlementPayoutFilters {
  page?: number;
  per_page?: number;
  connection_id?: string;
  status?: string;
}

export interface SettlementAgingFilters {
  connection_id?: string;
}

function hqBase(brandSlug: string): string {
  return `/api/v1/hq/${brandSlug}/settlements`;
}

type QueryValue = string | number | boolean | undefined;

function toQuery(params: object): string {
  const sp = new URLSearchParams();
  for (const [key, value] of Object.entries(params) as Array<[string, QueryValue]>) {
    if (value === undefined || value === "") continue;
    // Laravel's `$request->boolean()` reads "1"/"0"; sending "false" would be
    // truthy there and silently turn the whole-list view into the orphan view.
    sp.set(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
  }
  const q = sp.toString();
  return q ? `?${q}` : "";
}

export const settlementService = {
  listSettlements(
    brandSlug: string,
    filters: SettlementListFilters = {}
  ): Promise<SettlementPage<SettlementRow>> {
    return apiFetch<SettlementPage<SettlementRow>>(`${hqBase(brandSlug)}${toQuery(filters)}`);
  },

  listBatches(
    brandSlug: string,
    filters: SettlementBatchFilters = {}
  ): Promise<SettlementPage<SettlementBatchRow>> {
    return apiFetch<SettlementPage<SettlementBatchRow>>(
      `${hqBase(brandSlug)}/batches${toQuery(filters)}`
    );
  },

  listPayouts(
    brandSlug: string,
    filters: SettlementPayoutFilters = {}
  ): Promise<SettlementPage<SettlementPayoutRow>> {
    return apiFetch<SettlementPage<SettlementPayoutRow>>(
      `${hqBase(brandSlug)}/payouts${toQuery(filters)}`
    );
  },

  /** Aging is a whole-report snapshot — no pagination on the server side. */
  listAging(
    brandSlug: string,
    filters: SettlementAgingFilters = {}
  ): Promise<{ data: SettlementAgingRow[] }> {
    return apiFetch<{ data: SettlementAgingRow[] }>(
      `${hqBase(brandSlug)}/aging${toQuery(filters)}`
    );
  },

  /**
   * Tải CSV do SERVER sinh (#1157 / plan-050 T5.2).
   *
   * Trước đây bốn tab tự dựng CSV trong trình duyệt từ các dòng đang hiển thị,
   * vì backend chưa có endpoint export. Hệ quả là kế toán xuất trang 1/40 rồi
   * đóng sổ — file vẫn mở được, vẫn cộng ra một con số, và con số đó SAI mà
   * không có dấu hiệu gì. Tên file cũ (`..._page-3.csv`) là thứ duy nhất nói ra
   * điều đó, và không ai đọc tên file trước khi nộp.
   *
   * Endpoint server không phân trang, kèm BOM UTF-8 cho Excel, và tiền là số
   * nguyên đơn vị nhỏ nhất. Ở đây chỉ còn việc đẩy blob xuống đĩa.
   *
   * Tab `aging` KHÔNG dùng đường này, có chủ đích: endpoint aging vốn không
   * phân trang (nó là bảng tổng hợp trả về trọn vẹn), nên dựng CSV ở client
   * vẫn ra đúng đủ dữ liệu.
   */
  async downloadCsv(
    brandSlug: string,
    resource: "settlements" | "batches" | "payouts",
    filters: Record<string, string | number | boolean | undefined> = {}
  ): Promise<void> {
    const path =
      resource === "settlements"
        ? `${hqBase(brandSlug)}/export`
        : `${hqBase(brandSlug)}/${resource}/export`;

    const blob = await apiFetch(`${path}${toQuery(filters)}`, { responseType: "blob" });

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    // KHÔNG nhét số trang vào tên file nữa — không còn trang nào để nhét.
    a.download = `settlement-${resource}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

};
