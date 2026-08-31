/**
 * HQ transaction lookup (#2880 · T3 của #2876).
 *
 * Backing controller: `backend/app/Http/Controllers/Api/V1/HQ/TransactionController.php`
 *
 * ## Đây là nghĩa vụ pháp lý, không phải tiện ích
 *
 * 電子帳簿保存法 検索要件 bắt tra được theo **取引年月日 · 取引金額 · 取引先**
 * và KẾT HỢP từ hai trục trở lên. Trước màn này hệ thống không đáp ứng trục nào
 * ở tầng giao diện — muốn biết một giao dịch PayPay hôm qua ra sao thì phải vào
 * DB.
 *
 * ## Hai luật thừa hưởng nguyên từ `settlement-service.ts`
 *
 * 1. **Không stub fallback.** Endpoint đã ship; bịa một con số tiền tệ hơn
 *    nhiều so với một panel lỗi, vì người đọc không phân biệt được fixture với
 *    giao dịch thật. Lỗi được ném lên.
 * 2. **Không tính toán.** Mọi số tiền qua đây đúng như server gửi. UI chỉ định
 *    dạng để hiển thị; không cộng, không quy đổi.
 *
 * Thêm một luật riêng của màn này: **không có đường GHI.** Controller không có
 * route ghi nào, và service này cũng vậy — sửa tiền đi qua đường đã có, có lý
 * do và có audit.
 */

import { apiFetch } from "@/lib/api";

export interface TransactionPageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface TransactionPage<T> {
  data: T[];
  meta: TransactionPageMeta;
}

/**
 * Ảnh chụp cổng thanh toán tại thời điểm THU, không phải cấu hình hiện tại.
 *
 * Quán đổi cổng sau đó thì bản ghi cũ vẫn kể đúng chuyện đã xảy ra — đó là lý
 * do backend snapshot sáu trường này lên chính dòng tiền thay vì join lại
 * connection.
 */
export interface TransactionGatewaySnapshot {
  provider: string | null;
  environment: string | null;
  currency: string | null;
  amount_minor: number | null;
  state: string | null;
  provider_status: string | null;
}

export interface TransactionAttempt {
  id: string;
  provider_object_id: string | null;
  provider_status: string | null;
}

export interface TransactionRow {
  id: string;
  payment_code: string | null;
  amount: number;
  tip_amount: number;
  status: string | null;
  paid_at: string | null;
  channel: string | null;
  tender_key: string | null;
  reference_no: string | null;
  branch_id: string;
  customer_order_id: string | null;
  till_session_id: string | null;
  gateway: TransactionGatewaySnapshot;
  attempt: TransactionAttempt | null;
}

export interface TransactionFilters {
  /** 取引年月日 — ngày NGHIỆP VỤ của chi nhánh, không phải UTC (#1091). */
  date_from?: string;
  date_to?: string;
  /** 取引金額 — KHOẢNG, vì người đi tra nhớ "khoảng ba nghìn", không nhớ 2.980. */
  amount_min?: number;
  amount_max?: number;
  branch_id?: string;
  status?: string;
  /** 取引先 — cổng thanh toán. */
  provider?: string;
  tender_key?: string;
  till_session_id?: string;
  /**
   * MỘT ô cho mọi loại mã. Người vận hành cầm đúng một cái — thường là cái nhà
   * cung cấp đưa — và không phải biết nó thuộc cột nào.
   */
  reference?: string;
  page?: number;
  per_page?: number;
}

type QueryValue = string | number | boolean | undefined;

function toQuery(params: object): string {
  const sp = new URLSearchParams();
  for (const [key, value] of Object.entries(params) as Array<[string, QueryValue]>) {
    if (value === undefined || value === "") continue;
    sp.set(key, String(value));
  }
  const q = sp.toString();
  return q ? `?${q}` : "";
}

export const transactionService = {
  list(
    brandSlug: string,
    filters: TransactionFilters = {}
  ): Promise<TransactionPage<TransactionRow>> {
    return apiFetch<TransactionPage<TransactionRow>>(
      `/api/v1/hq/${brandSlug}/transactions${toQuery(filters)}`
    );
  },
};
