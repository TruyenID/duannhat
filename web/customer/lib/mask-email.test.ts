import { test } from "node:test";
import assert from "node:assert/strict";

import { maskEmail } from "./mask-email.ts";

// ---------------------------------------------------------------------------
// Che email trên màn nhập mã xác thực. Hai lỗi phải chặn:
//
//  1. Che quá tay ⇒ khách không nhận ra hộp thư của mình, và màn hình mất luôn
//     tác dụng duy nhất của nó ("mở đúng hộp thư nào").
//  2. Che hụt ⇒ địa chỉ đầy đủ nằm trên màn hình giữa quán.
// ---------------------------------------------------------------------------

test("giữ ký tự đầu và cuối của tên hộp thư, giữ nguyên tên miền", () => {
  assert.equal(maskEmail("vananh@gmail.com"), "v****h@gmail.com");
});

test("tên miền không bị che — đó là chỉ dẫn, không phải danh tính", () => {
  assert.equal(maskEmail("taro@company.co.jp"), "t**o@company.co.jp");
});

// Tên hộp thư dài không được đẩy dòng chữ tràn ra ngoài khung.
test("số dấu sao có trần", () => {
  const masked = maskEmail("nguyenvanabcdefghijklmn@gmail.com");
  assert.equal(masked, "n********n@gmail.com");
  assert.equal((masked.match(/\*/g) ?? []).length, 8);
});

test("tên hộp thư 2 ký tự vẫn che được", () => {
  assert.equal(maskEmail("ab@gmail.com"), "a*@gmail.com");
});

test("tên hộp thư 1 ký tự", () => {
  assert.equal(maskEmail("a@gmail.com"), "a*@gmail.com");
});

test("3 ký tự — ranh giới giữa hai nhánh", () => {
  assert.equal(maskEmail("abc@gmail.com"), "a*c@gmail.com");
});

// `@` cuối cùng mới là dấu ngăn: phần tên hộp thư được phép chứa `@` khi trích dẫn.
test("dùng dấu @ cuối cùng làm ranh giới", () => {
  assert.equal(maskEmail('"a@b"@gmail.com'), '"***"@gmail.com');
});

test("chuỗi không phải email được trả nguyên trạng", () => {
  assert.equal(maskEmail("khong-phai-email"), "khong-phai-email");
  assert.equal(maskEmail("@gmail.com"), "@gmail.com");
});

test("khoảng trắng thừa bị cắt", () => {
  assert.equal(maskEmail("  vananh@gmail.com  "), "v****h@gmail.com");
});
