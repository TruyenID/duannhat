/**
 * #2790 — hai thứ PHẢI khớp nhau, và Stripe.js không tha khi chúng lệch:
 *
 *   1. `fields.billingDetails` lúc tạo Payment Element — field nào ta KHÔNG
 *      cho Element thu (`"never"`);
 *   2. `confirmParams.payment_method_data.billing_details` lúc confirm — đúng
 *      những field đó phải có mặt.
 *
 * Lệch một field thì `stripe.confirmPayment()` **NÉM** IntegrationError trước
 * mọi request mạng. Hệ quả đo được trên production 2026-08-13: PaymentIntent
 * đứng ở `requires_payment_method`, `payment_method = null`,
 * `last_payment_error = null`, backend không nhận `confirm-payment` — 16 lượt
 * trả thẻ chết liên tiếp mà không một dòng lỗi nào ở server.
 *
 * Hai thứ đó sống chung một file để không ai sửa một bên rồi quên bên kia;
 * `stripe-billing-details.test.ts` giữ lời hứa đó.
 */

export type BillingFieldMode = "never" | "auto";

/**
 * Cấu hình `fields.billingDetails` của Payment Element ở chế độ thẻ.
 *
 * `address: "auto"` là CỐ Ý: đặt `"never"` thì Stripe đòi
 * `billing_details.address.country` **và** `.postal_code` lúc confirm, mà
 * khách chưa bao giờ nhập địa chỉ — không có giá trị thật nào để gửi, và bịa
 * ra một mã bưu chính là gửi dữ liệu sai cho AVS của ngân hàng. `"auto"` để
 * Stripe tự thu đúng phần thẻ cần.
 */
export const CARD_BILLING_FIELDS = {
  name: "never",
  email: "never",
  phone: "never",
  address: "auto",
} as const satisfies Record<string, BillingFieldMode>;

/** Danh tính khách mà FORM CỦA TA đã thu — Element không thu lại. */
export interface StripeBillingDetails {
  name?: string | null;
  email?: string | null;
  phone?: string | null;
}

/** Field nào Element không thu ⇒ confirm phải tự mang theo. */
export function billingKeysRequiredAtConfirm(
  fields: Record<string, BillingFieldMode> = CARD_BILLING_FIELDS,
): string[] {
  return Object.keys(fields).filter((k) => fields[k] === "never");
}

function trimmedOrNull(value: string | null | undefined): string | null {
  const s = typeof value === "string" ? value.trim() : "";
  return s.length > 0 ? s : null;
}

/**
 * `payment_method_data.billing_details` cho `stripe.confirmPayment()`.
 *
 * KHOÁ phải có mặt kể cả khi ta không có giá trị — Stripe chấp nhận `null`,
 * nhưng không chấp nhận khoá vắng mặt. Đó là toàn bộ sự khác nhau giữa một
 * lượt thanh toán chạy và một lượt chết im lặng.
 */
export function billingDetailsForConfirm(
  guest?: StripeBillingDetails,
): Record<string, string | null> {
  return {
    name: trimmedOrNull(guest?.name),
    email: trimmedOrNull(guest?.email),
    phone: trimmedOrNull(guest?.phone),
  };
}
