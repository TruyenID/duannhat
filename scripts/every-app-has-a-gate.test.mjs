/**
 * App có test thì phải có CỔNG chạy nó (#3002).
 *
 * ## Ca thật
 *
 * PR #2997 sửa i18n của `app/tms` và có bảng checks **trống trơn**:
 *
 *     grep -rln "app/tms" .github/workflows/   → RỖNG
 *
 * Không workflow nào canh `app/tms/**`. Tôi phải `npm test` tay trong một
 * worktree sạch mới biết nó xanh (4 file / 38 test).
 *
 * Đây là tầng tệ hơn "cổng có mà không chạy" (`docs/guide/cong-xanh-do-vi-khong-chay.md`):
 * kia còn có cổng để mà hỏi vì sao nó im, đây thì **không có cổng nào**. Và
 * bảng checks trống đọc y hệt "đang chờ chạy" — người review đợi một lúc rồi
 * merge.
 *
 * ## Rào này đo gì
 *
 * Mọi thư mục app cấp hai mà `package.json` khai script `test` thì tên nó phải
 * xuất hiện trong một workflow. Đứng ngoài có chủ đích thì khai vào
 * `DELIBERATELY_UNGATED` **kèm lý do đo được** — lúc đó có người đọc, và im
 * lặng mới là thứ bị chặn.
 *
 * ## #3133 — chính rào này từng mù một app
 *
 * Bản đầu duyệt hai thư mục GÕ CỨNG (`["app", "web"]`), nên `workstation/frontend`
 * — app JS duy nhất của repo không có test runner — nằm ngoài tầm nhìn của nó.
 * Không phải được miễn trừ: **vô hình**. Phép liệt kê nay đọc từ đĩa; xem
 * `appDirs()`.
 *
 * ## #3135 — và rồi chính rào này đo NHẦM THỨ
 *
 * Sửa xong phép liệt kê, phép SO vẫn là `workflowText.includes(app)` trên text
 * của mọi workflow nối lại. Nên "có cổng" chỉ có nghĩa là **tên thư mục xuất
 * hiện ở đâu đó** — `paths:`, `working-directory`, `cache-dependency-path`.
 *
 * `workstation/frontend` đã có tên trong `workstation-release.yml` (một workflow
 * **chỉ BUILD**) từ trước, nên lúc #3133 thêm script `test` cho nó, rào **vẫn
 * xanh** — xanh vì một lý do KHÁC hẳn lý do nó nói. Nếu #3133 chỉ thêm script mà
 * quên nối CI thì không gì đỏ, và người đọc log tin rằng app đã được canh.
 *
 * Câu hỏi đúng không phải *"tên có xuất hiện không"* mà **"có bước nào CHẠY test
 * của CHÍNH app này không"** — trả lời bằng cách parse YAML, ở
 * `scripts/lib/workflow-test-steps.mjs`. Đơn vị đo là **BƯỚC**, không phải job:
 * phép trung gian "tên app nằm trong một JOB có bước chạy test" cũng xanh, vì
 * `workstation-release.yml` có `go test` ở một job và `workstation/frontend` ở
 * một job khác của cùng file, chẳng liên quan gì nhau.
 *
 * Phụ phẩm: parse YAML thì comment biến mất hẳn, nên bẫy "chép tên app vào
 * docblock làm rào tự thoả mãn" (bản đầu #3002 dính) không còn đường sống — bản
 * trước phải lọc comment bằng tay.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

import { testStepsByDir } from "./lib/workflow-test-steps.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const workflowDir = join(root, ".github/workflows");

/**
 * App KHÔNG có cổng, có chủ đích — kèm LOẠI lý do.
 *
 * Hai loại, và phân biệt chúng là cả điểm của cấu trúc này:
 *
 *   `no-tests`  không có gì để chạy. Miễn trừ này **tự hết hạn**: app thêm
 *               test là rào bên dưới đỏ ngay.
 *   `blocked`   CÓ test, chạy được ở máy cá nhân, nhưng cổng chưa dựng được vì
 *               một thứ ngoài mã. Phải ghi `unblockedBy` — điều kiện đo được
 *               để gỡ, không phải một cái hẹn.
 *
 * Bản đầu của file này chỉ có một loại, và nó bắt nhầm ngay lượt chạy đầu:
 * `app/tms` có test nên bị coi là "miễn trừ hết hạn", trong khi lý do thật là
 * runner không clone được repo private. Gộp hai loại lại thì hoặc rào kêu oan,
 * hoặc phải nới nó ra cho xong — và nới xong thì `no-tests` cũng hết canh.
 *
 * Đừng thêm vào đây để làm test xanh. Câu phải trả lời trước là: vì sao app này
 * không cần ai canh, hoặc cái gì đang chặn?
 */
