import { describe, it } from "node:test";
import assert from "node:assert/strict";

import {
  canSelectPaymentOption,
  isPaymentChoiceLocked,
  paymentOptionFrom,
  paymentOptionLabelId,
  paymentStateFor,
  payPayRowShown,
  type OnlineGateway,
  type PaymentChannel,
  type PaymentOption,
} from "./payment-method-choice.ts";

/**
 * #3118 — trước bài này, danh sách radio phương thức thanh toán của màn dine-in
 * KHÔNG có một rào nào. Đo được: gỡ `setOnlineGateway` khỏi `choosePaymentOption`
 * thì 607 test của customer-web vẫn xanh, và triệu chứng duy nhất là khách bấm
 * "PayPay" rồi trả bằng thẻ.
 */

describe("#3118 chiều XUÔI — cặp state hai tầng chiếu xuống dòng đang sáng", () => {
  it("kênh online + cổng stripe ⇒ dòng thẻ", () => {
    assert.equal(paymentOptionFrom("online", "stripe"), "card");
  });

  it("kênh online + cổng paypay ⇒ dòng PayPay", () => {
    assert.equal(paymentOptionFrom("online", "paypay"), "qr_pay");
  });

  it("kênh counter THẮNG cổng — cổng lúc đó không mang nghĩa gì", () => {
    // Đây là ca dễ làm sai nhất và nó im lặng: khách chọn "tại quầy" trong khi
    // `onlineGateway` còn giữ "paypay" từ lần bấm trước. Đọc cổng trước kênh sẽ
    // làm dòng PayPay sáng lên trong khi khách đã chọn trả tại quầy.
    assert.equal(paymentOptionFrom("counter", "paypay"), "counter");
    assert.equal(paymentOptionFrom("counter", "stripe"), "counter");
  });
});

describe("#3118 chiều NGƯỢC — dòng được bấm ghi xuống cả hai tầng", () => {
  it("dòng thẻ ghi ĐỦ hai tầng, không chỉ tầng kênh", () => {
    // Chính là đột biến mà trước đây không gì bắt được: nếu chỉ ghi
    // `method: "online"` mà bỏ cổng, khách đang ở PayPay bấm sang "thẻ" sẽ vẫn
    // nằm ở cổng PayPay — bấm một đằng, trả một nẻo.
    assert.deepEqual(paymentStateFor("card"), { method: "online", gateway: "stripe" });
  });

  it("dòng PayPay ghi đủ hai tầng", () => {
    assert.deepEqual(paymentStateFor("qr_pay"), { method: "online", gateway: "paypay" });
  });

  it("dòng tại quầy KHÔNG đụng tầng cổng", () => {
    // `gateway: null` nghĩa là "đừng ghi", khác hẳn với ghi một cổng mặc định.
    // Ghi đè ở đây sẽ âm thầm đổi lựa chọn cổng của khách khi họ chỉ ghé qua
    // "tại quầy" rồi quay lại.
    assert.deepEqual(paymentStateFor("counter"), { method: "counter", gateway: null });
  });
});

describe("#3118 XUÔI ∘ NGƯỢC = phép đồng nhất", () => {
  it("bấm dòng nào thì dòng đó sáng — cả ba, không dòng nào lệch", () => {
    // Bất biến thật của phép chiếu. Một cặp hàm chiếu mà không khứ hồi được thì
    // sẽ có ít nhất một dòng bấm vào rồi sáng sang dòng khác.
    for (const option of ["card", "qr_pay", "counter"] as PaymentOption[]) {
      const next = paymentStateFor(option);
      const gateway: OnlineGateway = next.gateway ?? "stripe";
      assert.equal(
        paymentOptionFrom(next.method, gateway),
        option,
        `bấm "${option}" nhưng dòng sáng lại là dòng khác`,
      );
    }
  });

  it("giữ đúng cả khi cổng cũ còn sót lại từ lần bấm trước", () => {
    // "tại quầy" không ghi cổng, nên cổng cũ vẫn nằm đó. Dòng sáng vẫn phải là
    // "tại quầy" bất kể cổng cũ là gì.
    for (const stale of ["paypay", "stripe"] as OnlineGateway[]) {
      const next = paymentStateFor("counter");
      assert.equal(paymentOptionFrom(next.method, stale), "counter");
    }
  });
});

