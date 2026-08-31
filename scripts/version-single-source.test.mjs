import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { readVersion } from "./version.mjs";

/**
 * #2660 — MỘT số cho cả cây, và nó phải ở đúng một chỗ.
 *
 * Trước rào này bốn con số cùng tồn tại: `0.1.0` (admin · customer · kds ·
 * ws-frontend), `0.0.0` (pos), tag git dạng ngày (`v2026.8.10e`), và GitHub
 * Release "Latest" là `v1.0.0.2` — bốn phần. Không con số nào trả lời được câu
 * "quán này đang chạy bản nào", và không gì đỏ khi chúng trôi khỏi nhau.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * Mọi app có `package.json` mang số phiên bản NGƯỜI DÙNG nhìn thấy.
 *
 * Bản đầu liệt kê 5 mục trong khi cây có 10 `package.json` mang `version`, và
 * test mang tên "mọi app" — nên nó XANH trong khi `app/tms`, `app/kiosk`,
 * `app/handy` giữ **1.0.0** (CAO hơn số được coi là nguồn chân lý) và
 * `app/pos` giữ 0.1.0. Một rào báo sai phạm vi tệ hơn không có rào.
 */
const VERSIONED_APPS = [
  "web/admin",
  "web/customer",
  "web/pos",
  "app/kds",
  "app/tms",
  "app/kiosk",
  "app/handy",
  "app/pos",
  "workstation/frontend",
];

/**
 * Cố ý ĐỨNG NGOÀI, và lý do phải nêu ra chứ không im lặng bỏ qua:
 *
 * - `web/packages/godx-tempo-ui` (`@godxjp/ui`) là THƯ VIỆN, không phải app.
 *   `publishConfig` + `web/{admin,pos}` phụ thuộc bằng `file:` ⇒ số của nó nói
 *   về tương thích API cho người tiêu thụ, không nói quán đang chạy bản nào.
 *   Ép nó theo nhịp phát hành của monorepo là làm hỏng ý nghĩa của semver.
 * - các gói cấu hình nội bộ dưới `packages/` (và bản sao trong từng web app):
 *   eslint-config, prettier-config, tsconfig — đều `0.0.0`, không ai cài và
 *   không ai nhìn thấy.
 *
 * Danh sách này tồn tại để lần sau ai đó đếm 10 `package.json` rồi hỏi "sao rào
 * chỉ kiểm 9" có câu trả lời, thay vì lại nghĩ là sót.
 */
const DELIBERATELY_UNVERSIONED = [
  "web/packages/godx-tempo-ui",
  "packages/eslint-config",
  "packages/prettier-config",
  "packages/tsconfig",
];

test("VERSION là semver ba phần, không tiền tố v", () => {
  const raw = readFileSync(join(root, "VERSION"), "utf8").trim();
  assert.match(raw, /^\d+\.\d+\.\d+$/);
  assert.doesNotMatch(raw, /^v/, "VERSION không mang tiền tố `v`; chỗ cần thì tự thêm");
});

test("mọi app khai ĐÚNG số trong VERSION", () => {
  const expected = readVersion();

  for (const app of VERSIONED_APPS) {
    const pkg = JSON.parse(readFileSync(join(root, app, "package.json"), "utf8"));
    assert.equal(
      pkg.version,
      expected,
      `${app}/package.json khai ${pkg.version}, VERSION là ${expected}. ` +
        "Đổi phiên bản thì sửa FILE `VERSION` rồi đồng bộ, đừng sửa lẻ một app.",
    );
  }
});