const DELIBERATELY_UNGATED = {
  "app/handy": { kind: "no-tests", why: "chỉ có `lint`, chưa có script `test`" },

  // `app/tms` + `app/kiosk` ĐÃ RA KHỎI đây (#3002). Chúng đứng ngoài cổng vì
  // `npm ci` phải clone hai repo RIÊNG TƯ qua `github:` specifier và runner
  // không có credential — đo được là exit **128**, mã của `git` chứ không phải
  // của npm, cả ở PR #3039 lẫn khi đo lại 2026-08-17.
  //
  // Cái gỡ nút không phải một secret: hai gói là hàng TỰ LÀM, nên chúng về
  // `app/packages/` và được trỏ bằng `file:` (ruling chủ dự án 2026-08-17, cùng
  // khuôn `web/packages/`). Không còn repo riêng tư nào để clone thì không còn
  // gì để xin quyền.
};

/**
 * Thư mục không bao giờ là app: cây phụ thuộc và cây sinh ra.
 *
 * Đây KHÔNG phải danh sách nhóm — nó là danh sách thứ phải bỏ qua khi đi tìm,
 * và nó nhỏ đi theo thời gian chứ không lớn lên. Danh sách NHÓM mới là thứ đã
 * đẻ ra #3133; xem docblock của `appDirs()`.
 */
const NOT_A_TREE_WITH_APPS = new Set(["node_modules", "vendor", "dist", "build", "bin", "out"]);

/**
 * Mọi app JS trong cây, ĐO ĐƯỢC — không gõ cứng tên nhóm.
 *
 * ## Vì sao không còn `for (const group of ["app", "web"])`
 *
 * #3133: `workstation/frontend` — 33 file `.ts`/`.tsx`, trong đó có panel xác
 * nhận lệch tiền của #2848 — nằm dưới `workstation/`, nên vòng lặp hai nhóm gõ
 * cứng **không hề nhìn thấy nó**. Nó cũng không nằm trong `DELIBERATELY_UNGATED`.
 * Một app được miễn trừ thì có người quyết và có lý do ghi lại; một app **vô
 * hình** thì không có cả hai, và không ai biết để hỏi.
 *
 * Thêm `"workstation"` vào mảng ấy là lặp lại đúng sai lầm ở quy mô nhỏ hơn:
 * nhóm thứ tư sẽ vô hình y như vậy. Phép liệt kê phải dựa trên thứ **đo được
 * trên đĩa** — thư mục có `package.json` khai `scripts` — chứ không dựa trên
 * việc ai đó nhớ ra cập nhật một mảng.
 *
 * ## Hai ranh giới, và vì sao chúng đo được chứ không phải sở thích
 *
 * **`scripts` chứ không phải chỉ `package.json`**: `packages/tsconfig` và hai
 * anh em của nó là gói cấu hình thuần dữ liệu, không có script nào để chạy —
 * nên không có gì cho một cổng CI gọi. Chúng rơi ra vì phép đo, không vì tên.
 *
 * **Sâu đúng HAI cấp** (`<nhóm>/<app>`): đó là bố cục thật của monorepo này
 * (`app/*`, `web/*`, `workstation/frontend`). Cấp ba là
 * `web/packages/godx-tempo-ui` — bản vendor của một repo KHÁC
 * (`godx-jp/godx-tempo-ui`), test của nó thuộc về repo đó. Ranh giới là ĐỘ SÂU,
 * một con số kiểm được, không phải một danh sách tên phải nhớ.
 */
function appDirs() {
  const out = [];

  function subdirs(dir) {
    try {
      return readdirSync(dir, { withFileTypes: true })
        .filter((e) => e.isDirectory())
        .map((e) => e.name)
        .filter((name) => !name.startsWith(".") && !NOT_A_TREE_WITH_APPS.has(name));
    } catch {
      return [];
    }
  }

  for (const group of subdirs(root)) {
    for (const name of subdirs(join(root, group))) {
      const pkg = join(root, group, name, "package.json");
      if (!existsSync(pkg)) continue;

      let scripts;
      try {
        scripts = JSON.parse(readFileSync(pkg, "utf8")).scripts;
      } catch {
        continue;
      }

      // Không có `scripts` ⇒ không có gì để một cổng chạy (xem docblock).
      if (scripts === undefined || scripts === null || typeof scripts !== "object") continue;

      out.push({ app: `${group}/${name}`, hasTest: Boolean(scripts.test) });
    }
  }

  return out.sort((a, b) => a.app.localeCompare(b.app));
}

