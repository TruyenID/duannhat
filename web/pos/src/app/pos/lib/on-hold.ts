/**
 * #2049 — ĐANG TREO: quán chưa nhận đủ tiền của đơn.
 *
 * Hai cách rơi vào đó, và không có cách thứ ba:
 *
 *   `part_paid`  khách trả thiếu rồi đi
 *   `open_debt`  quán cho nợ ghi sổ (`on_account`) và chưa ai trả
 *
 * Đơn treo KHÔNG được in biên lai (tờ giấy nói ĐÃ THANH TOÁN) và KHÔNG được
 * xuất hoá đơn đỏ. Luật đó được **cưỡng chế ở workstation** bằng 409
 * `order_on_hold`; mọi thứ trong file này chỉ để giao diện đừng mời thu ngân
 * bấm một nút chắc chắn hỏng. Nếu hai bên lệch nhau thì bên server thắng —
 * đừng bao giờ dựng một đường in nào đi vòng qua nó.
 */

import { ApiError } from "@/lib/api";
import type { CustomerOrder } from "../types";

export type OrderOnHoldReason = "open_debt" | "part_paid";

/**
 * Verdict của server dưới dạng ba trạng thái. `null` là "bề mặt này KHÔNG trả
 * lời", không phải "không treo" — xem docblock của `is_on_hold` trong types.ts.
 */
export type OnHoldVerdict = boolean | null;

/**
 * Hợp nhất verdict cũ với verdict vừa nhận.
 *
 * Luật: **`null` không bao giờ hạ được một câu trả lời đã biết.**
 *
 * Vì sao cần luật này: Cloud chỉ đóng dấu cờ ở hai đường ĐỌC (`GET /orders`,
 * `GET /orders/{id}`). Mọi endpoint GHI — sửa món, áp coupon, gộp bàn — trả về
 * cùng một `CustomerOrder` nhưng KHÔNG có cờ, tức `null`. Không có luật này thì
 * thu ngân sửa một dòng món trên đơn treo là cờ tắt, hai nút in hiện lại, bấm
 * vào thì workstation 409. Người dùng đọc ra là "lúc được lúc không".
 *
 * Chiều ngược lại (`false` → `true`) thì cho qua bình thường: đó là lúc khách
 * vừa được ghi nợ, và cờ PHẢI bật lên ngay.
 */
export function mergeOnHold(prev: OnHoldVerdict, next: OnHoldVerdict): OnHoldVerdict {
  return next === null || next === undefined ? prev : next;
}

/**
 * Đơn này có đang treo không, theo những gì client biết CHẮC.
 *
 * `null`/`undefined` đọc là **false** ở đây, và đó là chủ ý: đây là hàm quyết
 * định có ẨN nút in hay không, nên mặc định phải là "cứ hiện". Một bề mặt chưa
 * trả lời mà bị đọc thành "treo" sẽ giấu nút in của mọi đơn bình thường trên
 * mọi màn hình chưa được đóng dấu — tức làm hỏng nghiệp vụ chính để phòng một
 * trường hợp mà server đã chặn cứng rồi.
 *
 * Hướng an toàn của tầng giao diện KHÔNG phải là hướng an toàn của tầng in.
 * Tầng in fail-closed (workstation `orderOnHold` đọc mọi lỗi thành "treo"); tầng
 * giao diện fail-open và để server nói lời cuối. Đừng đảo bên nào.
 */
export function isOrderOnHold(order: Pick<CustomerOrder, "is_on_hold"> | null | undefined): boolean {
  return order?.is_on_hold === true;
}

/**
 * Phiên bản dành cho MÀN HÌNH ĐÓNG ĐƠN, nơi client tự biết câu trả lời.
 *
 * Ngay sau khi thu tiền, pos-web tự tính được phần còn thiếu của đơn (`remaining`
 * ở `useReceiptFlow`) mà không cần chờ server — và trong đúng khoảnh khắc đó thì
 * server CHƯA kịp biết, vì payment vừa mới được ghi. Nên ở màn đóng đơn, "còn
 * thiếu tiền" là bằng chứng đủ mạnh để coi là treo, độc lập với cờ.
 *
 * Đây là chỗ DUY NHẤT được suy ra trạng thái treo từ số tiền. Mọi chỗ khác dùng
 * `isOrderOnHold`, tức chỉ tin server.
 */
export function isClosingOnHold(args: {
  remaining: number;
  serverVerdict: OnHoldVerdict;
}): boolean {
  return args.remaining > 0 || args.serverVerdict === true;
}

/**
 * Lỗi này có phải "workstation từ chối in vì đơn đang treo" không.
 *
 * Server là bên cưỡng chế, nên client PHẢI biết đọc lời từ chối của nó — kể cả
 * ở những đường mà giao diện tưởng là đã chặn rồi. Ba đường có thể tới đây với
 * một cờ đã cũ: in lại từ màn lịch sử, in theo từng khách của chia bill, và
 * đường tự in khi mã đơn được chốt (`use-workstation-socket`).
 *
 * Khớp trên `code`, không khớp trên `message`: message là câu tiếng Anh dành
 * cho log và nó được phép đổi; `code` là hợp đồng.
 */
export function isOnHoldError(err: unknown): boolean {
  return (
    err instanceof ApiError &&
    err.status === 409 &&
    (err.body as { code?: unknown } | undefined)?.code === "order_on_hold"
  );
}

/** Khoá i18n cho lý do treo. Lý do lạ rơi về khoá chung, không hiện chuỗi thô. */
export function onHoldReasonKey(reason: OrderOnHoldReason | null | undefined): string {
  return reason === "open_debt" || reason === "part_paid"
    ? `pos.on_hold.reason.${reason}`
    : "pos.on_hold.reason.unknown";
}
