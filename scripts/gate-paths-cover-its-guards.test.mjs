import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { parse } from "yaml";

import { covers } from "./lib/workflow-paths.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const GATE = ".github/workflows/omnify-gate.yml";

/**
 * Script `test:*` CỐ Ý không chạy trên CI, kèm lý do đo được.
 *
 * Rỗng có chủ ý: hiện không mục nào đủ lý do. Thêm vào đây là một quyết định,
 * không phải một đường tắt để bài trên hết đỏ.
 */
const DELIBERATELY_LOCAL = [];

/**
 * #2971 — rào cho TẦM VỚI của rào.
 *
 * Ba lần trong 24 giờ, cùng một hình dạng: một rào ĐÚNG nằm ở chỗ nó KHÔNG
 * chạy trong đúng tình huống nó sinh ra để canh.
 *
 *   #2874  phép kiểm review-lease chỉ ở `merge-batch` — đường ít bị dùng nhất;
 *          cả ba sự cố đều đi `gh pr merge`.
 *   #2959  rào version nằm sau `paths` KHÔNG chứa `workstation/**`; 23 file lên
 *          production, CI xanh.
 *   #2971  `workflows-parse` nằm sau `paths` chỉ liệt kê HAI file workflow;
 *          `deploy-xserver.yml` — thứ ghi vào DB production — sửa được mà không
 *          rào nào chạy.
 *
 * Cả ba đều xanh, cả ba đều vô dụng, và cả ba đọc log CI **y hệt** một rào đúng
 * và có chạy. Câu hỏi phân biệt được chúng không phải *"rào có đúng không"* mà
 * **"lượt chạy này CÓ chạm vào rào không"**.
 *
 * Bài này đặt đúng câu hỏi thứ hai, một lần cho tất cả: mỗi guard trong
 * `omnify-gate` khai nó canh thư mục/file nào, và `paths:` phải cho nó nhìn
 * thấy đúng những thứ đó.
 *
 * Ba lần vấp cùng một chỗ là đủ để nói rằng nhớ bằng tay không đủ.
 */

/**
 * guard => những đường dẫn nó phát biểu VỀ.
 *
 * Đây không phải danh sách "cho phép" mà là **lời khai**: thêm một guard vào
 * job thì khai nó canh gì, và bài này bảo đảm `paths:` với tới được.
 */
const GUARD_SUBJECTS = {
  "test:version-fleet": {
    subjects: ["workstation/**", "VERSION"],
    why: "#2959 — 'delta chạm workstation/ thì VERSION phải đổi'",
  },
  "test:buildspecs-uploaded": {
    subjects: [".github/amplify/**", ".github/workflows/**"],
    why: "#3229 — 'mọi buildspec phải có workflow nạp nó'; PR thêm một buildspec mồ côi chỉ chạm .github/amplify/",
  },
  "test:deploy-smoke": {
    subjects: [".github/workflows/**", ".github/amplify/**"],
    why: "#3231 — 'smoke phải hỏi bundle nào đang phục vụ'; nó đọc CẢ workflow lẫn buildspec",
  },
  "test:workflows-parse": {
    subjects: [".github/workflows/**"],
    why: "#2971 — canh cú pháp MỌI workflow, gồm deploy-xserver.yml ghi vào DB production",
  },
  "test:version": {
    subjects: ["VERSION"],
    why: "#2660 — MỘT số phiên bản cho cả cây",
  },
  "test:promote-gate": {
    subjects: ["scripts/**"],
    why: "#3093 — phép phân loại xoá-có-chủ-đích vs hotfix-bỏ-quên sống ở scripts/",
  },
  "test:version-drift": {
    subjects: [".github/scripts/**"],
    why: "#3065 — logic của monitor sống ở .github/scripts/, mà paths: cũ chỉ có .github/workflows/**",
  },
  "test:ws-expected": {
    // File CỐ Ý ở gốc cây, không trong `workstation/`: đặt trong đó là dựng lại
    // vòng tròn #3145 với `test:version-fleet` (bump expected ⇒ đòi bump
    // VERSION ⇒ phát hành bản mới hơn ⇒ expected vừa đặt đã cũ). Hệ quả là
    // KHÔNG mẫu nào có sẵn phủ nó — phải khai thẳng.
    subjects: ["WORKSTATION_EXPECTED_VERSION", ".github/workflows/**"],
    why: "#3173 — con số 'quán nên ở bản nào' phải đi từ repo xuống .env, trước config:cache",
  },
  "test:ws-frontend-ui": {
    subjects: ["workstation/frontend/**"],
    why: "ruling 2026-08-18 — workstation/frontend chỉ dùng @godxjp/ui; dep/import UI khác phải đỏ ngay PR chạm frontend",
  },
  "test:app-gates": {
    // #3133 — MỘT mẫu, không phải hai mẫu ghim nhóm. Rào đã thôi duyệt
    // `["app", "web"]` gõ cứng (chính nó làm `workstation/frontend` — app JS
    // duy nhất không có test runner — vô hình), nên lời khai ở đây phải rộng
    // đúng bằng phép liệt kê mới: MỌI thư mục cấp hai có `package.json`. Khai
    // hẹp hơn thì nhóm thứ tư lọt lại y hệt, và lần này rào sẽ IM chứ không kêu.
    subjects: ["*/*/package.json"],
    why: "#3002/#3133 — 'app CÓ TEST thì phải có cổng'; rào đọc package.json cấp hai của MỌI nhóm, không riêng app/ và web/",
  },
};

