/**
 * Ký tự điều khiển lọt vào mã nguồn — bắt được ở #1806 S3, sau khi nó qua hết
 * mọi cổng.
 *
 * Một byte `NUL` nằm giữa một template literal:
 *
 * ```ts
 * const key = `${row.kind}<NUL>${row.subject}`;   // dấu cách "trông như" dấu cách
 * ```
 *
 * `tsc --noEmit`: 0 lỗi. `eslint`: 0 lỗi. 189 test: xanh — vì nó VẪN CHẠY
 * ĐÚNG, `NUL` là một dấu phân cách hợp lệ. Thứ duy nhất phản ứng là git: nó
 * phân loại file thành **binary**, và từ đó `git diff` không hiện nội dung nữa.
 * Tức là file đó **không review được** — và không ai được cảnh báo, vì mọi cổng
 * đều xanh.
 *
 * Đó là lý do rào này nằm ở tầng byte chứ không tầng cú pháp: cú pháp không
 * thấy gì sai. Cùng lưới bắt luôn ESC, backspace, hay một dòng bị dán nhầm từ
 * terminal.
 *
 * `\t` (0x09), `\n` (0x0A), `\r` (0x0D) hợp lệ. File nhị phân thật (ảnh, font,
 * favicon) loại theo đuôi.
 *
 * @vitest-environment node
 */

import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const SRC = join(__dirname, "..");

/** Đuôi file nhị phân — nội dung của chúng ĐƯƠNG NHIÊN có byte điều khiển. */
const BINARY = /\.(ico|png|jpe?g|gif|webp|avif|woff2?|ttf|eot|otf|pdf|mp4|webm)$/i;

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules") continue;
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (!BINARY.test(p)) out.push(p);
  }
  return out;
}

describe("source files carry no stray control bytes", () => {
  it("every text file under src/ is free of C0 control bytes", () => {
    const offenders: string[] = [];

    for (const file of walk(SRC)) {
      const bytes = readFileSync(file);
      for (let i = 0; i < bytes.length; i += 1) {
        const b = bytes[i];
        if (b < 0x09 || (b > 0x0d && b < 0x20)) {
          // Báo kèm offset: một byte vô hình thì "file X có lỗi" là chưa đủ để
          // đi tìm.
          offenders.push(`${file}: 0x${b.toString(16).padStart(2, "0")} at byte ${i}`);
          break;
        }
      }
    }

    expect(offenders).toEqual([]);
  });
});
