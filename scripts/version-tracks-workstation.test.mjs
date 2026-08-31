import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import {
  NOT_IN_BINARY,
  VERSIONED_TREES,
  checkVersionTracksFleet,
  manifestDeltaIsVersionOnly,
  VERSION_ONLY_MANIFEST,
  isExemptFromVersioning,
} from "./version-tracks-workstation.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * #2898 — số hiệu phải đi theo thứ đã đi.
 *
 * Rào phải biết KÊU và biết IM. Chiều IM ở đây quan trọng bất thường: nếu nó
 * đòi bump cho MỌI thay đổi thì mỗi PR sửa lỗi chính tả cũng phải bump, và một
 * rào phiền như vậy sẽ bị tắt trong tuần.
 */

test("KÊU: chạm workstation mà VERSION đứng yên", () => {
  const r = checkVersionTracksFleet({
    changedFiles: ["workstation/internal/handler/order_hold.go", "docs/a.md"],
    versionChanged: false,
  });

  assert.equal(r.ok, false);
  assert.deepEqual(r.offending, ["workstation/internal/handler/order_hold.go"]);
});

test("IM: chạm workstation VÀ đã bump", () => {
  assert.equal(
    checkVersionTracksFleet({
      changedFiles: ["workstation/x.go", "VERSION"],
      versionChanged: true,
    }).ok,
    true,
  );
});

test("IM: không chạm cây nào cần số hiệu", () => {
  // Đây là ca giữ cho rào không phiền. Bỏ nó đi thì lần đầu ai đó sửa một dòng
  // docs cũng bị chặn, và phản ứng sẽ là gỡ rào chứ không phải bump.
  const r = checkVersionTracksFleet({
    changedFiles: ["backend/app/Foo.php", "docs/b.md", "web/pos/src/c.ts"],
    versionChanged: false,
  });

  assert.equal(r.ok, true);
  assert.deepEqual(r.offending, []);
});

test("IM: tập rỗng", () => {
  assert.equal(
    checkVersionTracksFleet({ changedFiles: [], versionChanged: false }).ok,
    true,
  );
});

test("tên cây khớp theo TIỀN TỐ, không theo chuỗi con", () => {
  // `my-workstation-notes/` KHÔNG phải cây workstation. Khớp bằng `includes`
  // sẽ bắt nhầm nó, và một rào bắt nhầm là một rào sắp bị tắt.
  const r = checkVersionTracksFleet({
    changedFiles: ["docs/my-workstation-notes/a.md"],
    versionChanged: false,
  });

  assert.equal(r.ok, true, "khớp chuỗi con sẽ làm ca này đỏ oan");
});

