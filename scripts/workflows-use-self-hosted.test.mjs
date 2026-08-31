/**
 * Runner tính tiền phải được KHAI, không được lẻn vào (#3001).
 *
 * Repo này chạy CI trên runner tự dựng, và cho tới #3001 thì `web-apps.yml` là
 * workflow duy nhất còn dùng `ubuntu-latest` — với matrix bốn app, tức bốn job
 * tính tiền mỗi lượt, và chạy hai lượt cho cùng một thay đổi (`pull_request`
 * rồi `push`). Đo ngày 2026-08-16: ~140 job GitHub-hosted trong MỘT ngày, cho
 * tới khi budget chặn lại.
 *
 * Cách nó chặn là phần đáng ghi: bốn job đỏ sau 3–4 giây với **0 step, không
 * một dòng log**. Không có gì trong giao diện nói lý do; sự thật chỉ nằm ở
 * annotation (`The job was not started because an Actions budget is preventing
 * further use.`). Một người không biết chuyện sẽ đi tìm lỗi trong `web/`.
 *
 * Nên rào này không cấm `ubuntu-latest` — đôi khi nó đúng. Nó bắt việc KHAI:
 * thêm một workflow dùng runner tính tiền thì phải thêm tên nó vào danh sách
 * dưới kèm lý do, và lúc đó có người đọc. Im lặng mới là thứ bị chặn.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const workflowDir = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  ".github/workflows",
);

/**
 * Workflow được phép dùng runner GitHub-hosted, kèm lý do đo được.
 *
 * Đừng thêm vào đây để làm test xanh. Câu hỏi phải trả lời trước là: vì sao
 * runner tự dựng KHÔNG chạy được việc này?
 */
const BILLED_RUNNER_ALLOWED = {
  // Chỉ chạy bằng tay (`workflow_dispatch`) trong tình huống khôi phục, và
  // đúng lúc đó runner tự dựng có thể chính là thứ đang hỏng.
  "workstation-manifest-restore.yml": "workflow_dispatch, dùng khi khôi phục",
};

test("#3001 không workflow nào lặng lẽ dùng runner tính tiền", () => {
  const offenders = [];

  for (const file of readdirSync(workflowDir)) {
    if (!file.endsWith(".yml") && !file.endsWith(".yaml")) {
      continue;
    }

    const body = readFileSync(join(workflowDir, file), "utf8");

    // Chỉ xét dòng `runs-on:` thật, không xét khi nó xuất hiện trong comment
    // — chính tài liệu giải thích luật này có nhắc `ubuntu-latest` trong văn
    // xuôi, và một rào tự tính bình luận thành vi phạm là rào sẽ bị tắt.
    const usesBilledRunner = body
      .split("\n")
      .filter((line) => !line.trimStart().startsWith("#"))
      .some((line) => /^\s*runs-on:\s*ubuntu-/.test(line));

    if (usesBilledRunner && !(file in BILLED_RUNNER_ALLOWED)) {
      offenders.push(file);
    }
  }

  assert.deepEqual(
    offenders,
    [],
    `Workflow dùng runner GitHub-hosted mà chưa khai: ${offenders.join(", ")}.\n` +
      "Runner tự dựng chạy được Node/pnpm (xem omnify-gate + web-apps). Nếu thật sự\n" +
      "cần runner tính tiền thì khai vào BILLED_RUNNER_ALLOWED kèm lý do đo được —\n" +
      "budget cạn làm job đỏ sau 3 giây với 0 step và KHÔNG log, nên chi phí thật\n" +
      "của việc này không phải tiền mà là một buổi đi tìm lỗi ở nhầm chỗ.",
  );
});

test("#3001 web-apps giữ cả hai trigger — pull_request VÀ push", () => {
  const body = readFileSync(join(workflowDir, "web-apps.yml"), "utf8");
  const on = body.slice(body.indexOf("\non:"), body.indexOf("\nconcurrency:"));

  assert.match(
    on,
    /pull_request:/,
    "mất trigger pull_request — PR sẽ merge mà không app web nào được kiểm",
  );
  assert.match(
    on,
    /push:/,
    "Mất trigger `push` nghĩa là commit ĐÃ MERGE không còn bằng chứng nào của\n" +
      "riêng nó. Chi phí từng là lý do duy nhất để bỏ nó, và từ #3001 workflow\n" +
      "chạy self-hosted nên lý do đó không còn.",
  );
});
