/**
 * #2824 — chạy CHÍNH `publish-workstation-downloads.sh`, không chỉ python bên trong.
 *
 * Lượt phát hành v0.8.1 đỏ vì bản refactor gọi bộ gộp manifest bằng
 * `$(dirname "$0")`, mà script đã `cd "$DIST_DIR"` trước đó — đường dẫn tương
 * đối phân giải thành `dist/.github/scripts/…` và không tồn tại.
 *
 * Bộ test của #2814 gọi thẳng python nên xanh, và logic gộp KHÔNG sai một dòng
 * nào. Cái sai nằm ở chỗ NỐI hai mảnh, và chỗ nối chỉ tồn tại trong shell. Bài
 * này đi qua đúng chỗ đó.
 *
 * `PROD_MANIFEST_URL` trỏ `file://` — cùng đường `curl` mà CI dùng, nên bước
 * "nạp lịch sử từ production" được chạy thật chứ không bị vòng qua.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, writeFileSync, readFileSync, mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

// ĐƯỜNG DẪN TƯƠNG ĐỐI, chạy từ gốc repo — y hệt workflow:
//
//     .github/scripts/publish-workstation-downloads.sh \
//
// Đây KHÔNG phải chi tiết vụn. Gọi bằng đường dẫn tuyệt đối thì `$0` cũng tuyệt
// đối và `dirname "$0"` vẫn đúng sau khi `cd` — bài test sẽ XANH với đúng cái
// lỗi đã làm đỏ production. Bản đầu của chính bài này mắc lỗi đó: nó xanh cả
// khi tiêm lỗi trở lại, tức là một cái rào không canh gì.
const SCRIPT = ".github/scripts/publish-workstation-downloads.sh";
const REPO_ROOT = process.cwd();
const TARGETS = [
  "linux-amd64", "linux-arm64", "darwin-amd64", "darwin-arm64", "windows-amd64.exe",
];

/** Manifest "production" giả: một thế hệ đã có, phải sống sót qua lượt phát hành. */
const PREV = {
  latest: "v0.8.0",
  updated_at: "2026-08-13T22:46:12Z",
  versions: [{
    version: "v0.8.0",
    released_at: "2026-08-13T22:46:12Z",
    commit: "ba9077575522e5eee0ca7e7d7e5c67455a7c3e54",
    archived: false,
    platforms: [],
  }],
};

function publish(version) {
  const dir = mkdtempSync(join(tmpdir(), "wspublish-"));
  const dist = join(dir, "dist");
  const root = join(dir, "downloads");
  mkdirSync(dist, { recursive: true });
  mkdirSync(root, { recursive: true });
  for (const t of TARGETS) writeFileSync(join(dist, `ws-server-${t}`), `binary-${t}`);
  const prevPath = join(dir, "prod-manifest.json");
  writeFileSync(prevPath, JSON.stringify(PREV));

  // `cwd` = gốc repo (như CI), `dist`/`root` tuyệt đối và nằm ngoài repo (như
  // CI, nơi chúng là thư mục runner). Script tự `cd "$DIST_DIR"` bên trong —
  // đó chính là chỗ đường dẫn tương đối gãy.
  execFileSync("bash", [SCRIPT, version, "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef", dist, root], {
    cwd: REPO_ROOT,
    env: { ...process.env, PROD_MANIFEST_URL: `file://${prevPath}` },
    stdio: ["ignore", "pipe", "pipe"],
    encoding: "utf8",
  });
  return JSON.parse(readFileSync(join(root, "manifest.json"), "utf8"));
}

test("script chạy trọn vẹn khi được gọi bằng đường dẫn TƯƠNG ĐỐI — chỗ nối shell → python", () => {
  // Bài này đỏ với `$(dirname "$0")`: python báo
  // "can not open file .../dist/.github/scripts/merge-workstation-manifest.py".
  const m = publish("v0.8.1");
  assert.equal(m.latest, "v0.8.1");
});

test("thế hệ trước SỐNG SÓT qua lượt phát hành và bị đánh archived", () => {
  // Đường thật của #2814: nạp manifest production rồi gộp. Bài của #2814 kiểm
  // bộ gộp; bài này kiểm rằng shell thực sự ĐƯA được manifest đó vào bộ gộp.
  const m = publish("v0.8.1");
  assert.deepEqual(m.versions.map((v) => v.version), ["v0.8.1", "v0.8.0"]);
  assert.equal(m.versions[0].archived, false);
  assert.equal(m.versions[1].archived, true, "bản cũ phải được đánh dấu archived");
});

test("bản mới mang đủ 5 nền tảng kèm gói cài", () => {
  const m = publish("v0.8.1");
  const ids = m.versions[0].platforms.map((p) => p.id).sort();
  assert.deepEqual(ids, [...TARGETS].sort());
  for (const p of m.versions[0].platforms) {
    assert.ok(p.sha256 && p.size > 0, `${p.id} thiếu sha256/size`);
    assert.ok(p.bundle?.filename, `${p.id} thiếu gói cài`);
  }
});
