/**
 * Mọi buildspec trong repo phải có một workflow NẠP nó lên Amplify.
 *
 * Amplify chỉ đọc `amplify.yml` ở gốc app, hoặc bản lưu trong console của nó.
 * Một file dưới `.github/amplify/` KHÔNG tự tới đâu cả — nên nếu không workflow
 * nào chạy `update-app --build-spec` với nó, sửa file đó không đổi một byte nào
 * của lượt build.
 *
 * Cái giá đã trả, đo 2026-08-18: sửa `admin-buildspec.yml` để nướng
 * `TEMPO_BACKEND_URL` vào `.env.production`. CI xanh. Deploy xanh. Trang vẫn
 * **500** — vì lệnh mới chưa bao giờ chạy. Mất ba vòng sửa–deploy–đo mới nhận ra,
 * và trong ba vòng đó tôi đã đi tìm nguyên nhân ở tầng AWS, nơi không sửa được.
 *
 * Vì sao nó qua mắt được: cả ba buildspec đều nằm trong `paths:` của workflow
 * tương ứng, nên mọi PR chạm chúng đều kích CI. Bảng checks xanh đọc y hệt
 * "thay đổi đã có hiệu lực".
 *
 * Một file cấu hình không ai đọc còn TỆ HƠN không có file: nó trả lời "đã cấu
 * hình" cho câu hỏi "chỗ này đã được cấu hình chưa".
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const SPEC_DIR = join(root, ".github/amplify");
const WORKFLOW_DIR = join(root, ".github/workflows");

function buildspecs() {
  if (!existsSync(SPEC_DIR)) return [];
  return readdirSync(SPEC_DIR).filter((f) => f.endsWith("-buildspec.yml"));
}

function workflowBodies() {
  return readdirSync(WORKFLOW_DIR)
    .filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))
    .map((f) => readFileSync(join(WORKFLOW_DIR, f), "utf8"))
    .join("\n");
}

test("CÓ buildspec để mà hỏi — nếu không, bài này đang đo khoảng không", () => {
  // Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào thuộc
  // diện được hỏi". Đổi bố cục thư mục thì bài này phải ĐỎ, không được im.
  assert.ok(
    buildspecs().length >= 3,
    `chỉ thấy ${buildspecs().length} buildspec dưới .github/amplify/ — bố cục đã đổi, sửa bài test chứ đừng xoá`,
  );
});

test("#3229 mọi buildspec đều được một workflow NẠP lên Amplify", () => {
  const bodies = workflowBodies();

  const orphans = buildspecs().filter(
    (spec) => !bodies.includes(`--build-spec "$(cat .github/amplify/${spec})"`),
  );

  assert.deepEqual(
    orphans,
    [],
    "Buildspec KHÔNG có workflow nào nạp lên Amplify:\n  " +
      orphans.join("\n  ") +
      "\n\nAmplify đọc bản trong console, nên sửa những file này không đổi gì cả — " +
      "và CI vẫn xanh, nên không có dấu hiệu nào. Thêm một bước " +
      "`aws amplify update-app --build-spec` vào workflow của app đó.",
  );
});

test("#3229 bước nạp phải PHÂN BIỆT thiếu quyền với lỗi khác", () => {
  // `|| true` nuốt cả hai. Thiếu quyền là trạng thái đã biết, cần một người xử
  // một lần; mọi lỗi khác nghĩa là điều kiện đã đổi và phải dừng lại.
  const missing = readdirSync(WORKFLOW_DIR)
    .filter((f) => readFileSync(join(WORKFLOW_DIR, f), "utf8").includes("--build-spec"))
    .filter((f) => {
      const body = readFileSync(join(WORKFLOW_DIR, f), "utf8");
      return !body.includes("AccessDeniedException") || !body.includes("exit 1");
    });

  assert.deepEqual(
    missing,
    [],
    "Workflow nạp buildspec mà không tách `AccessDeniedException` khỏi lỗi khác:\n  " +
      missing.join("\n  "),
  );
});

/**
 * Buildspec phải PARSE được — Amplify parse YAML lúc CHẠY, không phải lúc nạp.
 *
 * Cái giá đã trả, đo 2026-08-18 ngay sau khi #3229 nạp buildspec thật vào
 * Amplify: dòng `node -e "...JSON.stringify({commit: process.env...})"` là
 * plain scalar chứa `: ` — YAML không nuốt được, nhưng lỗi chỉ phát nổ TRONG
 * job Amplify ("bad indentation of a mapping entry"), sau khi CI xanh và
 * `update-app` thành công. Cả BA app web production đỏ cùng một dòng, và
 * suốt thời gian file còn là file chết thì lỗi ngủ yên — chính việc nạp nó
 * lên mới kích hoạt quả mìn.
 *
 * Lệnh có `{key: value}` trong buildspec phải bọc block scalar (`>-`).
 */
test("mọi buildspec (và customHttp.yml) phải là YAML hợp lệ", async () => {
  const { parse } = await import("yaml");
  const custom = execFileSync("git", ["ls-files", "*customHttp.yml"], {
    cwd: root,
    encoding: "utf8",
  })
    .split("\n")
    .filter(Boolean);

  const broken = [];
  for (const f of [...buildspecs().map((s) => join(".github/amplify", s)), ...custom]) {
    try {
      parse(readFileSync(join(root, f), "utf8"));
    } catch (e) {
      broken.push(`${f}: ${e.message.split("\n")[0]}`);
    }
  }

  assert.deepEqual(
    broken,
    [],
    "File Amplify không parse được — sẽ đỏ TRONG job Amplify chứ không phải ở CI:\n  " +
      broken.join("\n  "),
  );
});

/**
 * #3233 — cùng một lớp lỗi, một loại file khác.
 *
 * `web/pos/customHttp.yml` khai `frame-ancestors 'none'` cho POS suốt nhiều
 * tháng, và header đó chưa bao giờ tồn tại trên đường thật. File đúng tên, đúng
 * định dạng, đúng ý — chỉ không ai nạp nó. Rào ở trên KHÔNG bắt được vì nó chỉ
 * biết về `*-buildspec.yml`.
 *
 * Bài học rộng hơn cả hai lần: một file cấu hình chỉ có nghĩa khi có đường đưa
 * nó tới nơi thi hành. Ở đây "nơi thi hành" là Amplify, và đường duy nhất là
 * `update-app`.
 */
test("#3233 mọi customHttp.yml đều được một workflow NẠP lên Amplify", () => {
  const specs = execFileSync("git", ["ls-files", "*customHttp.yml"], {
    cwd: root,
    encoding: "utf8",
  })
    .split("\n")
    .filter(Boolean);

  // Không có file nào là hợp lệ (chưa app nào cần header riêng) — nhưng nói ra,
  // đừng để bài im lặng trôi qua như thể đã kiểm.
  if (specs.length === 0) {
    console.log("  (chưa app nào khai customHttp.yml)");
    return;
  }

  const bodies = workflowBodies();
  const orphans = specs.filter(
    (f) => !bodies.includes(`--custom-headers "$(cat ${f})"`),
  );

  assert.deepEqual(
    orphans,
    [],
    "customHttp.yml KHÔNG có workflow nào nạp:\n  " +
      orphans.join("\n  ") +
      "\n\nAmplify dùng cấu hình trong console, nên file này không có hiệu lực — " +
      "và repo thì đọc như đã bảo vệ. Thêm bước `aws amplify update-app --custom-headers`.",
  );
});
