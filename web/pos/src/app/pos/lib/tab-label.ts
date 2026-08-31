import { isProvisionalCode } from "./order-code";
import { joinTableNames, tableDisplayName } from "./table-names";
import type { CustomerOrder, TableResource } from "../types";

export type TabLabelKind =
  /** Đơn đã gán bàn — nhãn là TÊN BÀN. */
  | "table"
  /** Mang đi / chưa gán bàn — nhãn là MÃ ĐƠN. */
  | "code"
  /** Mã chưa được cấp (`WS-…` của máy trạm) — giao diện thay bằng chỗ chờ. */
  | "pending";

export interface TabLabel {
  kind: TabLabelKind;
  /** Chuỗi hiển thị. Ở `pending` đây là mã thô, KHÔNG dùng để vẽ. */
  text: string;
}

/**
 * `orderId → nhãn bàn` dựng từ FEED BÀN của màn sơ đồ.
 *
 * ## Vì sao cần feed bàn, khi đơn đã có sẵn `order.tables`
 *
 * Vì `order.tables` chỉ có ở **chi tiết đơn**, không có ở **danh sách đơn mở**:
 * đo trên API thật, `GET /pos/orders?status=…` trả `tables: []` cho cả đơn
 * `dine_in` đang ngồi bàn A-2, trong khi `GET /pos/orders/{id}` trả
 * `tables: [{code: "A-2"}]`.
 *
 * Dải tab thì vẽ MỌI tab, còn chi tiết chỉ được nạp cho tab đã mở — và tab sống
 * qua reload (localStorage). Nếu chỉ dựa vào `order.tables`, sau mỗi lần tải lại
 * trang mọi tab sẽ hiện mã đơn rồi lần lượt nhảy sang tên bàn khi người dùng bấm
 * vào từng cái. Feed bàn đã được màn sơ đồ nạp sẵn (và nằm trong danh sách cache
 * offline), nên nó vá đúng khoảng trống đó mà không tốn thêm request nào.
 *
 * Đơn GỘP BÀN rơi ra tự nhiên: nhiều bàn cùng trỏ về một `current_order_id` thì
 * gộp lại thành "A-1 + A-2" qua {@link joinTableNames} — cùng cách viết, cùng
 * thứ tự với nhánh `order.tables`, nên nhãn không xáo lại khi tab được mở.
 */
export function tableLabelsByOrderId(
  tables: readonly TableResource[] | null | undefined,
): Map<string, string> {
  const byOrder = new Map<string, TableResource[]>();

  for (const table of tables ?? []) {
    const orderId = table.current_order_id;
    if (!orderId || !tableDisplayName(table)) continue;
    const bucket = byOrder.get(orderId);
    if (bucket) {
      bucket.push(table);
    } else {
      byOrder.set(orderId, [table]);
    }
  }

  return new Map(
    [...byOrder].map(([orderId, rows]) => [orderId, joinTableNames(rows)]),
  );
}

/**
 * Nhãn của một tab POS: **bàn nếu có bàn, mã đơn nếu không**.
 *
 * Thu ngân nghĩ theo bàn ("bàn A-2 gọi thêm gì chưa?"), không theo mã đơn — mã
 * chỉ là định danh chứng từ. Dải tab toàn `ORD-2026-32xx` bắt họ dịch ngược từ
 * mã sang bàn ở mỗi lần liếc mắt.
 *
 * ## Thứ tự nguồn có chủ đích
 *
 * 1. `order.tables` — quan hệ CHÍNH THỐNG, dùng khi chi tiết đơn đã nằm trong
 *    cache. Đây là thứ giỏ hàng cũng đọc, nên hai chỗ không thể nói khác nhau.
 * 2. Feed bàn (`current_order_id`) — con trỏ NGƯỢC, dùng cho tab chưa nạp chi
 *    tiết. Xem {@link tableLabelsByOrderId}.
 *
 * Ưu tiên (1) vì nếu hai nguồn có lệch nhau thì quan hệ trên đơn mới là thứ mọi
 * màn khác đang vẽ theo.
 *
 * Đơn mang đi và đơn chưa gán bàn giữ nguyên mã — và người gọi tô chúng khác
 * màu, vì "không có bàn" là thông tin thu ngân cần thấy ngay chứ không phải một
 * chỗ trống.
 */
export function resolveTabLabel(args: {
  order: CustomerOrder | undefined;
  /** Nhãn đã lưu của tab — mã đơn lúc mở tab, dùng khi đơn chưa vào cache. */
  fallbackCode: string;
  orderId: string;
  tableLabels: ReadonlyMap<string, string>;
}): TabLabel {
  const fromOrder = joinTableNames(args.order?.tables);

  const tableText = fromOrder || args.tableLabels.get(args.orderId) || "";
  if (tableText) {
    return { kind: "table", text: tableText };
  }

  const code = (args.order?.order_code || args.fallbackCode || "").trim();

  return { kind: isProvisionalCode(code) ? "pending" : "code", text: code };
}
