/**
 * #3066 — miễn trừ bump số hiệu phải TỰ CHỨNG MINH nó còn đúng.
 *
 * ## Vì sao bài này tồn tại
 *
 * `version-tracks-workstation.mjs` miễn trừ `testdata/` và `*_test.go` khỏi
 * yêu cầu bump `VERSION`, với lý do "chúng không đi vào binary quán cài".
 *
 * Lý lẽ hiển nhiên cho vế `testdata/` là *"Go bỏ qua thư mục đó"*. **Sai**, và
 * tôi đã đo trực tiếp trước khi viết miễn trừ này. Go bỏ qua `testdata/` khi
 * PHÂN GIẢI PACKAGE, nhưng `go:embed` với tới được — module tối thiểu:
 *
 *     //go:embed testdata/x.txt
 *     var s string
 *
 *     go build  → rc=0
 *     go list   → EmbedFiles: ["testdata/x.txt"]
 *
 * Nên miễn trừ kia KHÔNG dựa trên bảo đảm của ngôn ngữ. Nó dựa trên một tính
 * chất **của cây workstation hiện tại**, và tính chất thì hỏng được bằng một
 * dòng `//go:embed`. Bài này là thứ duy nhất giữ nó thành sự thật.
 *
 * Không có bài này, hình dạng hỏng sẽ là: ai đó embed một file testdata, file
 * đó vào binary, sửa nó KHÔNG còn đòi bump — và fleet lại rơi về đúng ca #2898
 * (hai máy cùng số hiệu, khác bản), lần này với một rào đang đứng đó nói rằng
 * mọi thứ ổn.
 *
 * ## Vì sao hỏi `go list` chứ không grep
 *
 * `grep go:embed` chỉ thấy chỉ thị, không thấy nó nở ra thành file nào — mà
 * `all:pos-web/dist` nở ra hàng trăm file. `go list -json` trả đúng tập file
 * trình biên dịch sẽ nhét vào binary, tức nó trả lời đúng câu hỏi được hỏi.
 *
 * ## Bài này cần Go, nên nó KHÔNG chạy ở `omnify-gate`
 *
 * Nó được gọi từ `workstation-go.yml` (job đã cài Go). Thiếu `go` thì bài này
 * **ĐỎ**, không skip: một bài tự bỏ qua khi thiếu công cụ đọc y hệt một bài đã
 * chạy và xanh — đúng hình dạng đã trả giá bốn lần trong repo này.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { NOT_IN_BINARY } from "./version-tracks-workstation.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const wsDir = join(root, "workstation");

/** Mọi object JSON nối đuôi nhau mà `go list -json` phát ra. */
function parseGoListStream(text) {
  const out = [];
  let i = 0;
  while (i < text.length) {
    while (i < text.length && /\s/.test(text[i])) i++;
    if (i >= text.length) break;
    let depth = 0;
    let inStr = false;
    let esc = false;
    const start = i;
    for (; i < text.length; i++) {
      const c = text[i];
      if (inStr) {
        if (esc) esc = false;
        else if (c === "\\") esc = true;
        else if (c === '"') inStr = false;
        continue;
      }
      if (c === '"') inStr = true;
      else if (c === "{") depth++;
      else if (c === "}" && --depth === 0) {
        i++;
        break;
      }
    }
    out.push(JSON.parse(text.slice(start, i)));
  }
  return out;
}

function goPackages() {
  const raw = execFileSync("go", ["list", "-e", "-json", "./..."], {
    cwd: wsDir,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  });
  return parseGoListStream(raw);
}

test("#3066 `go` phải có mặt — thiếu công cụ là ĐỎ, không phải bỏ qua", () => {
  assert.ok(existsSync(wsDir), `không thấy ${wsDir}`);
  assert.doesNotThrow(
    () => execFileSync("go", ["version"], { stdio: "ignore" }),
    "bài này cần Go. Nó chạy ở `workstation-go.yml`; đừng nới thành skip — một " +
      "bài tự bỏ qua khi thiếu công cụ đọc y hệt một bài đã chạy và xanh.",
  );
});

test("#3066 không package nào nạp lỗi — mẫu số phải khác 0", () => {
  // `go list` trả rỗng (hoặc toàn package lỗi) thì mọi bài dưới xanh vô điều
  // kiện. Đây là vế "số 0 là một khẳng định, không phải mặc định".
  const pkgs = goPackages();
  assert.ok(pkgs.length >= 5, `chỉ nạp được ${pkgs.length} package — bố cục đổi?`);

  const broken = pkgs.filter((p) => p.Error).map((p) => `${p.ImportPath}: ${p.Error?.Err}`);
  assert.deepEqual(broken, [], `package không nạp được:\n  ${broken.join("\n  ")}`);

  const compiled = pkgs.reduce((n, p) => n + (p.GoFiles?.length ?? 0), 0);
  assert.ok(compiled >= 50, `chỉ ${compiled} file .go vào binary — phép đo hỏng?`);
});

test("#3066 KHÔNG file miễn trừ nào lọt vào binary", () => {
  const offenders = [];

  for (const pkg of goPackages()) {
    // `Dir` tuyệt đối; đổi về đường dẫn tương đối gốc repo để khớp cùng một
    // mẫu mà `version-tracks-workstation.mjs` dùng cho tên file trong git.
    const rel = pkg.Dir.startsWith(root) ? pkg.Dir.slice(root.length + 1) : pkg.Dir;

    // CHỈ hai khoá này. `TestGoFiles`/`XTestGoFiles` KHÔNG vào binary — đó
    // chính là điều đang được khẳng định, nên gộp chúng vào đây sẽ làm bài tự
    // mâu thuẫn và luôn đỏ.
    for (const key of ["GoFiles", "EmbedFiles"]) {
      for (const f of pkg[key] ?? []) {
        const path = `${rel}/${f}`;
        const hit = NOT_IN_BINARY.find(({ pattern }) => pattern.test(path));
        if (hit) offenders.push(`${key}: ${path}  (khớp ${hit.pattern})`);
      }
    }
  }

  assert.deepEqual(
    offenders,
    [],
    "File được MIỄN TRỪ khỏi yêu cầu bump VERSION lại đang đi vào binary:\n  " +
      offenders.join("\n  ") +
      "\n\nNghĩa là sửa nó sẽ đổi thứ quán chạy mà KHÔNG đòi số hiệu mới — rơi\n" +
      "thẳng về ca #2898 (hai máy cùng số hiệu, khác bản), lần này với một rào\n" +
      "đang đứng đó nói rằng mọi thứ ổn.\n\n" +
      "Sửa: bỏ `//go:embed` trỏ vào file đó, HOẶC gỡ mẫu tương ứng khỏi\n" +
      "`NOT_IN_BINARY` và chấp nhận bump khi nó đổi.",
  );
});

test("#3066 bài trên có thứ để đối chiếu — mẫu miễn trừ không rỗng", () => {
  // Xoá sạch `NOT_IN_BINARY` thì bài trên duyệt qua 0 mẫu và xanh mãi mãi,
  // trong khi miễn trừ cũng biến mất khỏi rào bump. Cả hai đều im lặng.
  assert.ok(NOT_IN_BINARY.length > 0, "NOT_IN_BINARY rỗng — bài trên không canh gì");
});