/**
 * `paths:` của TỪNG trigger, KHÔNG gộp.
 *
 * Bản đầu của bài này gộp `push` + `pull_request` thành một mảng, và phép thử
 * ngược cho thấy nó **không kêu** khi tôi gỡ subject khỏi riêng `push`: bản sao
 * bên `pull_request` che mất. Nhưng đó đúng là lỗ của #2959 — gate chạy lúc mở
 * PR rồi im lúc đẩy thẳng vào `dev`, mà đẩy thẳng mới là đường ra production.
 * Mỗi trigger phải tự phủ đủ.
 */
function gateTriggers() {
  const doc = parse(readFileSync(join(root, GATE), "utf8"));
  // `on` là khoá YAML 1.1 bị đọc thành boolean true bởi một số parser —
  // lấy cả hai dạng để bài này không im lặng đo một object rỗng.
  const on = doc.on ?? doc[true];

  assert.ok(on, `${GATE}: không đọc được khối 'on:' — bài này sẽ vô nghĩa`);

  const triggers = Object.entries(on)
    .filter(([, cfg]) => cfg && Array.isArray(cfg.paths))
    .map(([name, cfg]) => [name, cfg.paths]);

  assert.ok(triggers.length > 0, `${GATE}: không trigger nào có 'paths:' — bài này sẽ vô nghĩa`);

  return triggers;
}

test("mỗi guard trong omnify-gate được paths: cho phép nhìn thấy thứ nó canh", () => {
  const missing = [];

  for (const [trigger, paths] of gateTriggers()) {
    assert.ok(paths.length > 0, `on.${trigger}.paths rỗng — bộ lọc không còn canh gì`);

    for (const [script, { subjects, why }] of Object.entries(GUARD_SUBJECTS)) {
      for (const subject of subjects) {
        if (!paths.some((p) => covers(p, subject))) {
          missing.push(`on.${trigger}: ${script} canh ${subject} — paths: không cho nó thấy  (${why})`);
        }
      }
    }
  }

  assert.deepEqual(
    missing,
    [],
    `Rào nằm sau bộ lọc loại trừ chính thứ nó canh:\n  ${missing.join("\n  ")}\n\n` +
      `Đây là hình dạng đã vấp BA lần (#2874, #2959, #2971). Rào đúng mà không\n` +
      `chạy thì đọc log CI y hệt rào đúng và có chạy — nên câu hỏi cần đặt là\n` +
      `"lượt chạy này CÓ chạm vào rào không", không phải "rào có đúng không".`,
  );
});

/**
 * Phép đo CƠ HỌC, không dựa vào danh sách ai đó nhớ ra.
 *
 * `GUARD_SUBJECTS` ở trên chỉ phủ những guard tôi khai — mà chính chỗ yếu của
 * ba lần vấp trước là **thứ không ai nghĩ tới**. Bài này quét toàn bộ
 * `package.json` và đòi mọi script `test:*` phải được MỘT workflow nào đó gọi.
 *
 * Lượt chạy đầu tiên của nó tìm ra BỐN guard mồ côi: `workflows-parse`,
 * `omnify-check`, `ws-manifest-restore`, `ws-publish` — tất cả đều đã xanh,
 * tất cả đều chưa bao giờ chạy trên CI.
 */
test("mọi script test:* trong package.json đều được một workflow gọi", () => {
  const pkg = JSON.parse(readFileSync(join(root, "package.json"), "utf8"));
  const dir = join(root, ".github/workflows");
  const yaml = readdirSync(dir)
    .filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))
    .map((f) => readFileSync(join(dir, f), "utf8"))
    .join("\n");

  const orphans = Object.keys(pkg.scripts ?? {})
    .filter((s) => s.startsWith("test:"))
    .filter((s) => !DELIBERATELY_LOCAL.includes(s))
    .filter((s) => !yaml.includes(`npm run ${s}`));

  assert.deepEqual(
    orphans,
    [],
    `Guard có script nhưng KHÔNG workflow nào gọi:\n  ${orphans.join("\n  ")}\n\n` +
      `Một rào chưa bao giờ chạy đọc y hệt một rào luôn xanh. Nối nó vào một\n` +
      `workflow, hoặc khai vào DELIBERATELY_LOCAL kèm lý do đo được.`,
  );
});

test("KÊU: bỏ một subject khỏi paths thì bài này đỏ", () => {
  // Chiều kêu, đo trên dữ liệu tổng hợp — không sửa file thật, vì file thật là
  // thứ đang canh production và một bài test không được đụng vào nó.
  const paths = [".omnify/**", "package.json"];
  const missing = [];

  for (const [script, { subjects }] of Object.entries(GUARD_SUBJECTS)) {
    for (const subject of subjects) {
      if (!paths.some((p) => covers(p, subject))) missing.push(`${script}:${subject}`);
    }
  }

  assert.ok(missing.length > 0, "phép so không phát hiện được subject bị thiếu");
});

test("covers(): khớp theo THƯ MỤC, không theo chuỗi con", () => {
  assert.equal(covers(".github/workflows/**", ".github/workflows/deploy-xserver.yml"), true);
  assert.equal(covers("workstation/**", "workstation/internal/x.go"), true);
  assert.equal(covers("VERSION", "VERSION"), true);

  // `docs/my-workstation-notes/` KHÔNG phải cây workstation. Khớp bằng
  // `includes` sẽ bắt nhầm nó, và một rào bắt nhầm là một rào sắp bị tắt.
  assert.equal(covers("workstation/**", "docs/my-workstation-notes/a.md"), false);
  assert.equal(covers("package.json", "VERSION"), false);
});
