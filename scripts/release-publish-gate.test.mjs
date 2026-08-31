import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { parse } from "yaml";

/**
 * #2679 — mọi bước GHI RA PRODUCTION phải nằm dưới một job có chắn ref.
 *
 * `workstation-release.yml` khai `push: branches: [main]`, và đọc lên thì tưởng
 * chỉ `main` mới phát hành được. Nhưng nó cũng khai `workflow_dispatch:`, mà
 * dispatch chạy được từ BẤT KỲ nhánh nào chứa file workflow — `dev`, mọi
 * `issue-*`. `push:` chỉ chắn đường push.
 *
 * Trước rào này, trên đường ghi ra production không bước nào kiểm ref:
 * `detect-changes` quyết thuần theo tag/thay đổi file/`force`, job `release`
 * chỉ hỏi `release == 'true'`, và bước `Rsync workstation downloads to XServer`
 * KHÔNG có `if:` nào cả. Chắn ref duy nhất từng tồn tại nằm ở bước `Publish
 * GitHub Release` — tức thứ ĐƯỢC bảo vệ là GitHub Release, còn thứ KHÔNG được
 * bảo vệ là trang tải mà quán thật sự vào lấy bản cài.
 *
 * Rào kiểm ở tầng JOB chứ không tầng bước: một bước ghi mới thêm vào sau này
 * thừa hưởng chắn của job thay vì phải nhớ tự thêm `if:` — và đó chính là kiểu
 * quên đã tạo ra lỗ này.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

const WORKFLOW = ".github/workflows/workstation-release.yml";

/**
 * Dấu hiệu một bước ghi ra ngoài repo. Cố ý bắt theo ĐÍCH ĐẾN (host, lệnh đồng
 * bộ, lệnh tạo release) chứ không theo tên bước — tên đổi tự do, đích đến thì
 * không.
 */
const WRITES_OUTWARD = [
  /\brsync\b/,
  /\bgh release create\b/,
  /X_SERVER_SSH_KEY/,
  // Phải là LỜI GỌI có tham số, không phải chỗ NHẮC TÊN. `detect-changes` liệt
  // kê chính đường dẫn script này trong mảng `PATHS=(...)` để dò thay đổi — đó
  // là một phép ĐỌC, và bản đầu của rào này đã báo oan nó. Một rào kêu oan
  // không bị tranh luận, nó bị TẮT.
  /publish-workstation-downloads\.sh[ \t]+\S/,
];

/** Một biểu thức `if:` được coi là chắn ref nếu nó đòi `main` hoặc đòi tag. */
function gatesOnRef(expr) {
  if (typeof expr !== "string") return false;
  return (
    /github\.ref\s*==\s*'refs\/heads\/main'/.test(expr) ||
    /github\.ref_type\s*==\s*'tag'/.test(expr)
  );
}

const workflow = parse(readFileSync(join(root, WORKFLOW), "utf8"));

test("workflow phát hành vẫn cho dispatch (rào này không được đóng cửa đó lại)", () => {
  assert.ok(
    workflow.on?.workflow_dispatch !== undefined,
    "`workflow_dispatch` là thứ cho phép thử BUILD mà không phát hành — giữ nó. " +
      "Cái phải chắn là job `release`, không phải cái trigger.",
  );
});

test("mọi bước ghi ra production nằm dưới job có chắn ref", () => {
  const offenders = [];

  for (const [jobName, job] of Object.entries(workflow.jobs ?? {})) {
    const jobGated = gatesOnRef(job.if);

    for (const step of job.steps ?? []) {
      const body = [step.run ?? "", JSON.stringify(step.with ?? {})].join("\n");
      const marker = WRITES_OUTWARD.find((re) => re.test(body));
      if (!marker) continue;

      // Bước tự chắn cũng được chấp nhận, nhưng job chắn mới là thứ bền.
      if (jobGated || gatesOnRef(step.if)) continue;

      offenders.push(`${jobName} › ${step.name ?? "(không tên)"} — khớp ${marker}`);
    }
  }

  assert.deepEqual(
    offenders,
    [],
    "Các bước sau ghi ra ngoài repo mà không có chắn ref nào:\n" +
      offenders.map((o) => `  - ${o}`).join("\n") +
      "\nDispatch từ `dev` hay một nhánh `issue-*` sẽ chạy chúng và đẩy build " +
      "chưa merge lên trang tải production (#2679). Chắn job bằng " +
      "`github.ref == 'refs/heads/main' || github.ref_type == 'tag'`.",
  );
});

/**
 * Rào phải biết KÊU chứ không chỉ biết IM: nếu bộ dò `WRITES_OUTWARD` không còn
 * khớp gì (bước bị đổi tên lệnh, file tách ra script khác), test trên sẽ xanh
 * vĩnh viễn vì nó không tìm thấy gì để kiểm. Đó là kiểu hỏng im lặng đúng bằng
 * cái lỗ nó đang canh.
 */
test("bộ dò thật sự tìm thấy bước ghi ra production", () => {
  const found = [];

  for (const [jobName, job] of Object.entries(workflow.jobs ?? {})) {
    for (const step of job.steps ?? []) {
      const body = [step.run ?? "", JSON.stringify(step.with ?? {})].join("\n");
      if (WRITES_OUTWARD.some((re) => re.test(body))) {
        found.push(`${jobName} › ${step.name ?? "(không tên)"}`);
      }
    }
  }

  assert.ok(
    found.length > 0,
    `Không dò ra bước ghi ra production nào trong ${WORKFLOW}. Hoặc workflow đã ` +
      "đổi cách phát hành, hoặc `WRITES_OUTWARD` đã mục — đọc lại rào này thay " +
      "vì tin rằng nó đang canh.",
  );
});