test("phép đo THẬT trên cây: từ lần bump gần nhất tới HEAD", () => {
  // Đây là ca nối phép quyết định với repo thật. Nó cũng là ca dễ hỏng im lặng
  // nhất — nếu `git log -- VERSION` không tìm thấy commit nào thì phép so sẽ
  // rỗng và rào xanh vĩnh viễn mà không canh gì.
  // #3005 — repo NÔNG làm phép đo này rỗng mà không kêu tiếng nào.
  //
  // `actions/checkout@v4` mặc định `fetch-depth: 1`, và `omnify-gate.yml` không
  // đặt gì khác. Trong clone một-commit thì `git log -1 -- VERSION` trả về
  // CHÍNH HEAD (mọi file trông như vừa được thêm), nên `git diff lastBump HEAD`
  // rỗng và rào xanh vô điều kiện. Đo được: nhánh `issue-2934` có 12 file
  // `workstation/` đổi mà CI vẫn báo pass, trong khi chạy cục bộ thì ĐỎ.
  //
  // Assert cũ (`lastBump !== ""`) KHÔNG cứu được: nó lường ca "không tìm thấy
  // commit nào", không lường ca "tìm thấy chính mình". Nên phải hỏi thẳng git
  // xem repo có nông không, và ĐỎ nếu có — sửa `fetch-depth` mà không có bài
  // này thì lần sau ai đổi checkout là rào lại câm y hệt.
  const shallow = execFileSync("git", ["rev-parse", "--is-shallow-repository"], {
    cwd: root,
    encoding: "utf8",
  }).trim();

  assert.equal(
    shallow,
    "false",
    "repo NÔNG — `git log -- VERSION` sẽ trả về chính HEAD và phép đo bên dưới " +
      "rỗng, tức rào xanh mà không canh gì. Đặt `fetch-depth: 0` cho bước checkout.",
  );

  const lastBump = execFileSync(
    "git",
    ["log", "--format=%H", "-1", "HEAD", "--", "VERSION"],
    { cwd: root, encoding: "utf8" },
  ).trim();

  assert.notEqual(lastBump, "", "không tìm thấy commit nào đổi VERSION — phép đo mất nghĩa");


  const changed = execFileSync(
    "git",
    ["diff", "--name-only", `${lastBump}`, "HEAD"],
    { cwd: root, encoding: "utf8" },
  )
    .split("\n")
    .filter(Boolean);

  // #3145 — đo NỘI DUNG delta cho từng manifest mang số phiên bản.
  //
  // Người gọi đo, hàm quyết định vẫn thuần. `git show` có thể thất bại một cách
  // hoàn toàn chính đáng (file vừa được thêm, hoặc vừa bị xoá) — lúc đó không
  // đo được, và không đo được thì KHÔNG miễn trừ.
  const versionOnlyManifests = changed
    .filter((f) => VERSION_ONLY_MANIFEST.test(f))
    .filter((f) => {
      const read = (ref) => {
        try {
          return execFileSync("git", ["show", `${ref}:${f}`], { cwd: root, encoding: "utf8" });
        } catch {
          return null;
        }
      };

      const before = read(lastBump);
      const after = read("HEAD");

      return before !== null && after !== null && manifestDeltaIsVersionOnly(before, after);
    });

  const r = checkVersionTracksFleet({
    changedFiles: changed,
    versionChanged: changed.includes("VERSION"),
    versionOnlyManifests,
  });

  // #3022 — KHÔNG assert "tập thay đổi phải khác rỗng".
  //
  // Bản đầu có assert ấy và nó đỏ OAN đúng ở hành vi ta muốn khuyến khích: một
  // nhánh đã gộp `dev` vào rồi mới bump thì `diff(lastBump, HEAD)` rỗng một
  // cách hoàn toàn chính đáng — bump chính là thứ mới nhất, sau nó không có gì
  // đổi. Đo được: #2998 (bump trên nhánh cũ) xanh, #2999 (đồng bộ base trước
  // rồi bump) đỏ. Nhánh càng cập nhật càng dễ đỏ.
  //
  // Vế `headTouchedVersion` cũng không cứu được: `pull_request` checkout
  // MERGE-REF (base + head), nên HEAD là commit gộp tổng hợp và không bao giờ
  // bằng commit bump.
  //
  // Ca duy nhất khiến tập rỗng trở nên VÔ NGHĨA là repo nông, và ca đó đã có
  // phép đo riêng ở trên. Thêm rào thứ hai cho cùng một nỗi lo không thêm phủ,
  // chỉ thêm báo động giả — và rào kêu oan thì bị TẮT.

  assert.equal(
    r.ok,
    true,
    `${r.reason}\n  ${r.offending.slice(0, 8).join("\n  ")}\n\n` +
      `Fleet là máy Windows KHÔNG tự cập nhật; version → commit tra bằng manifest.json\n` +
      `của trang tải. Hai máy cùng số hiệu mà khác bản là câu "máy nào đã chạy migration X"\n` +
      `không trả lời được. Bump VERSION trong chính PR chạm ${VERSIONED_TREES.join(", ")}.`,
  );
});

// ─────────────────────────────────────────────────────────────────────────────
// #3066 — file KHÔNG đi vào binary thì không đòi số hiệu
//
// Cả hai chiều phải được ghim, và chiều KÊU quan trọng hơn: rào kêu thừa thì
// người ta bump thêm một số (rẻ); rào im khi cần kêu thì hai máy mang cùng số
// hiệu mà chạy khác bản, và câu hỏi "máy nào đã chạy migration 087" vĩnh viễn
// không trả lời được.
// ─────────────────────────────────────────────────────────────────────────────

