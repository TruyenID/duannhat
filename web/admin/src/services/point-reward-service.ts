/**
 * Point Reward Service — #1514. Pure TypeScript, no React; mọi HTTP đi qua
 * apiFetch. Hooks ở `src/hooks/api/use-point-rewards.ts`.
 *
 * Catalog "đổi điểm" hiển thị ở customer-web `/account/points`. Trước issue
 * này bảng `point_rewards` không có màn hình nào — thêm một phần thưởng nghĩa
 * là mở `php artisan tinker` trên production.
 *
 * Một phần thưởng là BẢN MẪU CỦA MỘT COUPON: khách bỏ `cost_points` điểm và
 * hệ thống mint một coupon cá nhân theo đúng thông số giảm giá ở đây. Tên và
 * ảnh do admin tự đặt, KHÔNG link tới Product/SKU nào — "Bia" chỉ là nhãn của
 * một coupon, hệ thống không phát ra một ly bia.
 *
 * Hai phạm vi, hai bộ endpoint:
 *   - HQ (brand)  — CRUD đầy đủ. Ai sửa được coupon thì sửa được cái này.
 *   - Shop (chi nhánh) — chỉ đọc + một công tắc bật/tắt cho riêng cửa hàng.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export type PointRewardServiceCondition = "dine_in" | "takeaway" | "both";

export interface PointRewardTranslation {
  name?: string | null;
  description?: string | null;
}

export interface PointReward {
  id: string;
  /** Ngôn ngữ đang xem, có đường lui khi thiếu bản dịch. */
  name: string | null;
  description: string | null;
  cost_points: number;
  discount_type: "fixed" | "percent";
  discount_value: string | number;
  max_discount_cap: string | number | null;
  min_order_subtotal: string | number;
  /** Số ngày coupon mint ra còn hiệu lực, tính từ lúc đổi. */
  valid_days: number;
  /** `null` = không giới hạn. KHÁC hẳn `0` (tạo trước, chưa mở bán). */
  stock_quantity: number | null;
  redeemed_count: number;
  /** `null` khi không giới hạn; ngược lại là `stock_quantity - redeemed_count`. */
  remaining_stock: number | null;
  is_out_of_stock: boolean;
  /** CHỈ HIỂN THỊ cho khách — không tầng nào cưỡng chế (BR-PR07). */
  service_condition: PointRewardServiceCondition;
  is_active: boolean;
  sort_order: number;
  image_url: string | null;
  image_file_id: string | null;
  translations: {
    ja?: PointRewardTranslation;
    en?: PointRewardTranslation;
    vi?: PointRewardTranslation;
  };
  /** Chỉ có ở màn chi tiết — chi nhánh nào đã TẮT phần thưởng này. */
  disabled_branch_ids?: string[];
  created_at: string | null;
  updated_at: string | null;
}

export interface PointRewardListResponse {
  data: PointReward[];
  meta: { current_page: number; last_page: number; total: number };
}

/** Phần thưởng nhìn từ một cửa hàng — gọn hơn, và có thêm công tắc chi nhánh. */
export interface ShopPointReward {
  id: string;
  name: string | null;
  description: string | null;
  cost_points: number;
  service_condition: PointRewardServiceCondition;
  remaining_stock: number | null;
  is_out_of_stock: boolean;
  image_url: string | null;
  /** Cấp brand — cửa hàng không đổi được, chỉ để giải thích vì sao khách không thấy. */
  is_active: boolean;
  /** Cấp chi nhánh — công tắc của chính màn hình này. */
  is_available_at_branch: boolean;
}

export interface ShopPointRewardListResponse {
  data: ShopPointReward[];
  meta: { current_page: number; last_page: number; total: number };
}

export interface PointRewardTranslationsInput {
  ja?: PointRewardTranslation;
  en?: PointRewardTranslation;
  vi?: PointRewardTranslation;
}

