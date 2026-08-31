import assert from "node:assert/strict";
import { describe, it } from "node:test";

import en from "./en.json" with { type: "json" };
import ja from "./ja.json" with { type: "json" };
import vi from "./vi.json" with { type: "json" };

/**
 * #1783 — copy của luồng quên mật khẩu KHÔNG được nói địa chỉ có tài khoản hay
 * không.
 *
 * `POST /customer/auth/password/forgot` là endpoint CÔNG KHAI và cố ý trả cùng
 * một 200 cho mọi địa chỉ, chính vì phân biệt được hai trường hợp là biến form
 * này thành máy liệt kê khách hàng của một quán. `lib/password-reset.test.ts`
 * canh phần logic (FE không đọc body, không in message của server ra).
 *
 * Nhưng tính chất đó nằm một nửa ở LỜI, và lời thì bị phá bởi loại thay đổi
 * trông vô hại nhất — ai đó thấy câu "nếu địa chỉ này có tài khoản..." lòng
 * vòng, sửa thành "đã gửi link tới địa chỉ của bạn", và không có test nào đỏ.
 * Câu sau khẳng định tài khoản tồn tại; hai câu đọc gần giống nhau nhưng chỉ
 * một câu giữ được tính chất.
 *
 * Nên test này kiểm hai chiều: câu thông báo PHẢI mang điều kiện ("nếu" / "場合"
 * / "if"), và KHÔNG câu nào trong luồng được khẳng định/phủ định sự tồn tại.
 */
const locales = { ja, en, vi } as const;

/** Dấu hiệu câu nói có điều kiện — thứ duy nhất giữ được câu ở thế trung tính. */
const HEDGE: Record<keyof typeof locales, RegExp> = {
  vi: /\bNếu\b/i,
  ja: /場合/,
  en: /\bif\b/i,
};

/**
 * Cách nói làm lộ trạng thái tài khoản, theo cả hai hướng.
 *
 * Cố ý KHÔNG bắt cụm "có tài khoản" / "has an account" / "アカウントがある" —
 * chúng nằm ngay trong câu điều kiện đúng đắn. Thứ bị cấm là câu KHẲNG ĐỊNH
 * (đã gửi tới hộp thư CỦA BẠN) và câu PHỦ ĐỊNH (địa chỉ chưa đăng ký).
 */
const LEAKY: Array<{ pattern: RegExp; why: string }> = [
  { pattern: /không tồn tại|chưa đăng ký|không tìm thấy tài khoản|chưa có tài khoản/i, why: "phủ định sự tồn tại (vi)" },
  { pattern: /登録されていません|存在しません|見つかりません/, why: "phủ định sự tồn tại (ja)" },
  { pattern: /not registered|no account|does ?n[o']t exist|couldn'?t find (that|an) account/i, why: "phủ định sự tồn tại (en)" },
  { pattern: /đã gửi link .{0,20}tới email của bạn|đã gửi tới hộp thư của bạn/i, why: "khẳng định sự tồn tại (vi)" },
  { pattern: /we'?ve sent (you|a link to your)/i, why: "khẳng định sự tồn tại (en)" },
  { pattern: /あなたのメールアドレスに送信しました/, why: "khẳng định sự tồn tại (ja)" },
];

function strings(value: unknown, prefix = ""): Array<[string, string]> {
  if (typeof value === "string") return [[prefix, value]];
  if (value === null || typeof value !== "object" || Array.isArray(value)) return [];
  return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
    strings(child, prefix ? `${prefix}.${key}` : key)
  );
}

describe("copy luồng quên mật khẩu (#1783)", () => {
  it("câu 'đã gửi' phải có điều kiện, ở cả ba ngôn ngữ", () => {
    const flat: string[] = [];

    for (const [locale, messages] of Object.entries(locales)) {
      const sent = (messages as { forgotPassword?: { sent?: string } }).forgotPassword?.sent;
      if (!sent) {
        flat.push(`${locale}: thiếu forgotPassword.sent`);
        continue;
      }
      if (!HEDGE[locale as keyof typeof locales].test(sent)) {
        flat.push(`${locale}: forgotPassword.sent khẳng định thay vì đặt điều kiện — "${sent}"`);
      }
    }

    assert.deepEqual(flat, [], `Câu thông báo làm lộ trạng thái tài khoản:\n  ${flat.join("\n  ")}`);
  });

  it("không chuỗi nào trong luồng nói địa chỉ có hay không có tài khoản", () => {
    // Quét cả `forgotPassword` lẫn `resetPassword`: bước đặt mật khẩu mới cũng
    // gộp "địa chỉ không có tài khoản" vào cùng một lỗi với token hỏng, nên nó
    // chịu đúng ràng buộc đó.
    const offenders: string[] = [];

    for (const [locale, messages] of Object.entries(locales)) {
      const m = messages as Record<string, unknown>;
      for (const namespace of ["forgotPassword", "resetPassword"]) {
        for (const [key, text] of strings(m[namespace], namespace)) {
          for (const { pattern, why } of LEAKY) {
            if (pattern.test(text)) {
              offenders.push(`${locale}: ${key} — ${why}: "${text}"`);
            }
          }
        }
      }
    }

    assert.deepEqual(offenders, [], `Copy làm lộ trạng thái tài khoản:\n  ${offenders.join("\n  ")}`);
  });

  it("lỗi link hỏng phải nói việc CẦN LÀM TIẾP, không chỉ nói là hỏng", () => {
    // Token dùng một lần: gõ lại, tải lại trang, bấm lại link cũ — không cái nào
    // cứu được. Một câu chỉ nói "link không hợp lệ" để khách thử đúng những thứ
    // đó cho tới lúc bỏ cuộc.
    const wayOut: Record<keyof typeof locales, RegExp> = {
      vi: /link mới/i,
      ja: /新しいリンク/,
      en: /new (one|link)/i,
    };

    const dead: string[] = [];

    for (const [locale, messages] of Object.entries(locales)) {
      const text = (messages as { resetPassword?: { linkUnusable?: string } }).resetPassword
        ?.linkUnusable;
      if (!text) {
        dead.push(`${locale}: thiếu resetPassword.linkUnusable`);
        continue;
      }
      if (!wayOut[locale as keyof typeof locales].test(text)) {
        dead.push(`${locale}: linkUnusable không chỉ đường xin link mới — "${text}"`);
      }
    }

    assert.deepEqual(dead, [], `Lỗi không có lối ra:\n  ${dead.join("\n  ")}`);
  });
});