/** Mọi workflow của repo, dạng thô — `testStepsByDir` tự parse. */
function workflows() {
  return readdirSync(workflowDir)
    .filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))
    .map((f) => ({ file: f, text: readFileSync(join(workflowDir, f), "utf8") }));
}

/** thư mục → bằng chứng ("file › job › bước") của các bước CHẠY test. */
const testSteps = testStepsByDir(workflows());

test("#3002/#3135 mọi app CÓ TEST đều có một BƯỚC chạy test của chính nó", () => {
  const ungated = appDirs()
    .filter((a) => a.hasTest)
    .filter((a) => !testSteps.has(a.app))
    .map((a) => a.app)
    .filter((a) => !(a in DELIBERATELY_UNGATED));

  assert.deepEqual(
    ungated,
    [],
    "App có test mà KHÔNG bước nào chạy test của nó:\n  " +
      ungated.join("\n  ") +
      "\n\nPR chỉ chạm chúng sẽ đi qua CI với 0 cổng, và bảng checks trống đọc " +
      "y hệt 'đang chờ chạy'. Thêm một bước chạy test của app (thư mục làm việc " +
      "là app đó + lệnh gọi runner test), hoặc khai vào `DELIBERATELY_UNGATED` " +
      "kèm lý do.\n\nTên app xuất hiện trong một workflow KHÔNG còn tính (#3135) — " +
      "`workstation/frontend` từng 'có cổng' chỉ vì `workstation-release.yml`, " +
      "một workflow chỉ build, có tên nó ở `cache-dependency-path`.",
  );
});

test("#3002 mục miễn trừ phải CÒN ĐÚNG — app đã có test thì không được nằm đó", () => {
  // Miễn trừ hết hạn còn tệ hơn không có: nó đọc như một quyết định đang có
  // hiệu lực. `app/handy` thêm test mà quên bỏ khỏi danh sách thì nó im lặng
  // ở ngoài mọi cổng.
  // CHỈ áp cho loại `no-tests` — đó là loại duy nhất tự hết hạn được bằng phép
  // đo. Loại `blocked` hết hạn bằng một quyết định của người, nên rào không có
  // tư cách phán; nó chỉ đòi lý do phải nói ra điều gì gỡ được (ca dưới).
  const stale = appDirs()
    .filter((a) => a.hasTest && DELIBERATELY_UNGATED[a.app]?.kind === "no-tests")
    .map((a) => a.app);

  assert.deepEqual(
    stale,
    [],
    "Đã có test nhưng vẫn nằm trong `DELIBERATELY_UNGATED`:\n  " +
      stale.join("\n  ") +
      "\n\nLý do miễn trừ hết đúng — cho nó vào một workflow rồi xoá khỏi danh sách.",
  );
});

test("#3195 mục miễn trừ phải trỏ vào app CÒN TỒN TẠI", () => {
  // LỖ CỦA CHÍNH BÁNH CÓC Ở TRÊN. Bài trước lọc trên `appDirs()` — tức chỉ
  // những app CÒN TỒN TẠI. Một entry trỏ vào thư mục đã bị XOÁ không nằm trong
  // tập đó, nên không phép đo nào ghé tới nó, và nó sống mãi.
  //
  // Nó không vô hại: nếu sau này có ai dựng lại một app trùng tên, entry cũ
  // miễn trừ nó ngay từ lúc ra đời — app mới đi qua CI với 0 cổng, đúng thứ
  // #3002 sinh ra để chặn.
  //
  // Cùng lớp lỗi với `allowedDuplicates = {40: true}` (#3184) và
  // `KNOWN_BROKEN_FACTORIES` — bánh cóc chỉ nhìn thấy hiện tại thì danh sách
  // chỉ có thể dài ra.
  const known = new Set(appDirs().map((a) => a.app));

  // Sàn chống rỗng phải đứng TRƯỚC phép so, và thứ tự đó là load-bearing.
  // `appDirs()` hỏng thì `known` rỗng, và khi đó MỌI entry đọc thành "đã biến
  // mất" — bài sẽ đỏ với thông điệp sai và đẩy người đọc đi sửa nhầm file.
  // (Bản đầu của bài này đặt sàn ở sau; chính một lượt đột biến làm lộ ra.)
  assert.ok(
    known.size >= 5,
    `chỉ tìm thấy ${known.size} app — \`appDirs()\` hỏng hoặc bố cục đã đổi; sửa bài test chứ đừng xoá.`,
  );

  const vanished = Object.keys(DELIBERATELY_UNGATED).filter((a) => !known.has(a));

  assert.deepEqual(
    vanished,
    [],
    "`DELIBERATELY_UNGATED` nêu app KHÔNG còn tồn tại:\n  " +
      vanished.join("\n  ") +
      "\n\nGỡ entry đi — nó không miễn trừ gì nữa, chỉ cấp sẵn giấy phép cho một app cùng tên sau này.",
  );
});

