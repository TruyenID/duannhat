import { test } from "node:test";
import assert from "node:assert/strict";
import {
  CARD_BILLING_FIELDS,
  billingDetailsForConfirm,
  billingKeysRequiredAtConfirm,
  type BillingFieldMode,
} from "./stripe-billing-details.ts";

/**
 * #2790 — RATCHET. Đây là bài duy nhất chặn được lỗi đã làm chết 100% thanh
 * toán thẻ: `fields.billingDetails` opt-out một field mà confirm không gửi
 * field đó. Nó đo QUAN HỆ giữa hai cấu hình, không đo một hằng số — đổi
 * `CARD_BILLING_FIELDS` mà quên `billingDetailsForConfirm` là ĐỎ.
 */
test("mọi field Element KHÔNG thu đều có mặt trong payload confirm", () => {
  const required = billingKeysRequiredAtConfirm();
  const payload = billingDetailsForConfirm({ name: "A", email: "b@c.d", phone: "090" });

  assert.ok(required.length > 0, "không field nào opt-out — bài này mất nghĩa, xem lại CARD_BILLING_FIELDS");
  for (const key of required) {
    assert.ok(
      key in payload,
      `fields.billingDetails.${key} = "never" nhưng confirmParams.payment_method_data.billing_details thiếu "${key}" — ` +
        `stripe.confirmPayment() sẽ NÉM IntegrationError và PaymentIntent đứng ở requires_payment_method (#2790)`,
    );
  }
});

test("field opt-out mà ta không có giá trị thì gửi null, KHÔNG bỏ khoá", () => {
  const payload = billingDetailsForConfirm(undefined);
  for (const key of billingKeysRequiredAtConfirm()) {
    assert.ok(key in payload, `thiếu khoá "${key}" khi không có dữ liệu khách`);
    assert.equal(payload[key], null, `"${key}" phải là null, không phải chuỗi rỗng hay undefined`);
  }
});

test("chuỗi chỉ có khoảng trắng được coi là không có dữ liệu", () => {
  const payload = billingDetailsForConfirm({ name: "   ", email: "", phone: "  \t " });
  assert.deepEqual(payload, { name: null, email: null, phone: null });
});

test("giá trị thật được trim rồi gửi nguyên", () => {
  const payload = billingDetailsForConfirm({ name: "  Nguyen Van A  ", email: " a@b.c ", phone: " 09012345678 " });
  assert.deepEqual(payload, { name: "Nguyen Van A", email: "a@b.c", phone: "09012345678" });
});

test("address `never` chỉ hợp lệ nếu payload confirm THẬT SỰ gửi address", () => {
  // #2804 — bản trước là `assert.equal(CARD_BILLING_FIELDS.address, "auto")`:
  // một hằng số vọng lại chính nó, đỏ khi ai đó đổi cấu hình **đúng cách** và
  // xanh ngay cả khi payload đã hỏng. Bài này đo QUAN HỆ, nên nó chỉ kêu khi
  // hai bên thật sự lệch.
  //
  // Đo trong Chromium (#2790): `address: "never"` làm Stripe.js đòi
  // `address.country` VÀ `address.postal_code` lúc confirm, mà khách chưa bao
  // giờ nhập địa chỉ. Nên nếu ai đổi sang `never`, họ phải gửi address thật.
  const mode = (CARD_BILLING_FIELDS as Record<string, BillingFieldMode>).address;
  if (mode !== "never") return;
  const payload = billingDetailsForConfirm({ name: "A" });
  assert.ok(
    "address" in payload,
    "address = never nhưng payload confirm không có khoá address ⇒ thẻ chết 100% như #2790",
  );
});

test("KHÔNG ghim chiều 'field auto thì cấm gửi' — đã ĐO và nó SAI", () => {
  // #2804 đề nghị ratchet chiều ngược: field `auto` ⇒ không được gửi giá trị,
  // vì "Stripe.js cũng ném IntegrationError". Đo thật trong Chromium với PI
  // test: `fields.billingDetails.name = "auto"` mà vẫn gửi `name` ở confirm ⇒
  // `piStatus: succeeded`, KHÔNG ném gì. Docs cũng nói vậy:
  // "Details collected by Elements will override values passed here."
  // (docs.stripe.com/js/payment_intents/confirm_payment)
  //
  // Nên ghim chiều đó là ghim một bất biến KHÔNG TỒN TẠI. Giữ lại phép đo này
  // dưới dạng test để lần sau không ai thêm lại nó theo trí nhớ.
  const payload = billingDetailsForConfirm({ name: "A", email: "b@c.d", phone: "090" });
  const notNever = Object.keys(CARD_BILLING_FIELDS).filter(
    (k) => (CARD_BILLING_FIELDS as Record<string, BillingFieldMode>)[k] !== "never",
  );
  assert.ok(notNever.length > 0, "cấu hình không còn field nào khác `never` — xem lại bài này");
  assert.ok(
    notNever.every((k) => !(k in payload)),
    "hôm nay ta KHÔNG gửi field non-never — không phải vì Stripe cấm, mà vì ta không có dữ liệu cho chúng",
  );
});
