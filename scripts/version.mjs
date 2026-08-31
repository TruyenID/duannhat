#!/usr/bin/env node
/**
 * Nguồn chân lý DUY NHẤT cho số phiên bản của cả cây (#2660).
 *
 * ## Vì sao cần
 *
 * Trước file này, mỗi chỗ khai một số khác nhau: `web/admin` · `web/customer` ·
 * `app/kds` · `workstation/frontend` = `0.1.0`, `web/pos` = `0.0.0`, tag git thì
 * dạng ngày (`v2026.8.10e`), GitHub Release "Latest" là `v1.0.0.2` (bốn phần),
 * còn binary Go nhận số qua `-ldflags` lúc build. Không con số nào trả lời được
 * câu "quán này đang chạy bản nào".
 *
 * Chủ dự án chốt 2026-08-13: **mọi thứ về một số**, bắt đầu từ `0.5.0`.
 *
 * ## Vì sao là FILE, không phải git tag
 *
 * Tag chỉ tồn tại sau khi có người nhớ đẩy nó. Đường phát hành đã đứng im 3 ngày
 * đúng vì điều đó (#2660). Một file trong cây thì mọi lượt build — CI, máy cá
 * nhân, runner — đều đọc được cùng một giá trị mà không phụ thuộc trí nhớ ai.
 */
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

export const VERSION_FILE = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "VERSION",
);

/** `0.5.0` — không có tiền tố `v`. Chỗ cần `v0.5.0` tự thêm. */
export function readVersion() {
  const raw = readFileSync(VERSION_FILE, "utf8").trim();
  if (!/^\d+\.\d+\.\d+$/.test(raw)) {
    throw new Error(
      `VERSION phải là semver ba phần (ví dụ 0.5.0), đang là: ${JSON.stringify(raw)}`,
    );
  }
  return raw;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  process.stdout.write(readVersion() + "\n");
}
