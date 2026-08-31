/**
 * #2819 — khôi phục thế hệ đã mất khỏi manifest workstation.
 *
 * Bốn thứ được ghim, và ba trong số đó là các nhánh TỪ CHỐI. Đó là chủ ý: một
 * script khôi phục mà chỉ được kiểm ở đường thành công sẽ vui vẻ công bố một
 * bản thiếu nền tảng hoặc không rõ commit, và người cài phát hiện ra bằng một
 * liên kết 404 trên máy quán.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, writeFileSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const SCRIPT = ".github/scripts/restore-workstation-manifest-history.py";
const PLATFORMS = [
  ["linux-amd64", "Tempo-Workstation-linux-amd64.tar.gz"],
  ["linux-arm64", "Tempo-Workstation-linux-arm64.tar.gz"],
  ["darwin-amd64", "Tempo-Workstation-darwin-amd64.tar.gz"],
  ["darwin-arm64", "Tempo-Workstation-darwin-arm64.tar.gz"],
  ["windows-amd64.exe", "Tempo-Workstation-windows-amd64.zip"],
];

/** TSV như bước ssh sinh ra. `omit` để dựng một bản KHUYẾT có chủ đích. */
function inventory(version, { omit = null } = {}) {
  const rows = [];
  for (const [id, bundle] of PLATFORMS) {
    if (id === omit) continue;
    rows.push(`${version}\tws-server-${id}\t111\tsha-${id}`);
    rows.push(`${version}\t${bundle}\t222\tsha-${bundle}`);
  }
  return rows.join("\n") + "\n";
}

function run({ manifest, inv, meta }) {
  const dir = mkdtempSync(join(tmpdir(), "wsrestore-"));
  const p = (n) => join(dir, n);
  writeFileSync(p("manifest.json"), JSON.stringify(manifest));
  writeFileSync(p("inv.tsv"), inv);
  writeFileSync(p("meta.json"), JSON.stringify(meta));
  const stdout = execFileSync("python3", [
    SCRIPT, "--manifest", p("manifest.json"), "--inventory", p("inv.tsv"),
    "--meta", p("meta.json"), "--out", p("out.json"),
  ], { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
  return { out: JSON.parse(readFileSync(p("out.json"), "utf8")), stdout };
}

const CURRENT = {
  latest: "v0.8.0",
  updated_at: "2026-08-13T22:46:12Z",
  versions: [{ version: "v0.8.0", commit: "ba90775", archived: false, platforms: [] }],
};

test("khôi phục bản đã mất, giữ nguyên latest và entry đang có", () => {
  const { out } = run({
    manifest: CURRENT,
    inv: inventory("v0.7.0"),
    meta: { "v0.7.0": { commit: "654ba1f9b", released_at: "2026-08-13T21:40:00Z" } },
  });
  assert.equal(out.latest, "v0.8.0", "latest KHÔNG được đổi");
  assert.deepEqual(out.versions.map((v) => v.version), ["v0.8.0", "v0.7.0"]);

  const restored = out.versions[1];
  assert.equal(restored.commit, "654ba1f9b");
  assert.equal(restored.archived, true, "bản khôi phục không bao giờ là bản hiện hành");
  assert.equal(restored.restored, true, "phải phân biệt được với entry do lượt phát hành ghi ra");
  assert.equal(restored.platforms.length, 5);

  // Hash/size đến TỪ ĐĨA, không được bịa: entry mô tả sai file đang nằm đó còn
  // tệ hơn không có entry, vì SHA256SUMS là thứ người cài đối chiếu.
  const win = restored.platforms.find((p) => p.id === "windows-amd64.exe");
  assert.equal(win.sha256, "sha-windows-amd64.exe");
  assert.equal(win.size, 111);
  assert.equal(win.bundle.filename, "Tempo-Workstation-windows-amd64.zip");
  assert.equal(win.bundle.sha256, "sha-Tempo-Workstation-windows-amd64.zip");
});

test("bản THIẾU một nền tảng bị BỎ QUA, không công bố nửa vời", () => {
  const { out } = run({
    manifest: CURRENT,
    inv: inventory("v0.7.0", { omit: "darwin-arm64" }),
    meta: { "v0.7.0": { commit: "654ba1f9b", released_at: "x" } },
  });
  assert.deepEqual(out.versions.map((v) => v.version), ["v0.8.0"]);
});

test("KHÔNG biết commit ⇒ BỎ QUA — đó chính là câu hỏi manifest sinh ra để trả lời", () => {
  const { out } = run({
    manifest: CURRENT,
    inv: inventory("v0.7.0"),
    meta: {},
  });
  assert.deepEqual(out.versions.map((v) => v.version), ["v0.8.0"]);
});

test("sắp theo SỐ, không theo chuỗi — v0.10.0 phải đứng trên v0.9.0", () => {
  // Ghim mẫu số cho bài đầu: không có bài này thì thứ tự đúng ở trên có thể chỉ
  // là may mắn của so sánh chuỗi, và nó sẽ sai đúng lúc số minor lên hai chữ số.
  const { out } = run({
    manifest: { latest: "v0.10.0", updated_at: "x", versions: [] },
    inv: inventory("v0.9.0") + inventory("v0.10.0"),
    meta: {
      "v0.9.0": { commit: "aaaaaaaaa", released_at: "x" },
      "v0.10.0": { commit: "bbbbbbbbb", released_at: "y" },
    },
  });
  assert.deepEqual(out.versions.map((v) => v.version), ["v0.10.0", "v0.9.0"]);
});
