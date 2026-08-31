import { test } from "node:test";
import assert from "node:assert/strict";
import {
  AWAITING_STATUSES,
  decideConfirmOutcome,
  type ConfirmDecision,
} from "./stripe-confirm-outcome.ts";

/**
 * #2804 — test HÀNH VI, không phải test đọc mã nguồn.
 *
 * Bài ratchet cũ (`stripe-card-section.deferred-submit.test.ts`) khớp CHUỖI
 * trong file component, nên tiêm lại đúng lỗi #2790 vẫn cho 594/594 xanh. Bài
 * này gọi thẳng hàm quyết định, nên đổi luật là đỏ.
 */

test("chỉ 'succeeded' mới là ĐÃ THU TIỀN", () => {
  const d = decideConfirmOutcome("succeeded");
  assert.equal(d.succeeded, true);
  assert.equal(d.pending, false);
  assert.equal(d.errorKey, null);
  assert.equal(d.gate, "succeeded");
});

test("requires_payment_method là LỖI — đây đúng là hình dạng đã giết 16 lượt trả thẻ (#2790)", () => {
  const d = decideConfirmOutcome("requires_payment_method");
  assert.equal(
    d.succeeded,
    false,
    "báo succeeded ⇒ đơn đóng mà KHÔNG có tiền; đó chính là sự cố #2790",
  );
  assert.equal(d.pending, false, "không phải trạng thái chờ — nó sẽ không bao giờ tự chuyển");
  assert.equal(d.errorKey, "cardNotCharged");
  assert.equal(
    d.gate,
    "error",
    "gate phải reset về error, nếu không lượt bấm kế tiếp không submit() lại được (#2741)",
  );
});

test("processing và requires_action là CHỜ, không phải lỗi (#1125)", () => {
  for (const status of AWAITING_STATUSES) {
    const d = decideConfirmOutcome(status);
    assert.equal(d.pending, true, `${status} phải là pending`);
    assert.equal(d.succeeded, false, `${status} chưa có tiền`);
    assert.equal(d.errorKey, null, `${status} không được hiện lỗi cho khách`);
    assert.equal(d.gate, "pending", `${status} giữ nguyên method đã thu`);
  }
});

test("không có PaymentIntent (undefined / null) là LỖI, không phải thành công", () => {
  for (const status of [undefined, null]) {
    const d = decideConfirmOutcome(status);
    assert.equal(d.succeeded, false, `status=${String(status)} không được coi là đã thu tiền`);
    assert.equal(d.errorKey, "cardNotCharged");
    assert.equal(d.gate, "error");
  }
});

test("mọi trạng thái lạ đều rơi về LỖI — mặc định fail-closed", () => {
  // `requires_capture` cố ý nằm trong danh sách này: đường thanh toán hiện tại
  // không dùng manual capture (xem docblock của decideConfirmOutcome). Ai bật
  // nó phải sửa hàm quyết định TRƯỚC, và bài test này sẽ buộc phải sửa theo.
  for (const status of ["requires_confirmation", "requires_capture", "canceled", "wat"]) {
    const d = decideConfirmOutcome(status);
    assert.equal(d.succeeded, false, `${status} không được coi là đã thu tiền`);
    assert.equal(d.gate, "error", `${status} phải reset gate`);
  }
});

test("ĐÚNG MỘT trạng thái cho succeeded, và succeeded/pending/error loại trừ nhau", () => {
  const statuses = [
    "succeeded",
    "processing",
    "requires_action",
    "requires_payment_method",
    "requires_confirmation",
    "requires_capture",
    "canceled",
  ];
  const decisions = statuses.map(decideConfirmOutcome);

  assert.equal(
    decisions.filter((d) => d.succeeded).length,
    1,
    "nhiều hơn một trạng thái báo đã-thu-tiền là lỗi tiền",
  );
  for (const [i, d] of decisions.entries()) {
    const flags: Array<keyof ConfirmDecision> = ["succeeded", "pending"];
    const on = flags.filter((f) => d[f] === true).length;
    assert.ok(on <= 1, `${statuses[i]}: succeeded và pending không được bật cùng lúc`);
    assert.equal(
      d.errorKey !== null,
      d.gate === "error",
      `${statuses[i]}: có lỗi hiển thị cho khách thì gate phải là error, và ngược lại`,
    );
  }
});