export interface CreatePointRewardInput extends PointRewardTranslationsInput {
  cost_points: number;
  discount_type: "fixed" | "percent";
  discount_value: number;
  max_discount_cap?: number | null;
  min_order_subtotal?: number;
  valid_days?: number;
  stock_quantity?: number | null;
  service_condition?: PointRewardServiceCondition;
  is_active?: boolean;
  sort_order?: number;
  /**
   * Vắng mặt = giữ nguyên ảnh hiện có; `null` = gỡ ảnh. Hai ý đó khác nhau và
   * backend phân biệt bằng `array_key_exists`, nên đừng "dọn" undefined thành
   * null trước khi gửi.
   */
  image_file_id?: string | null;
}

export type UpdatePointRewardInput = Partial<CreatePointRewardInput>;

/** File tạm vừa upload, chưa gắn vào phần thưởng nào. */
export interface UploadedFile {
  id: string;
  url: string;
  original_name: string;
  is_permanent: boolean;
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/point-rewards${path}`;
}

function shopUrl(shopSlug: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/point-rewards${path}`;
}

// =========================================================================
//  Service
// =========================================================================

export const pointRewardService = {
  // --- HQ: đọc ---

  list: (brandSlug: string, params?: { search?: string; is_active?: boolean }) => {
    const query = new URLSearchParams();
    if (params?.search) query.set("search", params.search);
    if (params?.is_active !== undefined) query.set("is_active", String(params.is_active));
    const qs = query.toString();
    return apiFetch<PointRewardListResponse>(brandUrl(brandSlug, qs ? `?${qs}` : ""));
  },

  get: (brandSlug: string, id: string) =>
    apiFetch<{ data: PointReward }>(brandUrl(brandSlug, `/${id}`)),

  // --- HQ: ghi ---

  create: (brandSlug: string, data: CreatePointRewardInput) =>
    apiFetch<{ data: PointReward }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdatePointRewardInput) =>
    apiFetch<{ data: PointReward }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  /** Bật/tắt cho TOÀN BRAND. Cửa hàng tắt riêng thì dùng `setBranchAvailability`. */
  toggleActive: (brandSlug: string, id: string, currentIsActive: boolean) =>
    apiFetch<{ data: PointReward }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_active: !currentIsActive }),
    }),

  /**
   * Xoá mềm. Coupon đã mint vẫn dùng được và lịch sử điểm của khách vẫn trỏ
   * được sang phần thưởng — không thủng.
   */
  remove: (brandSlug: string, id: string) =>
    apiFetch<void>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  /**
   * Upload ảnh vào kho tạm (24h). Trả về `id` để gửi kèm `image_file_id` lúc
   * lưu phần thưởng — chính lúc lưu backend mới biến file thành vĩnh viễn.
   * Endpoint dùng chung, không cần phạm vi brand.
   */
  uploadImage: (file: File) => {
    const body = new FormData();
    body.append("file", file);
    body.append("collection", "reward");
    return apiFetch<{ data: UploadedFile }>("/api/v1/files/upload", {
      method: "POST",
      body,
    });
  },

  // --- Shop ---

  listForShop: (shopSlug: string, params?: { search?: string }) => {
    const query = new URLSearchParams();
    if (params?.search) query.set("search", params.search);
    const qs = query.toString();
    return apiFetch<ShopPointRewardListResponse>(shopUrl(shopSlug, qs ? `?${qs}` : ""));
  },

  /**
   * Bật/tắt cho RIÊNG cửa hàng này. Không đụng thông số cấp brand và không
   * ảnh hưởng chi nhánh khác.
   */
  setBranchAvailability: (shopSlug: string, id: string, isAvailable: boolean) =>
    apiFetch<{ data: { id: string; is_available_at_branch: boolean } }>(
      shopUrl(shopSlug, `/${id}/availability`),
      { method: "PATCH", body: JSON.stringify({ is_available: isAvailable }) },
    ),
};
