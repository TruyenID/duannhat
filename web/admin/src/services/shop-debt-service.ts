/**
 * Sổ nợ của một chi nhánh, cho MẶT QUẢN LÝ (#1998).
 *
 * Hai endpoint, hai loại nghĩa vụ, **cố ý không gộp**:
 *
 *   GET /shops/{slug}/debts            nợ trên TÀI KHOẢN được cấp có chủ đích
 *   GET /shops/{slug}/debts/part-paid  đơn KHÔNG AI ĐÓNG, khách trả thiếu rồi đi
 *
 * Luật #1990 giữ nguyên: cộng hai thứ vào một con số thì người quản lý mất khả
 * năng phân biệt "đã đồng ý cho nợ" với "sót". Một bên là quyết định kinh doanh,
 * bên kia là sự cố vận hành, và cách xử lý khác hẳn nhau.
 *
 * Cùng controller với cửa POS (`/pos/debts/...`) — hai namespace tồn tại vì hai
 * cách XÁC THỰC (device token vs Platform SSO), không vì hai tập dữ liệu. Backend
 * có test khẳng định hai cửa trả `toEqual` nhau; nếu chỗ này tự nắn số thì lời
 * khẳng định đó mất nghĩa.
 *
 * **Không làm phép tính ở đây.** Mọi số tiền qua ranh giới này đúng như server
 * gửi. Nếu cần một tổng thì server phải trả tổng — một con số do UI tự cộng sẽ
 * lệch khỏi sổ vào đúng ngày ai đó đổi bộ lọc phía dưới, và không có gì đỏ.
 */

import { apiFetch } from "@/lib/api";

/** Một đơn khách trả chưa đủ. Số tiền là CHUỖI, giữ nguyên từ server. */
export interface PartPaidOrderRow {
  order_id: string;
  order_code: string | null;
  total_amount: string;
  paid_amount: string;
  unpaid_amount: string;
  opened_at: string | null;
}

export interface PartPaidDebtRow {
  customer_id: string;
  customer_name: string | null;
  customer_phone: string | null;
  customer_tax_code: string | null;
  order_count: number;
  total_unpaid: string;
  oldest_at: string | null;
  latest_at: string | null;
  orders: PartPaidOrderRow[];
}

export interface OpenAccountDebtRow {
  customer_id: string;
  customer_name: string | null;
  customer_phone: string | null;
  customer_tax_code: string | null;
  open_debt_total: string;
  oldest_at?: string | null;
  latest_at?: string | null;
}

interface Envelope<T> {
  data: T[];
}

export const shopDebtService = {
  /** Nợ trên tài khoản được cấp có chủ đích. */
  async openAccount(shopSlug: string): Promise<OpenAccountDebtRow[]> {
    const res = await apiFetch<Envelope<OpenAccountDebtRow>>(
      `/api/v1/shops/${shopSlug}/debts`,
    );

    return res.data ?? [];
  },

  /** Đơn khách trả chưa đủ — tiền không sổ nợ nào thấy. */
  async partPaid(shopSlug: string): Promise<PartPaidDebtRow[]> {
    const res = await apiFetch<Envelope<PartPaidDebtRow>>(
      `/api/v1/shops/${shopSlug}/debts/part-paid`,
    );

    return res.data ?? [];
  },
};
