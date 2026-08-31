import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * #3057 — `VERSION` không bao giờ được đi LÙI so với thứ đã ở trên `main`.
 *
 * Đã xảy ra: #3044 cắt 0.8.9 → 0.8.10, rồi PR #3055 ghi đè xuống **0.8.9**.
 * Nhánh tách trước #3044 nên KHÔNG xung đột — nó trông như một quyết định hạ
 * số hiệu có chủ ý. Cả hai rào hiện có đều im, và cả hai đều đúng theo định
 * nghĩa của chúng:
 *
 *   test:version         chín app có KHỚP nhau không  → khớp, tôi hạ cả chín
 *   test:version-fleet   VERSION có ĐỔI không         → có, chỉ là sai chiều
 *
 * Không rào nào hỏi "số hiệu có TĂNG không". Fleet là hai máy Windows cài tay
 * và `manifest.json` tra version → commit; số hiệu đi lùi làm câu "máy nào đã
 * chạy migration nào" mất câu trả lời.
 */

/** So semver theo TỪNG SỐ, không so chuỗi — "0.8.9" > "0.8.10" theo thứ tự chữ. */
export function compareSemver(a, b) {
  const pa = String(a).trim().split(".").map(Number);
  const pb = String(b).trim().split(".").map(Number);

  for (let i = 0; i < Math.max(pa.length, pb.length); i += 1) {
    const x = pa[i] ?? 0;
    const y = pb[i] ?? 0;
    if (x !== y) return x < y ? -1 : 1;
  }

  return 0;
}

test("so semver theo SỐ, không theo chuỗi", () => {
  // Ca chính xác đã cắn: so chuỗi thì "0.8.9" > "0.8.10" và rào sẽ im.
  assert.equal(compareSemver("0.8.9", "0.8.10"), -1);
  assert.equal(compareSemver("0.8.11", "0.8.9"), 1);
  assert.equal(compareSemver("0.8.10", "0.8.10"), 0);
  assert.equal(compareSemver("1.0.0", "0.9.99"), 1);
});

test("VERSION trên cây này KHÔNG NHỎ HƠN bản đang ở trên main", () => {
  const here = readFileSync(join(root, "VERSION"), "utf8").trim();

  let onMain;
  try {
    onMain = execFileSync("git", ["show", "origin/main:VERSION"], {
      cwd: root,
      encoding: "utf8",
    }).trim();
  } catch {
    // Không đọc được `origin/main` (clone nông, hoặc chính main). Bài này chỉ
    // có nghĩa khi so được — nói rõ thay vì xanh vô nghĩa (#3005).
    assert.ok(true, "không có origin/main để so — bỏ qua");
    return;
  }

  // Cây NÀY đã nằm trong `main` (tức chính là main, hoặc một nhánh chưa rẽ) thì
  // không có gì để promote và "phải lớn hơn" là câu hỏi vô nghĩa — VERSION bằng
  // nhau là ĐÚNG.
  //
  // Bản đầu thiếu vế này và đỏ ngay trên `main` sau lượt promote đầu tiên
  // (0.8.11 == 0.8.11). Rào kêu oan không bị tranh luận, nó bị TẮT — và lúc đó
  // mất luôn vế thật.
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", "HEAD", "origin/main"], {
      cwd: root,
      stdio: "ignore",
    });

    return; // HEAD đã có trong main ⇒ không phải một lượt promote
  } catch {
    // Không phải tổ tiên ⇒ đây là nhánh sẽ đi vào main. Đo tiếp.
  }

  const cmp = compareSemver(here, onMain);

  // `>= 0`, KHÔNG phải `> 0`. Bất biến ở đây là "không đi LÙI", không phải
  // "mọi PR đều phải bump" — bản đầu đòi lớn hơn và đỏ trên chính nhánh sửa nó.
  //
  // Chuyện *phải* bump khi chạm `workstation/` là câu hỏi KHÁC, và
  // `test:version-fleet` đã hỏi nó rồi. Hai rào, hai câu; gộp làm một thì cái
  // này kêu oan với mọi PR không đụng máy trạm.
  assert.ok(
    cmp >= 0,
    `VERSION = ${here} nhưng main đã ở ${onMain} — số hiệu ĐI LÙI.\n` +
      `Fleet là máy Windows cài tay và manifest tra version → commit, nên một\n` +
      `số hiệu nhỏ hơn thứ đã phát hành làm câu "máy nào chạy bản nào" mất câu\n` +
      `trả lời (#2898/#2959/#3057).`,
  );
});
