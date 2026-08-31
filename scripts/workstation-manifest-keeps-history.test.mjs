import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * #2814 — phát hành KHÔNG được xoá lịch sử phiên bản.
 *
 * Đo trên production sau khi phát hành v0.7.0 (`654ba1f9b`): `versions[]` chỉ
 * còn MỘT mục; entry `v0.6.0` biến mất.
 *
 * Nguyên nhân: `publish-workstation-downloads.sh` đọc manifest cũ từ CHECKOUT
 * của CI, mà bản trong repo là `{"latest": null, "versions": []}`. Nó khởi tạo
 * từ rỗng rồi rsync ĐÈ lên production. Đoạn giữ một thế hệ cũ viết đúng nhưng
 * không bao giờ chạy được — nó đọc lịch sử từ nơi không có lịch sử.
 *
 * Vì sao đáng ghim: fleet là hai máy Windows CÀI TAY, không auto-update.
 * Manifest là thứ duy nhất trả lời "quán đang chạy bản nào" và "muốn lùi thì
 * lùi về đâu". Không có nó, một sự cố báo ở "bản trước" không tra được vào đâu.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const MERGE = join(root, ".github/scripts/merge-workstation-manifest.py");
const PUBLISH = join(root, ".github/scripts/publish-workstation-downloads.sh");

function runMerge(manifestPath, latest, prevLatest) {
  execFileSync(
    "python3",
    [
      MERGE,
      manifestPath,
      latest,
      "2026-08-14T00:00:00Z",
      JSON.stringify({ version: latest, commit: "deadbeef", platforms: [] }),
      prevLatest,
    ],
    { encoding: "utf8" },
  );
  return JSON.parse(readFileSync(manifestPath, "utf8"));
}

test("phát hành mới GIỮ thế hệ trước, đánh dấu archived", () => {
  const dir = mkdtempSync(join(tmpdir(), "wsmanifest-"));
  const p = join(dir, "manifest.json");

  // Manifest "production" đang phục vụ v0.6.0.
  writeFileSync(
    p,
    JSON.stringify({
      latest: "v0.6.0",
      versions: [{ version: "v0.6.0", commit: "d414f2bc3", platforms: [1, 2, 3, 4, 5] }],
    }),
  );

  const out = runMerge(p, "v0.7.0", "v0.6.0");
  const seen = out.versions.map((v) => v.version);

  assert.equal(out.latest, "v0.7.0");
  assert.ok(
    seen.includes("v0.6.0"),
    `thế hệ trước bị xoá khỏi manifest: ${JSON.stringify(seen)} (#2814)`,
  );
  assert.equal(
    out.versions.find((v) => v.version === "v0.6.0")?.archived,
    true,
    "thế hệ trước phải mang `archived: true`",
  );
});

/**
 * Bài ghim MẪU SỐ cho bài trên: nếu manifest vào là RỖNG thì bài kia vẫn xanh
 * một cách vô nghĩa (không có gì để mất thì không mất gì). Bài này chứng minh
 * hàm gộp thật sự đọc được lịch sử đầu vào, chứ không phải luôn ghi đè.
 */
test("manifest vào RỖNG thì ra đúng một thế hệ — không bịa thêm", () => {
  const dir = mkdtempSync(join(tmpdir(), "wsmanifest-"));
  const p = join(dir, "manifest.json");
  writeFileSync(p, JSON.stringify({ latest: null, versions: [] }));

  const out = runMerge(p, "v0.7.0", "");

  assert.deepEqual(out.versions.map((v) => v.version), ["v0.7.0"]);
});

/**
 * Chiều QUAN TRỌNG NHẤT: không lấy được manifest production thì phải DỪNG.
 *
 * Chính cú "âm thầm rơi về rỗng" là thứ đang hỏng — nếu script cứ chạy tiếp khi
 * mạng lỗi, nó sẽ xoá lịch sử đúng như trước, chỉ khác nguyên nhân.
 */
test("không tải được manifest production ⇒ script DỪNG, fail-closed", () => {
  const src = readFileSync(PUBLISH, "utf8");

  assert.match(
    src,
    /PROD_MANIFEST_URL/,
    "script phải nạp manifest từ production, không đọc bản trong checkout",
  );
  assert.match(
    src,
    /curl[^\n]*PROD_MANIFEST_URL[\s\S]{0,400}?exit 1/,
    "curl hỏng phải dẫn tới `exit 1` — rơi về rỗng là đúng lỗi #2814",
  );
  assert.match(
    src,
    /ALLOW_EMPTY_MANIFEST/,
    "phải có lối thoát TƯỜNG MINH cho lần phát hành đầu của môi trường mới",
  );
});

/**
 * Rào phải biết KÊU: nếu file gộp biến mất hoặc đổi tên, ba bài trên sẽ ném ở
 * `execFileSync` — nhưng bài này nói thẳng lý do thay vì để người đọc suy từ
 * một stack trace của python.
 */
test("script gộp tồn tại và chạy được", () => {
  const out = execFileSync("python3", ["-c", "import ast,sys; ast.parse(open(sys.argv[1]).read()); print('ok')", MERGE], {
    encoding: "utf8",
  });
  assert.match(out, /ok/);
});