test("#3066 IM: chỉ đổi testdata ⇒ KHÔNG đòi bump — đúng ca PR #3063", () => {
  const r = checkVersionTracksFleet({
    changedFiles: ["workstation/internal/handler/testdata/pos-api-manifest.json"],
    versionChanged: false,
  });

  assert.equal(r.ok, true);
  assert.deepEqual(r.offending, []);
});

test("#3066 IM: chỉ đổi *_test.go ⇒ KHÔNG đòi bump", () => {
  assert.equal(
    checkVersionTracksFleet({
      changedFiles: ["workstation/internal/service/sync_pull_test.go"],
      versionChanged: false,
    }).ok,
    true,
  );
});

test("#3066 KÊU: .go thật đổi CÙNG LƯỢT với testdata ⇒ vẫn đòi bump", () => {
  // Ca nguy hiểm nhất của một miễn trừ: nó nuốt luôn thứ đáng kêu vì đứng cạnh.
  const r = checkVersionTracksFleet({
    changedFiles: [
      "workstation/internal/handler/testdata/pos-api-manifest.json",
      "workstation/internal/handler/order_hold.go",
    ],
    versionChanged: false,
  });

  assert.equal(r.ok, false);
  assert.deepEqual(r.offending, ["workstation/internal/handler/order_hold.go"]);
});

test("#3066 KÊU: .md trong workstation KHÔNG được miễn trừ", () => {
  // `.md` trông an toàn nhưng không chứng minh được: `posweb.go`/`frontend.go`
  // embed `all:pos-web/dist` và `all:frontend/dist`, mà cây dist do CI dựng —
  // ở máy cá nhân chỉ có file stub. Đo "không .md nào bị embed" trên cây rỗng
  // là không đo gì. Không đo được thì không miễn trừ.
  assert.equal(isExemptFromVersioning("workstation/README.md"), false);
  assert.equal(
    checkVersionTracksFleet({
      changedFiles: ["workstation/README.md"],
      versionChanged: false,
    }).ok,
    false,
  );
});

test("#3066 miễn trừ chỉ áp TRONG cây cần số hiệu, không phải mọi nơi", () => {
  // `isExemptFromVersioning` khớp theo mẫu tên nên nó cũng trả true cho một
  // `testdata/` ở backend — vô hại, vì bộ lọc cây chạy TRƯỚC. Ghim thứ tự đó:
  // đảo lại thì một `backend/**/testdata/` sẽ không còn được lọc cây loại ra.
  const r = checkVersionTracksFleet({
    changedFiles: ["backend/tests/testdata/x.json"],
    versionChanged: false,
  });
  assert.equal(r.ok, true);
  assert.deepEqual(r.offending, []);
});

test("#3066 mỗi mẫu miễn trừ phải nói ra LÝ DO", () => {
  // Miễn trừ không kèm lý do sẽ sống mãi: người sau đọc không biết nó còn đúng
  // không, nên không ai dám gỡ và cũng không ai kiểm lại.
  assert.ok(NOT_IN_BINARY.length > 0, "danh sách miễn trừ rỗng — bài này vô nghĩa");
  for (const { pattern, why } of NOT_IN_BINARY) {
    assert.ok(pattern instanceof RegExp, "mẫu phải là RegExp");
    assert.ok((why ?? "").length >= 20, `mẫu ${pattern} thiếu lý do đo được`);
  }
});

test("#3066 rào vẫn KÊU cho file .go thường — miễn trừ không nuốt trục chính", () => {
  // Chiều ngược của cả nhóm: nếu một sửa đổi làm `offending` luôn rỗng thì mọi
  // bài IM ở trên vẫn xanh và không gì đỏ. Bài này là cái chốt.
  assert.equal(
    checkVersionTracksFleet({
      changedFiles: ["workstation/cmd/ws-server/main.go"],
      versionChanged: false,
    }).ok,
    false,
  );
});

