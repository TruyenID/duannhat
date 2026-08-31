import { apiFetch } from "@/lib/api";

/**
 * #1881 — từ vựng tender ở cấp tổ chức (HQ).
 *
 * Ba trường `*_editable` / `deletable` do BACKEND tính, không phải frontend suy
 * ra. `tender_key` được chụp lên từng chứng từ tiền, nên câu hỏi "sửa được
 * không" phụ thuộc vào số payment đang tham chiếu — dữ liệu chỉ backend có.
 * Suy ở client sẽ hoặc chặn oan, hoặc cho gõ rồi trả 409.
 */
export interface HqTenderType {
  id: string;
  tender_key: string;
  name: string;
  category: string | null;
  parent_tender_key: string | null;
  payment_method_code: string | null;
  currency_code: string | null;
  sort_order: number;
  is_active: boolean;
  is_expected_anchor: boolean;
  requires_terminal_total: boolean;
  /** Số payment đang tham chiếu. `null` khi chưa đếm (payload sau khi tạo). */
  payment_count: number | null;
  /** Luôn `false` — khoá bất biến theo thiết kế. */
  key_editable: boolean;
  group_editable: boolean | null;
  deletable: boolean | null;
}

export interface HqTenderTypeInput {
  tender_key: string;
  name: string;
  category: string;
  parent_tender_key?: string | null;
  sort_order?: number;
}

/** Mã lỗi 409 mà backend trả — UI rẽ theo MÃ, không theo câu chữ. */
export type TenderConflictCode =
  | "TENDER_KEY_IMMUTABLE"
  | "TENDER_GROUP_IMMUTABLE_ONCE_USED"
  | "TENDER_IN_USE";

function base(brandSlug: string): string {
  return `/api/v1/hq/${brandSlug}/tender-types`;
}

export const hqTenderTypeService = {
  list: (brandSlug: string, includeInactive = false) =>
    apiFetch<{ data: HqTenderType[] }>(
      `${base(brandSlug)}${includeInactive ? "?include_inactive=1" : ""}`,
    ),

  create: (brandSlug: string, input: HqTenderTypeInput) =>
    apiFetch<{ data: HqTenderType }>(base(brandSlug), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  // `tender_key` cố ý KHÔNG có trong kiểu này: nó bất biến, và một trường
  // optional ở đây sẽ là lời mời gọi gửi lên rồi nhận 409.
  update: (
    brandSlug: string,
    id: string,
    patch: Partial<Omit<HqTenderTypeInput, "tender_key">> & { is_active?: boolean },
  ) =>
    apiFetch<{ data: HqTenderType }>(`${base(brandSlug)}/${encodeURIComponent(id)}`, {
      method: "PATCH",
      body: JSON.stringify(patch),
    }),

  remove: (brandSlug: string, id: string) =>
    apiFetch<{ data: { deleted: boolean } }>(`${base(brandSlug)}/${encodeURIComponent(id)}`, {
      method: "DELETE",
    }),
};
