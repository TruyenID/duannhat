/**
 * FAQ Service — #1504. Pure TypeScript, no React dependency; all HTTP goes
 * through apiFetch. Used by hooks in src/hooks/api/use-faqs.ts.
 *
 * Câu hỏi thường gặp hiển thị ở customer-web `/account/faq` (#1486). Backend
 * lưu chúng trong bảng `posts` thuộc chuyên mục `faq`, nhưng endpoint này chỉ
 * nói `question` / `answer` / `is_published` / `is_pinned` — màn hình quản trị
 * cố ý KHÔNG phải CRUD bài viết, và không có đường nào từ đây chạm tới bài
 * news/promotion.
 *
 * Phạm vi là ORGANIZATION dù URL có `{brandSlug}`: bảng `posts` không có
 * `brand_id`, nên hai brand cùng tổ chức dùng chung một bộ FAQ. Đây là chủ ý
 * của backend, xem `routes/api/hq/faqs.php`.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface FaqTranslation {
  question?: string | null;
  answer?: string | null;
}

export interface Faq {
  id: string;
  /** Ngôn ngữ đang xem, có đường lui sang ngôn ngữ khác khi thiếu bản dịch. */
  question: string | null;
  answer: string | null;
  /** Khách có nhìn thấy câu hỏi này không (status + published_at). */
  is_published: boolean;
  /** Ghim lên đầu danh sách FAQ của khách. */
  is_pinned: boolean;
  published_at: string | null;
  translations: {
    ja?: FaqTranslation;
    en?: FaqTranslation;
    vi?: FaqTranslation;
  };
  created_at: string | null;
  updated_at: string | null;
}

export interface FaqListResponse {
  data: Faq[];
}

/**
 * #1673 — danh sách phía chi nhánh. Mỗi dòng kèm `is_inherited`: câu của HQ
 * hiện ra để người quản chi nhánh thấy đúng thứ khách đang đọc, nhưng chỉ đọc
 * — mọi thao tác ghi lên chúng bị backend trả 404.
 */
export interface ShopFaq extends Faq {
  is_inherited: boolean;
  /**
   * #1684 — chi nhánh này có cho hiện câu ĐI MƯỢN đó với khách không.
   *
   * Chỉ có nghĩa khi `is_inherited`; câu riêng của chi nhánh luôn `true` vì nó
   * đã có `is_published` của chính nó.
   *
   * `is_published` (của HQ) thắng tuyệt đối: HQ ẩn thì bật ở đây cũng không ra
   * trang khách (BR-FB03).
   */
  is_visible: boolean;
}

export interface ShopFaqListResponse {
  data: ShopFaq[];
  /** Chi nhánh có đang đọc kèm bộ câu hỏi của HQ hay không. */
  inherit_hq: boolean;
}

/** Nội dung theo từng ngôn ngữ. Ngôn ngữ vắng mặt = giữ nguyên, không xoá. */
export interface FaqTranslationsInput {
  ja?: FaqTranslation;
  en?: FaqTranslation;
  vi?: FaqTranslation;
}

export interface CreateFaqInput extends FaqTranslationsInput {
  is_published?: boolean;
  is_pinned?: boolean;
}

export type UpdateFaqInput = CreateFaqInput;

// =========================================================================
//  Service
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/faqs${path}`;
}

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/faqs${path}`;
}

export const faqService = {
  // --- Query (read) ---

  list: (brandSlug: string) => apiFetch<FaqListResponse>(brandUrl(brandSlug)),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateFaqInput) =>
    apiFetch<{ data: Faq }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateFaqInput) =>
    apiFetch<{ data: Faq }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  /**
   * Bật/tắt hiển thị. Backend giữ nguyên `published_at` cũ khi bật lại, nên
   * ẩn rồi hiện một câu hỏi cũ không ném nó lên đầu trang FAQ của khách.
   */
  togglePublished: (brandSlug: string, id: string, currentIsPublished: boolean) =>
    apiFetch<{ data: Faq }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_published: !currentIsPublished }),
    }),

  togglePinned: (brandSlug: string, id: string, currentIsPinned: boolean) =>
    apiFetch<{ data: Faq }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_pinned: !currentIsPinned }),
    }),

  /** Xoá mềm — không có gì tham chiếu tới một câu hỏi theo kiểu lịch sử. */
  remove: (brandSlug: string, id: string) =>
    apiFetch<void>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),
};

// =========================================================================
//  Cấp chi nhánh (#1673)
// =========================================================================

export const shopFaqService = {
  list: (shopSlug: string) => apiFetch<ShopFaqListResponse>(shopUrl(shopSlug)),

  create: (shopSlug: string, data: CreateFaqInput) =>
    apiFetch<{ data: ShopFaq }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateFaqInput) =>
    apiFetch<{ data: ShopFaq }>(shopUrl(shopSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  togglePublished: (shopSlug: string, id: string, currentIsPublished: boolean) =>
    apiFetch<{ data: ShopFaq }>(shopUrl(shopSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_published: !currentIsPublished }),
    }),

  togglePinned: (shopSlug: string, id: string, currentIsPinned: boolean) =>
    apiFetch<{ data: ShopFaq }>(shopUrl(shopSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_pinned: !currentIsPinned }),
    }),

  remove: (shopSlug: string, id: string) =>
    apiFetch<void>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  /**
   * #1684 — ẩn/hiện MỘT câu kế thừa từ HQ, cho riêng chi nhánh này.
   *
   * Endpoint riêng chứ không dùng `PATCH /{id}` như `togglePublished`: đường
   * kia sửa chính BÀI VIẾT, mà bài này là của HQ nên backend trả 404. Ở đây chỉ
   * ghi một dòng "chi nhánh X ẩn câu Y", không đụng nội dung.
   */
  setVisibility: (shopSlug: string, id: string, isVisible: boolean) =>
    apiFetch<{ data: ShopFaq }>(shopUrl(shopSlug, `/${id}/visibility`), {
      method: "PATCH",
      body: JSON.stringify({ is_visible: isVisible }),
    }),

  /**
   * Công tắc kế thừa đi qua endpoint CÀI ĐẶT CHI NHÁNH, không phải endpoint
   * FAQ: nó là một thuộc tính của chi nhánh, nằm cùng chỗ với cart timeout.
   */
  setInheritHq: (shopSlug: string, inherit: boolean) =>
    apiFetch<{ data: { faq_inherit_hq: boolean } }>(`/api/v1/shops/${shopSlug}/settings/branch`, {
      method: "PATCH",
      body: JSON.stringify({ faq_inherit_hq: inherit }),
    }),
};