test("#3002 mục `blocked` phải nói ra CÁI GÌ gỡ được nó", () => {
  // Một miễn trừ không nói điều kiện gỡ sẽ sống mãi: người sau đọc nó không
  // biết phải làm gì, nên không ai làm gì.
  const vague = Object.entries(DELIBERATELY_UNGATED)
    .filter(([, v]) => v.kind === "blocked")
    .filter(([, v]) => !v.unblockedBy || v.unblockedBy.trim().length < 10)
    .map(([app]) => app);

  assert.deepEqual(vague, [], "thiếu `unblockedBy` đo được:\n  " + vague.join("\n  "));
});

test("#3002 rào này có thứ để canh — không phải rào rỗng", () => {
  // Một rào duyệt qua 0 phần tử thì luôn xanh và đọc như đã phủ.
  assert.ok(
    appDirs().filter((a) => a.hasTest).length >= 4,
    "quét ra quá ít app có test — nhiều khả năng đường dò thư mục đã hỏng, không phải repo teo lại",
  );
});

test("#3133 phép liệt kê nhìn ra NGOÀI app/ và web/", () => {
  // Ghim đúng lỗ đã trả giá. Không có bài này thì một lần "dọn dẹp" đưa vòng
  // lặp về hai nhóm gõ cứng sẽ đi qua với mọi bài trên vẫn xanh — vì app biến
  // mất khỏi phép đo thì cũng biến mất khỏi mọi phép so.
  const apps = appDirs().map((a) => a.app);

  assert.ok(
    apps.includes("workstation/frontend"),
    "`workstation/frontend` không còn được liệt kê — phép dò thư mục lại gõ cứng nhóm?\n" +
      `  thấy: ${apps.join(", ")}`,
  );

  const groups = new Set(apps.map((a) => a.split("/")[0]));
  assert.ok(
    groups.size >= 3,
    `chỉ thấy ${[...groups].join(", ")} — một app ngoài các nhóm quen thuộc sẽ vô hình như #3133`,
  );
});

// ───────────────────────────────────────────────────────────────────────────
// RÀO CHO RÀO (#3135) — cả hai chiều, trên DỮ LIỆU TỔNG HỢP.
//
// Không sửa workflow thật để đo: file thật đang canh production, và một bài
// test không có tư cách đụng vào nó (cùng luật với `test:gate-paths`).
// ───────────────────────────────────────────────────────────────────────────

/** Hình dạng #3135: tên app có mặt, nhưng CHỈ trong các bước build. */
const BUILD_ONLY = `
name: fake-release
on:
  push:
    paths:
      - 'fake/app/**'
defaults:
  run:
    working-directory: fake
jobs:
  build-and-publish:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/setup-node@v4
        with:
          cache-dependency-path: fake/app/pnpm-lock.yaml
      - name: Build the frontend
        working-directory: fake/app
        run: |
          pnpm install --frozen-lockfile
          pnpm build
      - name: go test
        run: go test -count=1 ./...
`;

