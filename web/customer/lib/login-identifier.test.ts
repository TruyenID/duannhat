import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { loginOutcome, loginPayload, looksLikeEmail } from "./login-identifier.ts";

describe("loginPayload", () => {
  it("gửi trường `identifier`, không gửi `email`", () => {
    const payload = loginPayload("0901234567", "hunter2", "customer-web");
    assert.equal(payload.identifier, "0901234567");
    assert.equal("email" in payload, false);
    assert.equal(payload.device_name, "customer-web");
  });

  it("cắt khoảng trắng — dán số từ tin nhắn thường kèm một dấu cách cuối", () => {
    assert.equal(loginPayload("  a@b.co ", "x", "d").identifier, "a@b.co");
  });
});

describe("looksLikeEmail", () => {
  it("phân biệt được email với số điện thoại", () => {
    assert.equal(looksLikeEmail("van.khanh@example.com"), true);
    assert.equal(looksLikeEmail(" van.khanh@example.com "), true);
    assert.equal(looksLikeEmail("0901234567"), false);
    assert.equal(looksLikeEmail("+84901234567"), false);
    assert.equal(looksLikeEmail(""), false);
  });
});

describe("loginOutcome", () => {
  // Cái test này là lý do module tồn tại: trước #1782 mọi lỗi 422 đều quy về
  // "email hoặc mật khẩu không đúng", nên ca "số điện thoại trùng nhiều tài
  // khoản" — ca DUY NHẤT mà khách phải làm một việc khác (dùng email) — hiện ra
  // đúng như một lần gõ sai mật khẩu, và khách gõ lại mãi mãi.
  it("422 trên trường `identifier` → hiện NGUYÊN câu của backend", () => {
    const outcome = loginOutcome(
      {
        status: 422,
        body: {
          message: "The given data was invalid.",
          errors: {
            identifier: [
              "Số điện thoại này đang gắn với nhiều tài khoản. Vui lòng đăng nhập bằng email.",
            ],
          },
        },
      },
      "0901234567",
    );

    assert.deepEqual(outcome, {
      kind: "fieldMessage",
      message:
        "Số điện thoại này đang gắn với nhiều tài khoản. Vui lòng đăng nhập bằng email.",
    });
  });

  // Mặt kia của cùng một ràng buộc. Backend đặt `auth.failed` lên trường
  // `email` chứ không phải `identifier`, và đó chính là thứ giữ cho hàm này
  // không bao giờ in ra một câu nói về sự tồn tại của tài khoản.
  it("422 trên trường `email` (sai mật khẩu) → KHÔNG mượn chữ của server", () => {
    const outcome = loginOutcome(
      {
        status: 422,
        body: { errors: { email: ["These credentials do not match our records."] } },
      },
      "a@b.co",
    );

    assert.deepEqual(outcome, { kind: "invalid" });
  });

  it("403 email_not_verified → dùng địa chỉ THẬT do backend trả về, không phải chuỗi khách gõ", () => {
    const outcome = loginOutcome(
      { status: 403, body: { code: "email_not_verified", email: "that@example.com" } },
      // Khách đăng nhập bằng SỐ — gửi mã tới chuỗi này là gửi vào hư không.
      "0901234567",
    );

    assert.deepEqual(outcome, { kind: "unverified", email: "that@example.com" });
  });

  it("403 không kèm `email` nhưng khách gõ email → vẫn vào được màn nhập mã", () => {
    const outcome = loginOutcome({ status: 403, body: { code: "email_not_verified" } }, " a@b.co ");
    assert.deepEqual(outcome, { kind: "unverified", email: "a@b.co" });
  });

  it("403 không kèm `email` và khách gõ SỐ → không treo ở màn nhập mã trống", () => {
    const outcome = loginOutcome({ status: 403, body: { code: "email_not_verified" } }, "0901234567");
    assert.deepEqual(outcome, { kind: "invalid" });
  });

  it("403 vì lý do khác (không phải chưa xác nhận) → invalid", () => {
    const outcome = loginOutcome({ status: 403, body: { code: "account_disabled" } }, "a@b.co");
    assert.deepEqual(outcome, { kind: "invalid" });
  });

  it("lỗi mạng (không có status) và body lạ → invalid, không ném lỗi", () => {
    assert.deepEqual(loginOutcome({}, "a@b.co"), { kind: "invalid" });
    assert.deepEqual(loginOutcome({ status: 422, body: null }, "a@b.co"), { kind: "invalid" });
    assert.deepEqual(loginOutcome({ status: 422, body: "boom" }, "a@b.co"), { kind: "invalid" });
    assert.deepEqual(
      loginOutcome({ status: 422, body: { errors: { identifier: [] } } }, "a@b.co"),
      { kind: "invalid" },
    );
    assert.deepEqual(
      loginOutcome({ status: 422, body: { errors: { identifier: "chuỗi trần" } } }, "a@b.co"),
      { kind: "fieldMessage", message: "chuỗi trần" },
    );
  });

  it("500 → invalid", () => {
    assert.deepEqual(loginOutcome({ status: 500, body: { message: "Server Error" } }, "a@b.co"), {
      kind: "invalid",
    });
  });
});
