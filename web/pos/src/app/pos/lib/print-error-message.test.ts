import { describe, expect, it } from "vitest";

import { ApiError } from "@/lib/api";
import { printErrorToast } from "./print-error-message";

/**
 * Một sự cố, một câu. Bài test này tồn tại vì bảng ánh xạ dưới đây từng được
 * chép tay vào từng nút in, và hai bản chép sẽ lệch — lệch ở đây nghĩa là cùng
 * một máy in hết giấy hiện ra hai câu khác nhau tuỳ thu ngân bấm nút nào.
 */
describe("printErrorToast", () => {
  // Thứ tự là điều đắt nhất ở đây. Đơn treo TRẢ VỀ 409 y như "thanh toán chưa
  // xác nhận", nên nếu nhánh 409 chung chạy trước thì thu ngân đọc được câu sai
  // và đi kiểm tra sai chỗ: sự thật là quán chưa thu đủ tiền.
  it("đơn treo thắng nhánh 409 chung", () => {
    const onHold = new ApiError(409, { code: "order_on_hold", message: "order is on hold" });
    expect(printErrorToast(onHold)).toEqual({
      level: "error",
      key: "pos.on_hold.print_blocked",
    });
  });

  it("409 không phải treo thì đọc là thanh toán chưa xác nhận", () => {
    expect(printErrorToast(new ApiError(409, { message: "payment not confirmed" })).key).toBe(
      "pos.order_history.reprint_not_settled",
    );
  });

  // Force-pull chưa kịp: đơn CÓ thật, chỉ chưa về tới máy này. Cảnh báo chứ
  // không phải lỗi — bấm lại là được, và một toast đỏ ở đây dạy thu ngân bỏ qua
  // toast đỏ.
  it("504 là CẢNH BÁO, không phải lỗi", () => {
    expect(printErrorToast(new ApiError(504, { message: "force-pull timed out" }))).toEqual({
      level: "warning",
      key: "pos.kitchen.sync_pending",
    });
  });

  it.each([
    [503, "pos.order_history.reprint_no_printer"],
    [422, "pos.order_history.reprint_nothing_to_print"],
    [404, "pos.order_history.reprint_not_found"],
  ])("%i → %s", (status, key) => {
    expect(printErrorToast(new ApiError(status, {})).key).toBe(key);
  });

  // Workstation không với tới được là `ApiError(0)`, và một lỗi lạ hoàn toàn
  // cũng phải ra chữ chứ không phải màn hình trắng.
  it("mã lạ và thứ không phải ApiError đều rơi về câu chung", () => {
    expect(printErrorToast(new ApiError(0, { message: "workstation unreachable" })).key).toBe(
      "pos.order.reprint_failed",
    );
    expect(printErrorToast(new Error("boom")).key).toBe("pos.order.reprint_failed");
    expect(printErrorToast(undefined).key).toBe("pos.order.reprint_failed");
  });
});