/**
 * `package-lock.json` là NHÁNH THỨ HAI của "nguồn duy nhất", và nó đã trôi.
 *
 * Đo 2026-08-18: `VERSION` = 0.8.31, mọi `package.json` = 0.8.31, còn lockfile
 * ghi **0.8.25** — lệch SÁU bản. Nó trôi vì mọi lượt bump từ trước tới nay chỉ
 * sửa `VERSION` + `package.json`, và bài test ngay trên chỉ quét `VERSIONED_APPS`.
 * Không gì đỏ suốt cả quãng đó.
 *
 * Vì sao đáng canh dù nó "chỉ là metadata": `npm install` ĐỒNG BỘ trường này từ
 * `package.json` — nên bất cứ ai chạy install cũng tạo ra một diff lockfile mà
 * họ không cố ý, giữa lượt làm việc của mình. Đúng chuyện vừa xảy ra: một lượt
 * `npm install` chạy nhầm cây làm bẩn working tree của người khác bằng hai dòng
 * mà không ai hiểu từ đâu ra.
 *
 * Rào này canh HAI khoá — `version` gốc và `packages[""].version` — vì npm ghi
 * cả hai và sửa lẻ một cái là trạng thái nửa vời không có gì bắt.
 */
test("package-lock.json khai ĐÚNG số trong VERSION", () => {
  const expected = readVersion();
  const lock = JSON.parse(readFileSync(join(root, "package-lock.json"), "utf8"));

  assert.equal(
    lock.version,
    expected,
    `package-lock.json khai ${lock.version}, VERSION là ${expected}. ` +
      "Chạy `npm install` để npm tự đồng bộ, đừng sửa tay từng khoá.",
  );

  assert.equal(
    lock.packages?.[""]?.version,
    expected,
    `package-lock.json packages[""] khai ${lock.packages?.[""]?.version}, VERSION là ${expected}. ` +
      "npm ghi CẢ HAI khoá; sửa lẻ một cái để lại trạng thái nửa vời.",
  );
});

/**
 * Makefile và Taskfile phải đọc CÙNG file, không quay lại dò git tag.
 *
 * Đây là chỗ đã hỏng: cả hai từng dò semver tag mới nhất — đúng về ý, nhưng phụ
 * thuộc việc có người nhớ đẩy tag. Tag thật lại toàn dạng ngày nên không khớp,
 * và số bị đóng vào binary tụt về `dev` trong khi trang download đứng im.
 */
test("đường build của workstation đọc VERSION, không dò tag", () => {
  for (const file of ["workstation/Makefile", "workstation/Taskfile.yml"]) {
    const body = readFileSync(join(root, file), "utf8");

    assert.match(
      body,
      /VERSION/,
      `${file} phải tham chiếu file VERSION`,
    );
    assert.doesNotMatch(
      body,
      /git tag -l/,
      `${file} còn dò git tag — đó là cách đường phát hành đứng im 3 ngày (#2660). ` +
        "Đọc từ file VERSION.",
    );
  }
});

/**
 * Workflow phát hành phải kích được bằng chính đường mà repo dùng.
 *
 * Nó từng chỉ kích trên `v[0-9]+.[0-9]+.[0-9]+`, trong khi tag thật là
 * `v2026.8.10e` — nên **không tag nào kích được gì**, im lặng: không đỏ, không
 * xanh, workflow đơn giản không chạy.
 */
test("workflow phát hành kích trên push main, không chỉ trên tag semver", () => {
  const wf = readFileSync(
    join(root, ".github/workflows/workstation-release.yml"),
    "utf8",
  );
  const head = wf.slice(0, wf.indexOf("\njobs:"));

  assert.match(
    head,
    /branches:\s*\[\s*main\s*\]/,
    "workflow phải kích khi `main` đổi — không phụ thuộc việc ai đó nhớ đẩy tag",
  );
});

/**
 * #2660 review — mọi mục CỐ Ý đứng ngoài phải THẬT SỰ tồn tại.
 *
 * Danh sách miễn trừ mà trỏ vào một đường dẫn đã bị xoá là danh sách đang nói
 * dối: nó khiến người đọc tin rằng phạm vi đã được cân nhắc, trong khi thực ra
 * nó chỉ mục dần. Cùng họ với `TestForwardCompatExceptionListOnlyShrinks`.
 */
test("mọi mục trong DELIBERATELY_UNVERSIONED còn tồn tại thật", () => {
  for (const p of DELIBERATELY_UNVERSIONED) {
    const pkg = join(root, p, "package.json");
    assert.ok(
      existsSync(pkg),
      `${p} nằm trong danh sách miễn trừ nhưng không có package.json — gỡ entry, đừng để nó mục`,
    );
  }
});

