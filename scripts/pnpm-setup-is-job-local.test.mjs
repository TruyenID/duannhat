/**
 * `pnpm/action-setup` phải cài vào thư mục RIÊNG của từng job (#3032).
 *
 * ## Ca thật
 *
 * Sau khi `web-apps` chuyển sang runner tự dựng (#3001, 2026-08-16), các job
 * web bắt đầu đỏ ở **bước setup**, sau 10–20 giây, không chạy test nào:
 *
 *     Something went wrong, self-installer exits with code 1
 *     Error: ENOENT: no such file or directory, open '/home/satoshi/setup-pnpm/package.json'
 *
 * Đo được 5 job hỏng ở lượt đầu trong ~1,5 giờ.
 *
 * ## Nguyên nhân
 *
 * `dest` mặc định của action là `~/setup-pnpm` — khớp chính xác đường dẫn trong
 * annotation. Trên GitHub-hosted mỗi job là một VM mới nên vô hại. Trên
 * self-hosted thì matrix bốn app = **bốn job song song trên CÙNG một máy**, và
 * `concurrency` gom theo nhánh nên PR khác nhau còn chồng lên nữa. Bốn job cùng
 * ghi/đọc một thư mục: job A dọn trong khi job B đang đọc.
 *
 * `runner.temp` riêng theo từng job, nên nó cắt đứt tranh chấp.
 *
 * ## Vì sao cần RÀO chứ không chỉ sửa
 *
 * Hỏng này **đọc y hệt một lượt test đỏ** — không log test, và sự thật chỉ nằm
 * trong annotation. Cái giá thật không phải 20 giây chạy lại, mà là nó dạy
 * người ta bấm rerun khi thấy đỏ; ngày cổng đỏ vì lý do THẬT, phản xạ đó sẽ
 * nuốt mất nó. Thêm một workflow mới mà quên `dest` là dựng lại y nguyên.
 *
 * ⚠️ Đo lượt hỏng KHÔNG được dùng `gh run list`: rerun ghi đè kết luận ở mức
 * *run*, nên bảng đó chỉ hiện 1 trong 5. Phải hỏi mức job, kèm `attempts/<n>`.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const workflowDir = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  ".github/workflows",
);

/** Mọi lần dùng `pnpm/action-setup`, kèm khối `with:` ngay sau nó. */
function actionSetupUses(text) {
  const lines = text.split("\n");
  const uses = [];

  for (let i = 0; i < lines.length; i++) {
    if (!lines[i].includes("pnpm/action-setup@")) continue;

    // Gom mọi dòng cho tới bước kế tiếp — `dest` có thể nằm bất cứ đâu trong
    // khối `with:`, và ghim THỨ TỰ khoá sẽ đứt ngay lượt sửa kế tiếp.
    const indent = lines[i].length - lines[i].trimStart().length;
    const block = [];
    for (let j = i + 1; j < lines.length; j++) {
      const line = lines[j];
      const bare = line.trim();
      if (bare === "") continue;
      const at = line.length - line.trimStart().length;
      if (at <= indent && (bare.startsWith("- ") || bare.startsWith("uses:") || bare.startsWith("name:"))) break;
      block.push(line);
    }
    uses.push({ line: i + 1, block: block.join("\n") });
  }

  return uses;
}

const files = readdirSync(workflowDir).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"));

test("#3032 mọi `pnpm/action-setup` cài vào thư mục riêng của job", () => {
  const offenders = [];

  for (const file of files) {
    const text = readFileSync(join(workflowDir, file), "utf8");

    for (const use of actionSetupUses(text)) {
      if (!/dest:\s*\$\{\{\s*runner\.temp\s*\}\}/.test(use.block)) {
        offenders.push(`${file}:${use.line}`);
      }
    }
  }

  assert.deepEqual(
    offenders,
    [],
    "Thiếu `dest: ${{ runner.temp }}/pnpm`:\n  " +
      offenders.join("\n  ") +
      "\n\nMặc định là `~/setup-pnpm` — DÙNG CHUNG cho mọi job trên runner tự " +
      "dựng. Bốn job matrix chạy song song sẽ tranh nhau nó và chết ở bước " +
      "setup với `ENOENT` hoặc `self-installer exits with code 1` (#3032).",
  );
});

test("#3032 rào này có thứ để canh — không phải rào rỗng", () => {
  // Một rào duyệt qua 0 phần tử thì luôn xanh, và nó đọc như đã phủ. Đây là vế
  // thứ hai: nếu ai đó gỡ hết `pnpm/action-setup`, rào trên mất nghĩa và test
  // này bắt phải xem lại chứ không im lặng cho qua.
  const total = files.reduce(
    (n, f) => n + actionSetupUses(readFileSync(join(workflowDir, f), "utf8")).length,
    0,
  );

  assert.ok(
    total > 0,
    "không workflow nào dùng `pnpm/action-setup` nữa — rào #3032 đang duyệt tập RỖNG, xem lại nó còn cần không",
  );
});
