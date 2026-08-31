/**
 * Readiness Service — brand + shop baseline checklist (#2350).
 *
 *   GET /api/v1/hq/{brandSlug}/readiness
 *
 * Read-only by design (#2344). There is deliberately **no** "fix it" endpoint:
 * dựng dữ liệu gốc là hành động có chủ ý, chạy bằng
 * `php artisan provisioning:reconcile`. Màn hình chỉ được CHỈ RA cái thiếu.
 */

import { apiFetch } from "@/lib/api";

/**
 * Ba trạng thái, và `skipped` KHÔNG phải một biến thể của `satisfied`:
 *
 * - `satisfied` — đã đúng.
 * - `missing`   — thiếu, biết chắc.
 * - `skipped`   — **chưa kiểm được** (thiếu tiền đề, ví dụ brand chưa gắn
 *   organization, hay chi nhánh chưa khai đơn vị tiền). Hiển thị nó như "đã
 *   đúng" là dựng lại đúng sự im lặng mà #2320 đi chữa.
 */
export type ReadinessState = "satisfied" | "missing" | "skipped";

export interface ReadinessCheck {
  /** `brand:<slug>` hoặc `branch:<slug>` */
  subject: string;
  /** `brand.tax_types`, `branch.order_settings`, … */
  key: string;
  state: ReadinessState;
  detail: string;
}

export interface Readiness {
  /** true chỉ khi MỌI chủ thể không còn mục `missing` lẫn `skipped`. */
  ready: boolean;
  checks: ReadinessCheck[];
}

export const readinessService = {
  get: (brandSlug: string) =>
    apiFetch<{ data: Readiness }>(`/api/v1/hq/${brandSlug}/readiness`).then((r) => r.data),
};
