import { test } from "node:test";
import assert from "node:assert/strict";

import {
  PASSWORD_MIN_LENGTH,
  PASSWORD_RULE_KEYS,
  checkPasswordPolicy,
  passwordMeetsPolicy,
} from "./password-policy.ts";

// ---------------------------------------------------------------------------
// #1780 — bốn điều kiện ở đây là BẢN SAO của `App\Rules\StrongCustomerPassword`
// phía backend. Hai bộ luật lệch nhau thì trang đăng ký hỏng theo kiểu không ai
// đoán ra: FE nghiêm hơn ⇒ khách gõ đủ mà nút vẫn xám; BE nghiêm hơn ⇒ checklist
// xanh hết rồi submit ăn 422.
//
// Mỗi ca dưới đây trượt ĐÚNG MỘT điều kiện — nếu trượt hai thì test vẫn xanh khi
// một trong hai luật bị gỡ mất. Đối xứng với dataset cùng tên trong
// `backend/tests/Feature/Customer/Auth/RegisterTest.php`.
// ---------------------------------------------------------------------------

test("mật khẩu đạt cả bốn điều kiện", () => {
  assert.deepEqual(checkPasswordPolicy("Password123!"), {
    minLength: true,
    uppercase: true,
    lettersAndNumbers: true,
    symbol: true,
  });
  assert.equal(passwordMeetsPolicy("Password123!"), true);
});

test("mỗi mật khẩu chỉ trượt đúng một điều kiện", () => {
  const cases: Array<[string, keyof ReturnType<typeof checkPasswordPolicy>]> = [
    ["Passw1rd!", "minLength"], // 9 ký tự
    ["password123!", "uppercase"],
    ["PasswordAbc!", "lettersAndNumbers"], // không có số
    ["Password1234", "symbol"],
  ];

  for (const [password, expectedFailure] of cases) {
    const state = checkPasswordPolicy(password);
    assert.equal(state[expectedFailure], false, `${password} phải trượt ${expectedFailure}`);
    for (const rule of PASSWORD_RULE_KEYS) {
      if (rule === expectedFailure) continue;
      assert.equal(state[rule], true, `${password} không được trượt thêm ${rule}`);
    }
    assert.equal(passwordMeetsPolicy(password), false);
  }
});

test("thiếu chữ cũng trượt 'có chữ và số'", () => {
  // Cạnh còn lại của điều kiện hai vế — dataset trên chỉ phủ vế "thiếu số".
  assert.equal(checkPasswordPolicy("1234567890!").lettersAndNumbers, false);
});

test("mật khẩu 8 ký tự — hợp lệ theo luật CŨ — nay bị từ chối", () => {
  assert.equal(passwordMeetsPolicy("password123"), false);
  assert.equal(PASSWORD_MIN_LENGTH, 10);
});

test("đếm code point chứ không đếm đơn vị UTF-16", () => {
  // 6 emoji = 6 ký tự với người dùng và với `mb_strlen` của PHP, nhưng
  // `"…".length` của JS đếm 12. Đếm sai ở đây nghĩa là FE cho qua một mật khẩu
  // mà backend từ chối.
  const sixEmoji = "😀😀😀😀😀😀";
  assert.equal(sixEmoji.length, 12);
  assert.equal(checkPasswordPolicy(sixEmoji).minLength, false);

  // 10 ký tự tiếng Việt có dấu vẫn phải được tính là đủ dài.
  assert.equal(checkPasswordPolicy("Mậtkhẩu12!").minLength, true);
});

test("khoảng trắng tính là ký tự đặc biệt", () => {
  // Passphrase là mật khẩu hợp lệ; loại khoảng trắng ra khỏi "ký tự đặc biệt"
  // sẽ từ chối chúng, và backend thì không.
  assert.equal(checkPasswordPolicy("Mat khau 12").symbol, true);
  assert.equal(passwordMeetsPolicy("Mat khau 12"), true);
});

test("chữ hoa không phải ASCII vẫn tính", () => {
  // `\p{Lu}` khớp cả Đ, Ö, Ñ — regex `[A-Z]` thì không, và backend dùng `\p{Lu}`.
  assert.equal(checkPasswordPolicy("Đúngrồi12!").uppercase, true);
});