// ─────────────────────────────────────────────────────────────────────────────
// #3145 — vòng tròn: cổng đếm chính cái đuôi của mình
//
// `workstation/frontend/package.json` tồn tại một phần để MANG số phiên bản, mà
// rào `test:version` bắt buộc nó khai đúng số — nên mọi lần bump đều sửa nó, và
// việc sửa nó lại bị đọc thành "cây đã đổi, phải bump".
//
// Miễn trừ ở đây xét NỘI DUNG chứ không xét đường dẫn, nên rào phải chứng minh
// cả hai chiều. Chiều KÊU quan trọng hơn: file này cũng khai dependency, và
// một miễn trừ nuốt luôn ca đó sẽ để binary đổi mà số hiệu đứng yên.
// ─────────────────────────────────────────────────────────────────────────────

const MANIFEST = "workstation/frontend/package.json";

test("#3145 IM: chỉ đổi số phiên bản trong manifest ⇒ KHÔNG đòi bump", () => {
  const before = JSON.stringify({ name: "ws", version: "0.8.22", dependencies: { react: "19.0.0" } });
  const after = JSON.stringify({ name: "ws", version: "0.8.23", dependencies: { react: "19.0.0" } });

  assert.equal(manifestDeltaIsVersionOnly(before, after), true);

  const r = checkVersionTracksFleet({
    changedFiles: [MANIFEST],
    versionChanged: false,
    versionOnlyManifests: [MANIFEST],
  });

  assert.equal(r.ok, true, r.reason);
  assert.deepEqual(r.offending, []);
});

test("#3145 KÊU: đổi dependencies của chính manifest đó ⇒ VẪN đòi bump", () => {
  // Đây là ca mà issue cảnh báo trước khi làm, và là lý do miễn trừ không thể
  // theo đường dẫn: đổi dependency thì binary CÓ đổi.
  const before = JSON.stringify({ name: "ws", version: "0.8.22", dependencies: { react: "19.0.0" } });
  const after = JSON.stringify({ name: "ws", version: "0.8.23", dependencies: { react: "19.1.0" } });

  assert.equal(manifestDeltaIsVersionOnly(before, after), false);

  const r = checkVersionTracksFleet({
    changedFiles: [MANIFEST],
    versionChanged: false,
    // Người gọi đo được là KHÔNG version-only, nên không truyền vào đây.
    versionOnlyManifests: [],
  });

  assert.equal(r.ok, false);
  assert.deepEqual(r.offending, [MANIFEST]);
});

test("#3145 KÊU: manifest version-only KHÔNG che file .go đứng cạnh", () => {
  // Cùng cái bẫy mà #3066 đã ghim cho miễn trừ trước: một miễn trừ nuốt luôn
  // thứ đáng kêu vì nó đứng cùng lượt.
  const r = checkVersionTracksFleet({
    changedFiles: [MANIFEST, "workstation/internal/service/sync_pull.go"],
    versionChanged: false,
    versionOnlyManifests: [MANIFEST],
  });

  assert.equal(r.ok, false);
  assert.deepEqual(r.offending, ["workstation/internal/service/sync_pull.go"]);
});

test("#3145 KÊU: không parse được thì KHÔNG miễn trừ", () => {
  // Không đo được thì không miễn — cùng cân bất đối xứng mà cổng đã chọn.
  assert.equal(manifestDeltaIsVersionOnly("{khong-phai-json", "{}"), false);
  assert.equal(manifestDeltaIsVersionOnly("{}", "{khong-phai-json"), false);
  assert.equal(manifestDeltaIsVersionOnly("null", "null"), false);
});

test("#3145 lock file: số hiệu chép ở packages[\"\"] cũng được bỏ qua", () => {
  // Bỏ sót khoá này thì lock file không bao giờ được miễn, và vòng tròn còn
  // nguyên cho nửa kia.
  const before = JSON.stringify({ version: "0.8.22", packages: { "": { version: "0.8.22", name: "ws" } } });
  const after = JSON.stringify({ version: "0.8.23", packages: { "": { version: "0.8.23", name: "ws" } } });

  assert.equal(manifestDeltaIsVersionOnly(before, after), true);
});

test("#3145 thứ tự khoá không phải nội dung", () => {
  const before = JSON.stringify({ version: "0.8.22", name: "ws", dependencies: { b: "1", a: "2" } });
  const after = JSON.stringify({ name: "ws", dependencies: { a: "2", b: "1" }, version: "0.8.23" });

  assert.equal(manifestDeltaIsVersionOnly(before, after), true);
});
