/**
 * #2049 — luật "đơn treo" phía client.
 *
 * Ba hàm, ba hướng an toàn KHÁC NHAU, và chính chỗ đó là chỗ dễ sửa hỏng nhất:
 *
 *   `mergeOnHold`     `null` không được hạ một câu trả lời đã biết
 *   `isOrderOnHold`   `null` đọc là "không treo" — fail-OPEN (giao diện)
 *   `isClosingOnHold` còn thiếu tiền là treo, không cần chờ server
 *
 * Nếu ai đó "dọn cho nhất quán" bằng cách bắt cả ba fail-closed thì mọi màn hình
 * chưa được đóng dấu sẽ giấu nút in của cả những đơn đã trả đủ. Nếu bắt cả ba
 * fail-open thì đơn vừa ghi nợ xong lại hiện nút in. Từng hàm được ghim riêng.
 */

import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api";
import {
  isClosingOnHold,
  isOnHoldError,
  isOrderOnHold,
  mergeOnHold,
  onHoldReasonKey,
} from "./on-hold";
import type { CustomerOrder } from "../types";

describe("mergeOnHold", () => {
  it("null KHÔNG hạ được một verdict đã biết", () => {
    // Đây là toàn bộ lý do hàm này tồn tại. Mọi endpoint GHI của Cloud (sửa
    // món, áp coupon, gộp bàn) trả về order KHÔNG kèm cờ. Không có luật này thì
    // sửa một dòng món trên đơn treo là cờ tắt và hai nút in hiện lại.
    expect(mergeOnHold(true, null)).toBe(true);
    expect(mergeOnHold(false, null)).toBe(false);
  });

  it("một verdict thật thì ghi đè, cả hai chiều", () => {
    // Chiều false→true là lúc khách vừa được ghi nợ: cờ PHẢI bật ngay.
    expect(mergeOnHold(false, true)).toBe(true);
    // Chiều true→false là lúc khách vừa trả nợ; chỉ Cloud nói được câu này.
    expect(mergeOnHold(true, false)).toBe(false);
  });
});

describe("isOrderOnHold", () => {
  it("chỉ `true` mới là treo — fail-OPEN có chủ ý", () => {
    // Hàm này quyết định có ẨN nút in không. Đọc null thành "treo" sẽ giấu nút
    // in của MỌI đơn trên mọi màn chưa được đóng dấu — hỏng nghiệp vụ chính để
    // phòng một trường hợp mà server đã chặn cứng rồi.
    expect(isOrderOnHold({ is_on_hold: true } as CustomerOrder)).toBe(true);
    expect(isOrderOnHold({ is_on_hold: false } as CustomerOrder)).toBe(false);
    expect(isOrderOnHold({ is_on_hold: null } as CustomerOrder)).toBe(false);
    expect(isOrderOnHold({} as CustomerOrder)).toBe(false);
    expect(isOrderOnHold(null)).toBe(false);
    expect(isOrderOnHold(undefined)).toBe(false);
  });
});

describe("isClosingOnHold", () => {
  it("còn thiếu tiền là treo, kể cả khi server chưa biết", () => {
    // Khoảnh khắc đóng đơn: payment vừa ghi xong nên cờ server còn là verdict
    // TRƯỚC phiên thu. Tin nó ở đây sẽ mở ra màn CÓ nút in cho đơn vừa thiếu tiền.
    expect(isClosingOnHold({ remaining: 120_000, serverVerdict: null })).toBe(true);
    expect(isClosingOnHold({ remaining: 120_000, serverVerdict: false })).toBe(true);
  });

  it("thu đủ + server nói không treo ⇒ không treo", () => {
    expect(isClosingOnHold({ remaining: 0, serverVerdict: null })).toBe(false);
    expect(isClosingOnHold({ remaining: 0, serverVerdict: false })).toBe(false);
  });

  it("thu đủ nhưng server nói treo ⇒ vẫn treo (ghi nợ toàn bộ)", () => {
    // Ghi nợ toàn bộ đẩy paid_amount lên bằng total, nên `remaining` bằng 0
    // trong khi quán chưa cầm đồng nào. Chỉ cờ server bắt được nhánh này.
    expect(isClosingOnHold({ remaining: 0, serverVerdict: true })).toBe(true);
  });
});

describe("isOnHoldError", () => {
  it("nhận ra lời từ chối của workstation qua `code`, không qua message", () => {
    const err = new ApiError(409, { code: "order_on_hold", message: "whatever" });
    expect(isOnHoldError(err)).toBe(true);
  });

  it("không nhận nhầm 409 khác — 'payment not confirmed' là chuyện khác hẳn", () => {
    expect(isOnHoldError(new ApiError(409, { message: "payment not confirmed" }))).toBe(false);
    expect(isOnHoldError(new ApiError(503, { code: "order_on_hold" }))).toBe(false);
    expect(isOnHoldError(new Error("boom"))).toBe(false);
    expect(isOnHoldError(null)).toBe(false);
  });
});

describe("onHoldReasonKey", () => {
  it("lý do lạ rơi về khoá chung, không bao giờ hiện chuỗi thô từ server", () => {
    expect(onHoldReasonKey("open_debt")).toBe("pos.on_hold.reason.open_debt");
    expect(onHoldReasonKey("part_paid")).toBe("pos.on_hold.reason.part_paid");
    expect(onHoldReasonKey(null)).toBe("pos.on_hold.reason.unknown");
    expect(onHoldReasonKey("something_new" as never)).toBe("pos.on_hold.reason.unknown");
  });
});