test("#3135 KÊU: app chỉ được NHẮC TỚI trong workflow chỉ-build ⇒ không có cổng", () => {
  const dirs = testStepsByDir([{ file: "fake-release.yml", text: BUILD_ONLY }]);

  assert.equal(
    dirs.has("fake/app"),
    false,
    "rào vẫn coi `fake/app` là có cổng — nhưng bước duy nhất chạy ở đó chỉ `pnpm build`.\n" +
      `  bằng chứng thấy được: ${JSON.stringify([...dirs.keys()])}`,
  );

  // Chính hai phép đo đã trượt, ghim lại để không ai "đơn giản hoá" về chúng.
  assert.ok(
    BUILD_ONLY.includes("fake/app"),
    "phép cũ (so chuỗi con) phải XANH trên dữ liệu này — nếu không, bài đã hết tái hiện được bug",
  );
  assert.ok(
    dirs.has("fake"),
    "phép trung gian (cùng JOB có bước chạy test) cũng phải xanh: `go test` nằm cùng job, " +
      "chỉ khác BƯỚC — đó là chỗ nó thất bại",
  );
});

test("#3135 IM: thêm một bước chạy test của đúng app đó ⇒ có cổng", () => {
  const withTest =
    BUILD_ONLY +
    `      - name: test
        working-directory: fake/app
        run: pnpm test
`;

  const dirs = testStepsByDir([{ file: "fake-release.yml", text: withTest }]);

  assert.ok(
    dirs.has("fake/app"),
    "thêm đúng bước chạy test mà rào vẫn nói không có cổng — rào này sẽ báo oan.\n" +
      `  bằng chứng thấy được: ${JSON.stringify([...dirs.keys()])}`,
  );
});

test("#3135 IM: `cd <app> && npm test` từ gốc repo cũng là một cổng", () => {
  // Mọi script của root `package.json` viết theo hình dạng này
  // (`"lint:admin": "cd web/admin && pnpm lint"`), nên một workflow gọi chúng
  // phải được tính. Bỏ sót ⇒ báo oan hàng loạt.
  const dirs = testStepsByDir([
    {
      file: "fake-root.yml",
      text: `
name: fake-root
on: { push: {} }
jobs:
  a:
    runs-on: ubuntu-latest
    steps:
      - name: test
        run: cd fake/app && npm test
`,
    },
  ]);

  assert.ok(dirs.has("fake/app"), `thấy: ${JSON.stringify([...dirs.keys()])}`);
});

test("#3135 IM: matrix phải khai triển được — không thì bốn app bị báo oan", () => {
  // `web-apps.yml` chạy bốn app qua MỘT bước `run:`. Rào không khai triển được
  // matrix sẽ tuyên bố cả bốn là không có cổng, và **rào kêu oan thì bị TẮT**.
  const dirs = testStepsByDir([
    {
      file: "fake-matrix.yml",
      text: `
name: fake-matrix
on: { push: {} }
jobs:
  check:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        include:
          - app: fake/one
            test: 'test:coverage'
          - app: fake/two
            test: 'test'
    defaults:
      run:
        working-directory: \${{ matrix.app }}
    steps:
      - name: typecheck
        run: pnpm exec tsc
      - name: test
        run: pnpm \${{ matrix.test }}
`,
    },
  ]);

  assert.deepEqual([...dirs.keys()].sort(), ["fake/one", "fake/two"]);
});

test("#3135 IM: bước KHÔNG phải test không được tính là cổng", () => {
  // Ba lệnh có thật trong `.github/workflows/` hôm nay. Nhận nhầm bất kỳ cái nào
  // là dựng lại đúng lỗ #3135 dưới tên khác.
  const dirs = testStepsByDir([
    {
      file: "fake-nontest.yml",
      text: `
name: fake-nontest
on: { push: {} }
jobs:
  a:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: fake/app
    steps:
      - run: pnpm install --frozen-lockfile
      - run: pnpm exec tsc
      - run: pnpm check:api-manifest
      - run: npm run typecheck
      - run: pnpm build
`,
    },
  ]);

  assert.deepEqual([...dirs.keys()], [], "một lệnh không phải test đã được tính là cổng");
});

test("#3135 rào có mẫu số — app đang có cổng thật vẫn xanh trên cây THẬT", () => {
  // Chiều chống-báo-oan, đo trên workflow thật. Bốn app matrix + `app/pos` (job
  // `native`) + `workstation/frontend` (job riêng) = 6. Con số tụt xuống nghĩa
  // là phép đọc đã hỏng, không phải repo teo lại — và lúc đó bài chính ở trên sẽ
  // đỏ hàng loạt vì lý do sai.
  const gated = appDirs()
    .filter((a) => a.hasTest && testSteps.has(a.app))
    .map((a) => a.app);

  assert.ok(
    gated.length >= 6,
    `chỉ ${gated.length} app có cổng đo được (${gated.join(", ")}) — phép đọc workflow hỏng?`,
  );
});
