/**
 * Lời từ chối của tầng in, dịch ra chữ thu ngân đọc được — thuần, một bản.
 *
 * Vì sao là một hàm chứ không phải một khối `if` chép vào từng nút: mọi đường in
 * ở màn lịch sử đều nói CÙNG một thứ ngôn ngữ lỗi (workstation trả cùng bộ mã),
 * nhưng chúng là những nút khác nhau viết ở những lúc khác nhau. Hai bản sẽ
 * lệch, và lệch ở đây nghĩa là cùng một sự cố hiện ra hai câu khác nhau tuỳ thu
 * ngân bấm nút nào — đúng thứ làm người ta báo lỗi sai chỗ.
 *
 * Khớp trên `code`/`status`, KHÔNG khớp trên `message`: message của workstation
 * là câu tiếng Anh dành cho log và nó được phép đổi bất cứ lúc nào.
 */

import { ApiError } from "@/lib/api";
import { isOnHoldError } from "./on-hold";

export interface PrintToast {
  level: "error" | "warning";
  /** Khoá i18n — hàm này không tự dịch, để nó thuần và test được không cần provider. */
  key: string;
}

export function printErrorToast(err: unknown): PrintToast {
  // Đơn treo: bắt TRƯỚC nhánh 409 chung. Nhánh đó nói "thanh toán chưa xác
  // nhận", còn sự thật là "quán chưa thu đủ tiền" — hai việc khác nhau, và câu
  // sai sẽ đẩy thu ngân đi kiểm tra sai chỗ.
  if (isOnHoldError(err)) return { level: "error", key: "pos.on_hold.print_blocked" };

  if (err instanceof ApiError) {
    switch (err.status) {
      case 503:
        return { level: "error", key: "pos.order_history.reprint_no_printer" };
      // Force-pull chưa kịp: đơn CÓ thật, chỉ là chưa về tới máy này. Cảnh báo
      // chứ không phải lỗi — bấm lại là được.
      case 504:
        return { level: "warning", key: "pos.kitchen.sync_pending" };
      case 422:
        return { level: "error", key: "pos.order_history.reprint_nothing_to_print" };
      case 409:
        return { level: "error", key: "pos.order_history.reprint_not_settled" };
      case 404:
        return { level: "error", key: "pos.order_history.reprint_not_found" };
    }
  }
  return { level: "error", key: "pos.order.reprint_failed" };
}
