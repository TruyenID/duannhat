import assert from "node:assert/strict";

import { pathsCover } from "./lib/workflow-paths.mjs";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { parse } from "yaml";

/**
 * #2778 — job `pest` phải chạy SONG SONG.
 *
 * `paratest` nằm sẵn trong `vendor/` từ lâu; thiếu đúng một cờ. Đo trên máy 10
 * nhân: tuần tự 752–860s, `--parallel` **105s** với 9697 test cùng xanh.
 *
 * Vì sao đáng ghim bằng test chứ không chỉ sửa rồi thôi: deploy CHỜ workflow này
 * (`deploy-xserver.yml` dùng `workflow_run: [backend-tests]`). Ở 13–14 phút cộng
 * tới 27 phút xếp hàng, suite trên `dev` gần như không bao giờ kịp xong — đã đo
 * BỐN lượt liên tiếp bị huỷ vì merge kế tiếp landing trước, khiến phần lớn commit
 * trên `dev` không có bằng chứng xanh nào và PR promote thừa hưởng check bị huỷ.
 *
 * Gỡ cờ này ra thì mọi test vẫn xanh, deploy vẫn chạy, và không gì báo động —
 * chỉ chậm lại gấp bảy lần rồi từ từ quay về chuỗi huỷ. Đúng lớp lỗi mà #2557 và
 * #2660 đã trả giá: cổng ngừng canh mà không ai thấy.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const WORKFLOW = ".github/workflows/backend-tests.yml";

const wf = parse(readFileSync(join(root, WORKFLOW), "utf8"));

/** Mọi lệnh `run` của một job, gộp thành một chuỗi. */
function runsOf(jobName) {
  const job = wf.jobs?.[jobName];
  assert.ok(job, `${WORKFLOW} không còn job \`${jobName}\` — bố cục đã đổi, đọc lại rào này`);
  return (job.steps ?? []).map((s) => s.run ?? "").join("\n");
}

test("job pest chạy với --parallel", () => {
  const runs = runsOf("pest");

  assert.match(
    runs,
    /vendor\/bin\/pest[^\n]*--parallel/,
    "Job `pest` không còn `--parallel`. Suite quay về tuần tự: 13–14 phút thay vì " +
      "~2, và deploy chờ nó (`workflow_run: [backend-tests]`). Ở tốc độ đó các " +
      "lượt trên `dev` bị merge kế tiếp huỷ trước khi kịp xong (#2778).",
  );
});

/**
 * `flake-hunt` CỐ Ý đứng ngoài, và lý do phải nêu ra chứ không im lặng bỏ qua:
 * nó chạy `--order-by=random` để tìm test phụ thuộc thứ tự (#2753 bắt được một
 * cái theo đúng đường này). Chạy song song thì thứ tự thực thi bị chia cho nhiều
 * process, nên phép thử thứ tự mất nghĩa — hai cơ chế đo hai thứ khác nhau.
 *
 * Nó là job NIGHTLY nên 13 phút ở đó không nằm trên đường deploy.
 */
test("flake-hunt KHÔNG chạy song song — nó đo thứ tự, song song làm hỏng phép đo", () => {
  const runs = runsOf("flake-hunt");

  assert.match(runs, /--order-by=random/, "flake-hunt phải giữ `--order-by=random`");
  assert.doesNotMatch(
    runs,
    /--parallel/,
    "flake-hunt KHÔNG được chạy song song: nó tồn tại để tìm test phụ thuộc thứ " +
      "tự, mà song song chia thứ tự cho nhiều process nên phép đo mất nghĩa.",
  );
});

/**
 * Rào phải biết KÊU chứ không chỉ biết IM: nếu tên job đổi, hai bài trên sẽ ném
 * ở `runsOf()` — tốt. Nhưng nếu job còn đó mà KHÔNG còn lệnh pest nào, chúng sẽ
 * so một chuỗi rỗng và im lặng. Ghim mẫu số.
 */
test("cả hai job thật sự còn gọi pest", () => {
  for (const job of ["pest", "flake-hunt"]) {
    assert.match(
      runsOf(job),
      /vendor\/bin\/pest/,
      `Job \`${job}\` không còn gọi pest — hoặc cách chạy test đã đổi, hoặc rào này ` +
        "đã mục. Đọc lại thay vì tin nó đang canh.",
    );
  }
});

/**
 * #2778 (vòng review 1) — MỘT RÀO KHÔNG CHẠY ĐƯỢC THÌ KHÔNG PHẢI RÀO.
 *
 * Hai bài trên sống trong job `omnify-gate`, mà workflow đó **lọc theo path**.
 * Bản đầu của PR này không có `backend-tests.yml` trong danh sách lọc, nên kịch
 * bản mà rào sinh ra để chặn — một PR sửa ĐÚNG `backend-tests.yml` để gỡ
 * `--parallel` — lại là kịch bản KHÔNG kích hoạt được nó. Rào im, cờ mất, suite
 * lặng lẽ quay về tuần tự.
 *
 * Cùng lỗ với rào anh em `test:backend-tests-ci` (#2557), nên bài này ghim cho
 * cả hai: sửa file nào thì rào canh file đó phải chạy được.
 */
test("omnify-gate kích hoạt được trên chính file nó canh", () => {
  const gate = parse(readFileSync(join(root, ".github/workflows/omnify-gate.yml"), "utf8"));
  const on = gate.on ?? gate[true]; // `on:` parse thành boolean true trong YAML 1.1

  for (const event of ["push", "pull_request"]) {
    const paths = on?.[event]?.paths ?? [];
    assert.ok(
      pathsCover(paths, WORKFLOW),
      `omnify-gate.${event}.paths thiếu \`${WORKFLOW}\`. Rào \`--parallel\` sống ` +
        "trong workflow đó, nên một PR chỉ sửa file này sẽ KHÔNG chạy rào — đúng " +
        "kịch bản rào sinh ra để chặn (#2778).",
    );
  }
});
