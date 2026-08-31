/**
 * #1715 — đọc lời từ chối `line_unit_price_drift` của backend.
 *
 * Backend định giá lại mọi dòng lúc tạo đơn. Nếu một dòng ra CAO hơn giá client
 * đang hiển thị (khung giờ ưu đãi vừa đóng, Happy Hour hết, admin sửa giá) nó trả
 * 409 kèm **giá thật của từng dòng lệch** thay vì lặng lẽ tạo đơn ở giá khác.
 *
 * Thân lỗi đó chính là "báo giá" — nhờ nó client cập nhật giỏ được ngay mà không
 * cần một endpoint quote riêng, và không có khe TOCTOU nào giữa lúc hỏi giá và
 * lúc đặt.
 */

export const PRICE_DRIFT_CODE = "line_unit_price_drift";

export interface PriceDriftRow {
  /** Vị trí dòng trong mảng `items` vừa gửi lên — khớp 1-1 với giỏ theo thứ tự. */
  index: number;
  productSkuId: string;
  /** Giá client đang hiển thị. */
  expected: number;
  /** Giá server vừa giải ra — đây là con số sẽ được tính. */
  actual: number;
  currency: string;
}

interface RawDriftBody {
  code?: unknown;
  items?: unknown;
}

/**
 * Trả về danh sách dòng lệch, hoặc `null` nếu đây không phải lỗi trôi giá.
 *
 * Nhận `unknown` để chỗ gọi khỏi phải tự đoán hình dạng `ApiError`: mọi trường
 * không đúng kiểu đều bị loại, nên một body lạ sẽ ra `null` chứ không ném thêm
 * một lỗi thứ hai ngay giữa đường xử lỗi.
 */
export function parsePriceDrift(body: unknown): PriceDriftRow[] | null {
  if (!body || typeof body !== "object") return null;

  const raw = body as RawDriftBody;
  if (raw.code !== PRICE_DRIFT_CODE || !Array.isArray(raw.items)) return null;

  const rows: PriceDriftRow[] = [];
  for (const entry of raw.items) {
    if (!entry || typeof entry !== "object") continue;
    const row = entry as Record<string, unknown>;
    const index = Number(row.index);
    const expected = Number(row.expected_unit_price);
    const actual = Number(row.actual_unit_price);
    if (!Number.isInteger(index) || index < 0) continue;
    if (!Number.isFinite(expected) || !Number.isFinite(actual)) continue;
    rows.push({
      index,
      productSkuId: typeof row.product_sku_id === "string" ? row.product_sku_id : "",
      expected,
      actual,
      currency: typeof row.currency === "string" ? row.currency : "",
    });
  }

  return rows.length > 0 ? rows : null;
}

/**
 * Ghép mỗi dòng lệch với id dòng giỏ tương ứng, theo THỨ TỰ đã gửi lên.
 *
 * Dùng vị trí chứ không dùng `product_sku_id`: một SKU có thể nằm trên nhiều dòng
 * giỏ khác nhau (khác option, khác topping, khác ghi chú), nên khớp theo SKU sẽ
 * sửa nhầm dòng.
 */
/**
 * Đường dùng thường ngày: nhận thẳng lỗi bắt được, trả về danh sách dòng giỏ cần
 * sửa giá — hoặc `null` nếu đây là lỗi khác (chỗ gọi xử tiếp như cũ).
 *
 * Đọc `body` theo cấu trúc thay vì `instanceof ApiError` để module này không phải
 * kéo `lib/api` vào — nó chạy trong harness `node --test`, nơi mọi import runtime
 * đều là một cơ hội để chết vì alias.
 */
export function driftUpdatesFromError(
  err: unknown,
  sentLineIds: string[],
): Array<{ id: string; unitPrice: number }> | null {
  const body = (err as { body?: unknown } | null | undefined)?.body;
  const rows = parsePriceDrift(body);
  if (!rows) return null;
  const updates = mapDriftToCartLines(rows, sentLineIds);
  return updates.length > 0 ? updates : null;
}

export function mapDriftToCartLines(
  rows: PriceDriftRow[],
  sentLineIds: string[],
): Array<{ id: string; unitPrice: number }> {
  const updates: Array<{ id: string; unitPrice: number }> = [];
  for (const row of rows) {
    const id = sentLineIds[row.index];
    if (id === undefined) continue;
    updates.push({ id, unitPrice: row.actual });
  }
  return updates;
}
