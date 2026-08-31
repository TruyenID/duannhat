import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * #2699 — không app nào được tải font từ mạng ngoài LÚC BUILD.
 *
 * `next/font/google` nghe như một API biên dịch, nhưng nó **gọi ra
 * fonts.googleapis.com trong lúc build**. Ngày 2026-08-13 container build của
 * Amplify không với tới Google một lần, và `Deploy customer-web to Amplify`
 * chết ở commit `17e06536c`:
 *
 *     > src: url(@vercel/turbopack-next/internal/font/google/font?…https://fonts.gs…
 *     https://nextjs.org/docs/messages/module-not-found
 *     !!! Build failed
 *
 * Chạy lại thì xanh — và chính chỗ đó là vấn đề. Nó hỏng theo mạng của runner
 * chứ không theo mã, nên người merge sẽ đi tìm lỗi trong diff của mình mà diff
 * không sai; nó hỏng vào lúc không ai chọn, vì deploy chạy khi có người merge;
 * và chạy lại là thao tác TAY, không có gì tự thử lại.
 *
 * Hai app cùng dính (customer-web và admin-web), nên rào quét cả cây chứ không
 * ghim đường dẫn.
 */

/**
 * #3190 — phạm vi quét trước đây là `web/ app/`, tức `workstation/frontend`
 * nằm NGOÀI rào font. Cùng chỗ mù mà #3133 đã vá ở nơi khác: rào chỉ nhìn được
 * thứ nó được trỏ vào, và một thư mục bỏ quên thì không bao giờ đỏ — nó im.
 *
 * `workstation/frontend` là một app React thật, có `package.json`, có
 * `app.css`, và ĐANG SẠCH (đo 2026-08-18: 0 khớp `next/font/google` toàn cây).
 * Nó không phải Next.js hôm nay, nhưng phạm vi của rào không nên phụ thuộc vào
 * việc hôm nay ai đang dùng framework nào — đó chính là giả định làm rào hết
 * canh mà không ai thấy.
 */
const SCANNED = ["web/", "app/", "workstation/", "packages/"];

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * Chỉ bắt LỜI GỌI IMPORT, không bắt chỗ nhắc tên.
 *
 * Chú thích trong `fonts.css` và các file theme cố ý viết lại cụm
 * `next/font/google` để nói rằng chế độ hỏng đó không còn — đó là removal
 * record, và một rào bắt cả chúng sẽ báo oan chính tài liệu giải thích nó.
 * Cùng bài học với bộ dò của #2679.
 */
const IMPORT_PATTERNS = [
  String.raw`from ['"]next/font/google['"]`,
  String.raw`require\(['"]next/font/google['"]\)`,
  String.raw`import\(['"]next/font/google['"]\)`,
];

function grep(pattern) {
  try {
    return execFileSync(
      "git",
      ["grep", "-nE", pattern, "--", ...SCANNED],
      { cwd: root, encoding: "utf8" },
    )
      .trim()
      .split("\n")
      .filter(Boolean);
  } catch {
    return []; // git grep thoát 1 khi không khớp gì
  }
}

test("không app nào import next/font/google", () => {
  const hits = IMPORT_PATTERNS.flatMap(grep);

  assert.deepEqual(
    hits,
    [],
    "Các chỗ sau tải font từ Google lúc build:\n" +
      hits.map((h) => `  ${h}`).join("\n") +
      "\nDùng `@fontsource/*` (gói npm mang sẵn .woff2) như `web/*/app/fonts.css`. " +
      "Build phải tất định: cùng commit ⇒ cùng kết quả, không phụ thuộc mạng " +
      "của runner (#2699).",
  );
});

/**
 * Rào phải biết KÊU chứ không chỉ biết IM.
 *
 * Nếu font tự host bị gỡ mất, test trên vẫn xanh — nó chỉ biết nói "không có
 * import xấu", không biết nói "có font tốt". Hai app này phải thật sự nạp font
 * từ đâu đó, nếu không thì chữ rơi hết về font hệ thống mà không gì đỏ.
 */
test("ba app production thật sự nạp font tự host", () => {
  // Mỗi app nạp font theo cách của nó, nên mẫu đi kèm từng app thay vì một mẫu
  // chung: hai app Next.js `@import` thẳng gói `@fontsource`, còn
  // `workstation/frontend` lấy qua lớp fonts của `@godxjp/ui` — và dòng import
  // ấy là thứ CÓ THẬT gánh việc, comment ngay trên nó ghi lại lần font bị lớp
  // token của base.css đè mất và app âm thầm render bằng font hệ thống.
  const APPS = [
    ["web/admin/src/app/fonts.css", String.raw`^@import ['"]@fontsource/`],
    ["web/customer/app/fonts.css", String.raw`^@import ['"]@fontsource/`],
    ["workstation/frontend/src/app.css", String.raw`^@import ['"]@godxjp/ui/styles/fonts['"]`],
  ];

  for (const [app, pattern] of APPS) {
    // Neo ĐẦU DÒNG trong FILE (không phải đầu dòng output của git grep —
    // `git grep -E` khớp mẫu với nội dung file, không khớp `path:line:content`).
    // Bản đầu chỉ tìm chuỗi `@fontsource/` nên comment hết import ra mà rào vẫn
    // xanh: chuỗi còn nguyên trong dòng đã comment. Phép thử ngược lộ ra cả hai.
    const hits = grep(pattern).filter((h) => h.startsWith(app));
    assert.ok(
      hits.length > 0,
      `${app} không nạp font tự host nào (mẫu: ${pattern}) — hoặc font đã dời chỗ, hoặc app ` +
        "này đang chạy bằng font hệ thống. Đọc lại rào này thay vì tin nó đang canh.",
    );
  }
});
