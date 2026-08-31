import { describe, it } from "node:test";
import assert from "node:assert/strict";

import {
  correctPaymentMethod,
  defaultPaymentMethod,
  isCounterPayMethod,
  shouldOfferCounterPay,
  shouldShowCounterPayQr,
} from "./counter-pay.ts";
import { COUNTER_PAY_DEFAULTS, parseCounterPaySettings } from "./paypay-qr.ts";

const resolved = {
  stripeReady: true,
  stripeLoading: false,
  paypayEnabled: false,
  paypayLoading: false,
};

const counterOn = { counterPayEnabled: true, counterPayShowQr: true };

describe("shouldOfferCounterPay", () => {
  it("theo ĐÚNG cờ của chi nhánh, không suy diễn từ trạng thái cổng", () => {
    assert.equal(shouldOfferCounterPay(counterOn), true);
    assert.equal(
      shouldOfferCounterPay({ ...counterOn, counterPayEnabled: false }),
      false,
    );
  });

  it("mặc định của hệ thống là CHÀO — tắt phải là quyết định tường minh", () => {
    assert.equal(shouldOfferCounterPay(COUNTER_PAY_DEFAULTS), true);
  });
});

describe("shouldShowCounterPayQr", () => {
  it("QR bật/tắt độc lập, kênh trả tại quầy vẫn còn", () => {
    assert.equal(shouldShowCounterPayQr(counterOn), true);
    assert.equal(
      shouldShowCounterPayQr({ ...counterOn, counterPayShowQr: false }),
      false,
    );
  });

  it("tắt cả kênh thì QR không thể hiện — không có QR mồ côi trên màn không chào counter", () => {
    assert.equal(
      shouldShowCounterPayQr({ counterPayEnabled: false, counterPayShowQr: true }),
      false,
    );
  });
});

/**
 * Ratchet: cờ chỉ TẮT khi server nói `false` tường minh.
 *
 * Chiều này ngược với `parsePayPayAvailability` (mặc định `false`), và ngược có
 * chủ đích — ẩn một kênh thanh toán là hướng phá hoại, nên nó đòi một câu trả
 * lời thật. Một request rơi mà đọc thành "quán tắt counter" sẽ để khách ở chi
 * nhánh không cổng online đứng trước màn không có cách nào trả tiền.
 */
describe("parseCounterPaySettings", () => {
  it("đọc đúng khi server trả lời", () => {
    assert.deepEqual(
      parseCounterPaySettings({
        data: { counter_pay_enabled: false, counter_pay_show_qr: false },
      }),
      { counterPayEnabled: false, counterPayShowQr: false },
    );
  });

  it("payload rác / rỗng / thiếu khoá ⇒ mặc định BẬT, không phải tắt", () => {
    for (const raw of [null, undefined, {}, { data: null }, { data: {} }, "nope"]) {
      assert.deepEqual(parseCounterPaySettings(raw), COUNTER_PAY_DEFAULTS, String(raw));
    }
  });

  it("giá trị không phải boolean KHÔNG được đọc thành tắt", () => {
    assert.deepEqual(
      parseCounterPaySettings({ data: { counter_pay_enabled: 0, counter_pay_show_qr: "" } }),
      COUNTER_PAY_DEFAULTS,
    );
  });
});

describe("correctPaymentMethod", () => {
  it("đẩy counter/call_staff về card khi counter không còn được chào", () => {
    assert.equal(correctPaymentMethod("counter", false), "card");
    assert.equal(correctPaymentMethod("call_staff", false), "card");
  });

  it("không đụng lựa chọn online", () => {
    assert.equal(correctPaymentMethod("card", false), "card");
    assert.equal(correctPaymentMethod("qr_pay", false), "qr_pay");
    assert.equal(correctPaymentMethod("qr_pay", true), "qr_pay");
  });

  it("giữ counter khi counter đang được chào", () => {
    assert.equal(correctPaymentMethod("counter", true), "counter");
  });
});

