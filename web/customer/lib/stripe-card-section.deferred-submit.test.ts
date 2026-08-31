import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * #2741 — deferred Payment Element: submit() once per Pay click, then
 * confirmPayment. A second submit() after POST full-payment-intent left live
 * intents at requires_payment_method with no card (ORD-2026-0237).
 *
 * Source ratchet, not a Stripe.js mock: ungating submit() inside confirm()
 * is the regression.
 */
const src = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), "../components/stripe-card-section.tsx"),
  "utf8",
);


/**
 * #2790 — thân confirm() cắt bằng NEO CẤU TRÚC (mở handler → dấu đóng của
 * object handle), không bằng offset ký tự. Bản trước cắt `start + 3500` và
 * cắt bằng một dòng `return` cụ thể; cả hai đứt ngay khi confirm() dài thêm
 * hoặc đổi câu return — đúng cái đã xảy ra khi #2790 thêm try/catch. Test
 * ratchet mà neo vào độ dài thì nó đo độ dài, không đo luật.
 */
function confirmBody(): string {
  const start = src.indexOf("confirm: async (clientSecret, returnUrl)");
  assert.ok(start > 0, "confirm() handler missing");
  const end = src.indexOf("\n    }),", start);
  assert.ok(end > start, "confirm() body truncated");
  return src.slice(start, end);
}

describe("StripeCardSection deferred submit", () => {
  it("InnerForm owns a deferred-submit gate, not a raw boolean", () => {
    assert.match(src, /createDeferredSubmitGate\(\)/);
    assert.match(src, /submitGateRef/);
    assert.equal(src.includes("submittedRef"), false);
  });

  it("confirm() only submits when the gate says validate() has not already submitted", () => {
    const confirm = confirmBody();

    assert.match(confirm, /if \(submitGateRef\.current\.shouldSubmitOnConfirm\(\)\)/);
    const ungated = confirm.replace(
      /if \(submitGateRef\.current\.shouldSubmitOnConfirm\(\)\) \{[\s\S]*?\n        \}/,
      "",
    );
    assert.equal(
      ungated.includes("elementsInst.submit()"),
      false,
      "confirm() still calls submit() outside the deferred-submit gate",
    );
  });

  /**
   * #2804 — bài cũ ở đây khớp ba chuỗi `onConfirmOutcome("pending"|"succeeded")`
   * trong mã nguồn. Nó đỏ ngay khi #2804 tách luật ra hàm thuần (đúng hướng),
   * và nó vẫn XANH khi tiêm lại đúng lỗi tiền của #2790 — đo được: 594/594 xanh
   * với `requires_payment_method` bị coi là đã-thu-tiền.
   *
   * Thay bằng: (1) `lib/stripe-confirm-outcome.test.ts` đo HÀNH VI từng trạng
   * thái, và (2) bài dưới đây chỉ ghim đúng điều mà đọc-mã-nguồn có tư cách
   * ghim — rằng confirm() KHÔNG tự đặt kết cục gate bằng hằng số, mà lấy từ
   * hàm quyết định. Ghim vị trí thì phải khiêm tốn đúng mức đó.
   */
  it("confirm() lấy kết cục gate từ hàm quyết định, không hard-code", () => {
    const confirm = confirmBody();
    assert.match(
      confirm,
      /decideConfirmOutcome\(/,
      "confirm() phải hỏi lib/stripe-confirm-outcome, đừng phân loại status tại chỗ",
    );
    assert.match(
      confirm,
      /onConfirmOutcome\(decision\.gate\)/,
      "gate phải theo quyết định; hard-code lại là mở đường cho #2790 quay lại",
    );
    // Nhánh ném vẫn tự đặt "error" — đó là đường KHÔNG có PaymentIntent để
    // phân loại, nên nó hợp lệ và phải còn.
    assert.match(confirm, /onConfirmOutcome\("error"\)/);
  });

  it("loadError short-circuits validate() and confirm() before any submit()", () => {
    const validateStart = src.indexOf("validate: async ()");
    const validate = src.slice(validateStart, validateStart + 600);
    assert.match(validate, /if \(loadErrorRef\.current\) return \{ error: loadErrorRef\.current \}/);

    const confirmStart = src.indexOf("confirm: async (clientSecret, returnUrl)");
    const confirm = src.slice(confirmStart, confirmStart + 400);
    assert.match(
      confirm,
      /if \(loadErrorRef\.current\) return \{ succeeded: false, error: loadErrorRef\.current \}/,
    );
  });
});
