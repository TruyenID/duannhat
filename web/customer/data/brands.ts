export interface Brand {
  id: string;
  slug: string;
  name: string;
  description?: string | null;
  /** Optional absolute URL to the brand logo image. Falls back to the first
   *  letter of `name` when absent. Populated by `/api/v1/customer/branches`
   *  and `/api/v1/customer/tables/{qrToken}`. */
  logo_url?: string | null;
  /** Optional header logo specific to Customer-web (brand-level setting). */
  customer_header_logo_url?: string | null;
  /** Optional logo for the /order landing hero. */
  customer_order_logo_url?: string | null;
  /** Optional custom subtitle for the /order landing page. */
  customer_order_subtitle?: string | null;
}

export interface WeeklyHoursDay {
  open?: string;
  close?: string;
  closed?: boolean;
}

export type WeeklyHoursMap = Partial<
  Record<"mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun", WeeklyHoursDay>
>;

export interface Branch {
  id: string;
  slug: string;
  name: string;
  code?: string;
  description?: string | null;
  address?: string;
  phone?: string;
  img_branches?: string | null;
  /** #936 — banner riêng theo breakpoint. null/undefined = fallback xuống
   *  chuỗi mobile → tablet → desktop → img_branches (xem resolveBannerSet). */
  banner_desktop?: string | null;
  banner_tablet?: string | null;
  banner_mobile?: string | null;
  logo?: string | null;
  seat_capacity?: number;
  business_hours?: string;
  weekly_hours?: WeeklyHoursMap | null;
  /** #1160 — IANA zone `weekly_hours` is written in (branches.timezone). */
  timezone?: string | null;
  /** % phí dịch vụ (0-100) lấy từ ShopOrderSetting của branch. 0 nếu chưa cấu hình. */
  service_charge_rate?: number;
  /** plan-043 — giá niêm yết đã gồm thuế (総額表示). Snapshot lúc tạo order
   *  quyết định mode; đây là default hiện tại của branch cho preview. */
  prices_include_tax?: boolean;
  /** plan-043 — % thuế RIÊNG cho service charge (gộp vào nhóm rate khi in). */
  service_charge_tax_rate?: number;
  /** #1099 — loại thuế mặc định của branch (MỘT rate duy nhất). Dùng làm
   *  fallback rate cho món inherit khi tính preview per-rate. null = chưa cấu hình. */
  default_tax_type?: {
    id: string;
    code: string;
    rate: number;
  } | null;
	  /** ISO 4217 currency code (JPY, VND, USD, EUR...) — do shop cấu hình trong
	   *  ShopOrderSetting. Default JPY khi chưa cấu hình. */
	  currency_code?: string;
	  /** Dine-in split-bill rounding mode (BR-SOS07). auto/integer/two_decimals/none. */
	  split_bill_rounding_mode?: string;
  /** plan-035 — BCP-47 locale on the branch row (`vi-VN`, `ja-JP`). FE
   *  derives the phone-validation country from this. */
  locale?: string | null;
  /** plan-035 — server-resolved effective policy (brand default + shop
   *  override merged). Drives the email-required gate, the
   *  prep-before-payment banner, and the phone country fallback. */
  effective_order_policy?: {
    prep_before_payment: boolean;
    customer_email_required: boolean;
    phone_country: string;
    confirmation_timeout_minutes?: number;
    payment_timeout_minutes?: number;
    /** #1160 — phút chuẩn bị cho MỖI món (shop ?? brand ?? 5). ETA hiển thị
     *  cho khách = giá trị này x tổng số lượng trong giỏ. */
    prep_minutes_per_item?: number;
    source: {
      prep_before_payment: "shop" | "brand" | "default";
      customer_email_required: "shop" | "brand" | "default";
      prep_minutes_per_item?: "shop" | "brand" | "default";
    };
  };
  review_avg_rating?: number | null;
  review_total_count?: number;
  brand: Brand;
}

export const branches: Branch[] = [
  {
    id: "branch-001",
    slug: "hongo-shibuya",
    name: "Hongo Shibuya",
    code: "SHB",
    address: "Tokyo, Shibuya-ku 1-2-3",
    brand: { id: "brand-001", slug: "hongo", name: "Hongo" },
  },
  {
    id: "branch-002",
    slug: "hongo-shinjuku",
    name: "Hongo Shinjuku",
    code: "SNJ",
    address: "Tokyo, Shinjuku-ku 4-5-6",
    brand: { id: "brand-001", slug: "hongo", name: "Hongo" },
  },
  {
    id: "branch-003",
    slug: "hongo-osaka",
    name: "Hongo Osaka",
    code: "OSK",
    address: "Osaka, Namba 7-8-9",
    brand: { id: "brand-001", slug: "hongo", name: "Hongo" },
  },
];