describe("#3118 khoá khi đã mint mã PayPay — luật TIỀN, không phải luật giao diện", () => {
  it("chưa mint thì không khoá", () => {
    assert.equal(isPaymentChoiceLocked(null), false);
    assert.equal(isPaymentChoiceLocked(undefined), false);
  });

  it("đã mint thì khoá", () => {
    assert.equal(isPaymentChoiceLocked({ merchantPaymentId: "tempoqr-abc" }), true);
  });

  it("đang khoá: KHÔNG rời được sang dòng khác", () => {
    // Mã đã sống ở phía PayPay. Rời khỏi nó im lặng là để lại một mã quét được
    // mà trên màn không còn gì theo dõi — khách quét, tiền đi, màn hình đang nói
    // về một phương thức khác. Đường ra là bấm huỷ tường minh.
    assert.equal(canSelectPaymentOption("card", "qr_pay", true), false);
    assert.equal(canSelectPaymentOption("counter", "qr_pay", true), false);
  });

  it("đang khoá: bấm lại CHÍNH dòng đang chọn vẫn ăn", () => {
    // Chặn cả cú bấm này sẽ làm ô trông như hỏng, mà nó vốn là no-op vô hại.
    assert.equal(canSelectPaymentOption("qr_pay", "qr_pay", true), true);
  });

  it("không khoá thì đi đâu cũng được", () => {
    for (const next of ["card", "qr_pay", "counter"] as PaymentOption[]) {
      for (const current of ["card", "qr_pay", "counter"] as PaymentOption[]) {
        assert.equal(canSelectPaymentOption(next, current, false), true);
      }
    }
  });
});

describe("#3118 dòng PayPay hiện lúc nào", () => {
  it("chi nhánh mint được ⇒ hiện, BẤT KỂ kênh đang chọn là gì", () => {
    // Khác hẳn hàng tab cũ (`showTabs` tắt khi khách đang ở "tại quầy"). Một
    // danh sách mà các dòng biến mất khi ta di chuyển giữa chúng thì không phải
    // danh sách.
    assert.equal(payPayRowShown(true, null), true);
  });

  it("chi nhánh KHÔNG mint được và chưa có mã ⇒ ẩn", () => {
    assert.equal(payPayRowShown(false, null), false);
  });

  it("mã đã mint GIỮ dòng sống kể cả khi năng lực nói không", () => {
    // Cùng lý do `showQrPanel` xếp trên `paypayEnabled`: mã đã phát ra ngoài
    // rồi thì không được phép biến mất khỏi màn.
    assert.equal(payPayRowShown(false, { merchantPaymentId: "tempoqr-abc" }), true);
  });
});

describe("#3118 a11y — mỗi radio phải có TÊN", () => {
  it("mỗi dòng một id nhãn riêng, không trùng nhau", () => {
    const ids = (["card", "qr_pay", "counter"] as PaymentOption[]).map(paymentOptionLabelId);
    assert.equal(new Set(ids).size, ids.length, "hai dòng dùng chung một id nhãn");
  });

  it("id ổn định — nó là hợp đồng giữa aria-labelledby và phần tử nhãn", () => {
    // Ghim giá trị vì đây không phải chi tiết nội bộ: `aria-labelledby` của
    // radio và `id` của thẻ <p> phải khớp nhau, và chúng nằm ở hai chỗ khác
    // nhau trong JSX. Đổi một bên mà quên bên kia thì radio mất tên trở lại và
    // KHÔNG có gì đỏ ngoài bài này.
    assert.equal(paymentOptionLabelId("card"), "payment-option-label-card");
    assert.equal(paymentOptionLabelId("qr_pay"), "payment-option-label-qr_pay");
    assert.equal(paymentOptionLabelId("counter"), "payment-option-label-counter");
  });
});

describe("#3118 kiểu — ba dòng là tập ĐÓNG", () => {
  it("mọi PaymentOption đều chiếu ra một PaymentChannel hợp lệ", () => {
    const channels: PaymentChannel[] = ["online", "counter"];
    for (const option of ["card", "qr_pay", "counter"] as PaymentOption[]) {
      assert.ok(
        channels.includes(paymentStateFor(option).method),
        `"${option}" chiếu ra kênh không hợp lệ`,
      );
    }
  });
});
