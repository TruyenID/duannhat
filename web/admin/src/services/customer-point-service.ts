/**
 * Customer Point Service — #1700. Pure TypeScript, no React; mọi HTTP đi qua
 * apiFetch. Hooks ở `src/hooks/api/use-customer-points.ts`.
 *
 * Trước issue này admin KHÔNG có màn nào nhìn thấy ai đã đổi điểm: dữ liệu nằm
 * đủ trong `customer_point_entries` + `coupons` nhưng muốn đọc thì phải vào
 * MySQL gõ tay. Hai đường đọc ở đây lấp đúng chỗ đó.
 *
 * Hai phạm vi, hai câu hỏi khác nhau — đừng gộp:
 *   - `pointsForCustomer` — "khách NÀY thế nào": số dư, sổ điểm, mã đã đổi.
 *   - `redemptions`       — "brand này thế nào": nhật ký mọi lượt đổi, lọc
 *                            được theo phần thưởng / ngày / tình trạng mã.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Phạm vi
// =========================================================================

/**
 * #1718 — cùng hai câu hỏi, hỏi từ hai chỗ.
 *
 * HQ hỏi cho cả brand; cửa hàng hỏi vì khách cầm mã tới quầy. Dữ liệu trả về
 * GIỐNG NHAU và đó là điều đúng: một lượt đổi điểm không gắn chi nhánh nào
 * (khách bấm ở customer-web, không đứng ở quán), nên "lượt đổi của chi nhánh
 * tôi" là câu hỏi không có dữ liệu để trả lời. Backend nói ra bằng
 * `meta.scope = "brand"` và màn hình phải ghi nhãn — đừng bỏ.
 */
export type PointScope = { kind: "hq"; brandSlug: string } | { kind: "shop"; shopSlug: string };

function scopeBase(scope: PointScope): string {
  return scope.kind === "hq" ? `/api/v1/hq/${scope.brandSlug}` : `/api/v1/shops/${scope.shopSlug}`;
}

/** Định danh phạm vi cho khoá cache — hai màn không được ghi đè cache của nhau. */
export function scopeKey(scope: PointScope): string {
  return scope.kind === "hq" ? `hq:${scope.brandSlug}` : `shop:${scope.shopSlug}`;
}

// =========================================================================
//  Types
// =========================================================================

/**
 * Loại bút toán. Sổ append-only, `points` CÓ DẤU (BR-PT01): earn/adjust dương,
 * redeem/revoke/expire âm. Số dư = tổng cộng, nên đừng lấy trị tuyệt đối khi
 * hiển thị — một cuốn sổ mà chỗ nào cũng dương thì không cộng lại được.
 */
export type PointEntryKind = "earn" | "redeem" | "revoke" | "adjust" | "expire";

/**
 * Tình trạng tấm coupon cá nhân, theo từ vựng của người vận hành.
 *
 * KHÔNG phải `computed_status` của coupon chiến dịch (draft/scheduled/active/
 * expired/exhausted): mọi coupon cá nhân đều mint ra ở `draft` với
 * `usage_limit_total = 1`, nên bộ từ kia sẽ gọi tấm chưa dùng là "draft" và
 * tấm đã dùng là "exhausted" — không ai đọc được.
 */
export type PersonalCouponStatus = "unused" | "used" | "expired";

export interface RedeemedCoupon {
  id: string;
  code: string;
  status: PersonalCouponStatus;
  times_used: number;
  valid_until: string | null;
}

export interface CustomerPointEntry {
  id: string;
  /** Có dấu. Âm là tiêu, dương là tích. */
  points: number;
  kind: PointEntryKind;
  note: string | null;
  created_at: string | null;
  /** Đơn đã sinh ra bút toán này — chỉ có với earn/revoke. */
  order_code: string | null;
  order_id: string | null;
  /** Tên phần thưởng — chỉ có với redeem. `null` nếu phần thưởng đã bị xoá cứng. */
  reward_name: string | null;
  /** Tấm coupon mint ra khi đổi — chỉ có với redeem. */
  coupon: RedeemedCoupon | null;
}

export interface CustomerPointsResponse {
  data: {
    /** Cái khách tiêu được ngay = SUM(points). */
    balance: number;
    /** Tổng đã tích trong đời, dùng xét hạng — tiêu điểm KHÔNG làm giảm số này. */
    lifetime_points: number;
    entries: CustomerPointEntry[];
  };
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    /** "brand" — ví điểm là toàn cục theo khách (BR-PT04), kể cả khi hỏi từ cửa hàng. */
    scope?: "brand";
  };
}

export interface RedemptionRow {
  id: string;
  /** Âm — số điểm đã tiêu cho lượt đổi này. */
  points: number;
  created_at: string | null;
  customer: { id: string; name: string; phone: string | null; email: string | null } | null;
  reward: { id: string; name: string | null } | null;
  coupon: RedeemedCoupon | null;
}

export interface RedemptionListResponse {
  data: RedemptionRow[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    /**
     * Múi giờ mà `date_from`/`date_to` được hiểu theo — đồng hồ vận hành của
     * brand, KHÔNG phải máy người xem. Màn hình phải nói ra: một lượt đổi lúc
     * 23:30 giờ Hà Nội rơi vào ngày nào là câu hỏi có hai đáp án đúng.
     *
     * Hỏi từ cửa hàng thì đây là đồng hồ của CHÍNH chi nhánh đó — chính xác
     * hơn bản HQ, vốn phải đoán bằng chi nhánh cũ nhất của brand.
     */
    timezone: string;
    /** "brand" — nhật ký là của cả brand, không phải lát cắt theo chi nhánh. */
    scope?: "brand";
  };
}

export interface RedemptionFilters {
  point_reward_id?: string | null;
  /** `YYYY-MM-DD`, hiểu theo `meta.timezone`. */
  date_from?: string | null;
  date_to?: string | null;
  coupon_status?: PersonalCouponStatus | null;
  /** Tên / SĐT / email khách, hoặc mã coupon. */
  search?: string | null;
  page?: number;
  per_page?: number;
}

// =========================================================================
//  Service
// =========================================================================

function queryString(params: Record<string, string | number | null | undefined>): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    // `0` là giá trị hợp lệ nên không dùng falsy check; chuỗi rỗng thì bỏ, vì
    // `search=` gửi lên là một bộ lọc rỗng chứ không phải "không lọc".
    if (value === null || value === undefined || value === "") {
      continue;
    }
    query.set(key, String(value));
  }

  const qs = query.toString();
  return qs ? `?${qs}` : "";
}

export const customerPointService = {
  /** Số dư + sổ điểm + mã đã đổi của MỘT khách. */
  pointsForCustomer: (
    scope: PointScope,
    customerId: string,
    params?: { page?: number; per_page?: number }
  ) =>
    apiFetch<CustomerPointsResponse>(
      `${scopeBase(scope)}/customers/${customerId}/points` +
        queryString({ page: params?.page, per_page: params?.per_page })
    ),

  /** Nhật ký đổi thưởng của cả brand. */
  redemptions: (scope: PointScope, filters?: RedemptionFilters) =>
    apiFetch<RedemptionListResponse>(
      `${scopeBase(scope)}/point-rewards/redemptions` +
        queryString({
          point_reward_id: filters?.point_reward_id,
          date_from: filters?.date_from,
          date_to: filters?.date_to,
          coupon_status: filters?.coupon_status,
          search: filters?.search,
          page: filters?.page,
          per_page: filters?.per_page,
        })
    ),
};