describe("defaultPaymentMethod", () => {
  it("thẻ khi Stripe dùng được", () => {
    assert.equal(defaultPaymentMethod(resolved), "card");
  });

  it("PayPay khi chỉ có PayPay — KHÔNG rơi về thẻ", () => {
    assert.equal(
      defaultPaymentMethod({ ...resolved, stripeReady: false, paypayEnabled: true }),
      "qr_pay",
    );
  });

  it("counter khi không cổng online nào", () => {
    assert.equal(defaultPaymentMethod({ ...resolved, stripeReady: false }), "counter");
  });

  it("đang dò thì đứng ở thẻ — radio luôn có mặt, nên không chọn vào ô có thể biến mất", () => {
    assert.equal(
      defaultPaymentMethod({ ...resolved, stripeReady: false, stripeLoading: true }),
      "card",
    );
    assert.equal(
      defaultPaymentMethod({ ...resolved, stripeReady: false, paypayLoading: true }),
      "card",
    );
  });
});

/**
 * Hai hàm trên chạy nối nhau ở call-site (`paymentChoice ?? default`, rồi
 * `correct`). Chúng đúng RIÊNG LẺ mà vẫn cho ra ô hỏng khi ghép — đó chính là
 * lỗi các bài này ghim, nên phải test cả CHUỖI chứ không chỉ từng mắt.
 */
describe("mặc định + hiệu chỉnh, ghép như ở call-site", () => {
  const selected = (
    gw: typeof resolved,
    choice: string | null = null,
    counter = counterOn,
  ) =>
    correctPaymentMethod(
      choice ?? defaultPaymentMethod(gw),
      shouldOfferCounterPay(counter),
    );

  it("chỉ có PayPay ⇒ chọn qr_pay, KHÔNG phải card đang báo stripeNotConfigured", () => {
    assert.equal(selected({ ...resolved, stripeReady: false, paypayEnabled: true }), "qr_pay");
  });

  it("không cổng nào ⇒ chọn counter, đúng ô duy nhất trả được tiền", () => {
    assert.equal(selected({ ...resolved, stripeReady: false }), "counter");
  });

  it("khách đã bấm thì mặc định im — kể cả khi ta thấy ô khác 'hợp lý hơn'", () => {
    assert.equal(
      selected({ ...resolved, stripeReady: false, paypayEnabled: true }, "card"),
      "card",
    );
  });

  /**
   * #2806 — cái bẫy mà cặp hàm này sinh ra khi cờ của quán vào cuộc.
   *
   * `defaultPaymentMethod` vẫn đọc trạng thái cổng, nên ở chi nhánh không cổng
   * online nào nó vẫn đề xuất `counter`. Nếu quán vừa TẮT counter thì đề xuất
   * đó trỏ vào một radio không render — đúng loại ngõ cụt mà `correctPaymentMethod`
   * tồn tại để chặn. Bài này ghim rằng mắt thứ hai của chuỗi bắt được.
   */
  it("quán tắt counter ⇒ mặc định counter bị hiệu chỉnh về card, không trỏ vào radio vắng mặt", () => {
    assert.equal(
      selected({ ...resolved, stripeReady: false }, null, {
        counterPayEnabled: false,
        counterPayShowQr: true,
      }),
      "card",
    );
  });

  it("counter khách bấm vẫn bị đẩy về card khi quán tắt kênh đó", () => {
    assert.equal(
      selected(resolved, "counter", { counterPayEnabled: false, counterPayShowQr: true }),
      "card",
    );
  });
});

describe("isCounterPayMethod", () => {
  it("nhận cả call_staff — gọi nhân viên tới thu vẫn là trả tại quầy", () => {
    assert.equal(isCounterPayMethod("counter"), true);
    assert.equal(isCounterPayMethod("call_staff"), true);
    assert.equal(isCounterPayMethod("card"), false);
    assert.equal(isCounterPayMethod("qr_pay"), false);
  });
});