/**
 * Bộ lọc tag ngày — chạy CHÍNH `semver-release-tags.sh`, không mô phỏng lại
 * regex trong JS (mô phỏng thì test đo bản sao, không đo thứ workflow gọi).
 *
 * Hai chiều:
 *   phải LOẠI  — `v2026.8.5` khớp cú pháp semver nhưng là tag ngày
 *   phải GIỮ   — `v0.4.0` là semver thật
 */
test("semver_tags loại tag ngày và giữ semver thật", () => {
  const script = join(root, ".github/scripts/semver-release-tags.sh");
  const probe = [
    `source ${JSON.stringify(script)}`,
    // Ghi đè `git tag -l` bằng một danh sách cố định — test không phụ thuộc
    // vào tag thật của repo (chúng đổi, và test sẽ mục theo).
    'git() { if [ "$1" = "tag" ]; then printf "%s\\n" v0.0.1 v0.4.0 v2026.7.21 v2026.8.5 v2026.8.10a; else command git "$@"; fi; }',
    "semver_tags",
  ].join("\n");

  const out = execFileSync("bash", ["-c", probe], { encoding: "utf8" })
    .trim()
    .split("\n")
    .filter(Boolean);

  assert.deepEqual(out, ["v0.0.1", "v0.4.0"], "tag ngày phải bị loại khỏi semver_tags");
});

test("assert_semver_tag từ chối tag ngày, chấp nhận semver thật", () => {
  const script = join(root, ".github/scripts/semver-release-tags.sh");
  const run = (tag) => {
    try {
      execFileSync("bash", ["-c", `source ${JSON.stringify(script)}; assert_semver_tag ${tag}`], {
        stdio: ["ignore", "ignore", "ignore"],
      });
      return true;
    } catch {
      return false;
    }
  };

  assert.equal(run("v0.5.0"), true, "semver thật phải được chấp nhận");
  assert.equal(run("v2026.8.5"), false, "tag ngày KHÔNG hậu tố chữ vẫn phải bị từ chối");
  assert.equal(run("v2026.8.10a"), false, "tag ngày có hậu tố chữ phải bị từ chối");
});

/**
 * #2660 — `setup-go` KHÔNG được tự cache trên runner tự host.
 *
 * Runner là máy BỀN: `$GOMODCACHE` sống qua các lượt chạy. Bật cache làm mỗi job
 * tải + giải nén một tarball 8,5 GB đè lên chính thư mục đó. Runner lại có 2 slot
 * song song, nên ngày 2026-08-10 hai job cùng giải nén CÙNG tarball vào CÙNG
 * đường dẫn: `tar: Cannot open: File exists` ⇒ `Build (darwin-arm64)` đỏ ⇒
 * `Publish` skip ⇒ trang download đứng im 3 ngày ở một bản cũ.
 *
 * Ba job sau chạy lần lượt khi slot trống nên xanh — đó là lý do sự cố trông như
 * "một chân matrix hỏng" chứ không như một cuộc đua, và là lý do rào này tồn tại:
 * lần tới nó sẽ lại trông giống hệt vậy.
 */
test("setup-go trong workflow phát hành KHONG bat cache", () => {
  const wf = readFileSync(
    join(root, ".github/workflows/workstation-release.yml"),
    "utf8",
  );

  const steps = wf.split("- uses: actions/setup-go").slice(1);
  assert.ok(steps.length > 0, "khong thay buoc setup-go nao - workflow da doi, doc lai rao nay");

  steps.forEach((step, i) => {
    const body = step.split("\n      - ")[0];
    assert.match(
      body,
      /cache:\s*false/,
      `setup-go #${i + 1} thieu "cache: false". Runner tu host giu san ` +
        "$GOMODCACHE; bat cache la tai lai 8,5 GB moi job VA cho hai job song " +
        "song giai nen de nhau (#2660).",
    );
  });
});
