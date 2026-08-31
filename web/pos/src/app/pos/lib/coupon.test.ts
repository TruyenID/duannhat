import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api";
import { couponMayBeChanged, parseCouponError } from "./coupon";
import type { CustomerOrder } from "../types";

const order = (status: string) =>
  ({ id: "o1", status }) as unknown as CustomerOrder;

describe("couponMayBeChanged — bản sao của allowlist hai đầu", () => {
  /*
   * Cloud       `OrderCouponService::assertOrderModifiable`
   * Workstation `OrderCouponMutable` (order_mutation_gate.go)
   *
   * Hai danh sách khớp nhau. Nới thêm một trạng thái ở đây mà backend không nới
   * là mời thu ngân bấm để ăn 422 giữa lúc khách đứng chờ.
   */
  it.each(["open", "dining", "pending", "confirmed", "checkout"])(
    "cho phép ở %s",
    (s) => expect(couponMayBeChanged(order(s))).toBe(true),
  );

  it("CHẶN ở `paying` — đơn đã nhận một phần tiền", () => {
    expect(couponMayBeChanged(order("paying"))).toBe(false);
  });

  it.each(["closed", "voided", "expired", ""])("chặn ở %s", (s) =>
    expect(couponMayBeChanged(order(s))).toBe(false),
  );

  it("chặn khi không có đơn", () => {
    expect(couponMayBeChanged(null)).toBe(false);
  });
});

describe("parseCouponError", () => {
  it("rút error_code + meta ra khỏi ApiError 422", () => {
    const err = new ApiError(
      422,
      { error_code: "coupon_exclusive_conflict", meta: { exclusive_item_names: ["Phở"] } },
      "unprocessable",
    );

    expect(parseCouponError(err)).toEqual({
      code: "coupon_exclusive_conflict",
      meta: { exclusive_item_names: ["Phở"] },
    });
  });

  it("ApiError KHÔNG mang error_code → generic, không ném", () => {
    expect(parseCouponError(new ApiError(500, {}, "boom"))).toEqual({
      code: "generic",
    });
  });

  it("lỗi mạng / lỗi lạ → generic — vẫn nói được điều gì đó, thay vì im", () => {
    expect(parseCouponError(new Error("offline"))).toEqual({ code: "generic" });
    expect(parseCouponError(undefined)).toEqual({ code: "generic" });
    expect(parseCouponError("nope")).toEqual({ code: "generic" });
  });
});
