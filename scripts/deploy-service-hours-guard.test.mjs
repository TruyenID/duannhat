/**
 * Rào giờ phục vụ của đường deploy production phải CÒN ĐÓ, và phải rào đúng
 * đường.
 *
 * Vì sao cần một bài test cho một bước YAML: bước này chỉ chạy đúng vào lúc
 * người ta bực nhất — khi ai đó vừa merge và muốn thấy nó lên ngay. Một bước
 * "phiền" mà không có gì canh sẽ bị gỡ trong một commit "dọn CI", và cái mất đi
 * thì không ai thấy cho tới lần deploy giữa ca trưa kế tiếp.
 *
 * Bối cảnh: repo private ở gói free KHÔNG có branch protection lẫn ruleset
 * (`/branches/main/protection` và `/rulesets` đều trả 403 "Upgrade to Pro"),
 * nên không có cách nào chặn ở tầng merge. Và kể cả có, branch protection cũng
 * không có luật theo giờ. Đây là chỗ DUY NHẤT rào được, vì nó nằm trong repo.
 *
 * Bài này kiểm BỐN điều, và điều thứ tư mới là điều dễ mất nhất:
 *
 *  1. bước rào tồn tại trong job deploy;
 *  2. nó chặn cửa sổ 09:00–23:00 JST;
 *  3. nó `exit 1` — chặn phải FAIL TO, vì một deploy bị bỏ qua trong im lặng
 *     sẽ bị đọc thành "đã ship";
 *  4. nó chỉ áp cho đường TỰ ĐỘNG (`workflow_run`). Nếu ai đó bỏ điều kiện
 *     `if:` này, rào sẽ chặn luôn `workflow_dispatch` — tức chặn đúng cái nút
 *     mà thông điệp lỗi bảo người ta bấm, và biến một rào an toàn thành một
 *     quán không deploy được lúc đang có sự cố.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), "..");
const workflowPath = join(
  repoRoot,
  ".github/workflows/deploy-xserver.yml",
);
const workflow = readFileSync(workflowPath, "utf8");

test("rào giờ phục vụ còn trong đường deploy", () => {
  assert.match(
    workflow,
    /Rào giờ phục vụ \(deploy tự động chỉ 23:00–09:00 JST\)/,
    "Bước rào giờ đã biến mất khỏi deploy-xserver.yml. Nếu đây là chủ ý thì " +
      "sửa bài test này CÙNG LƯỢT và ghi lý do — đừng để nó rơi im lặng.",
  );
});

test("cửa sổ chặn đúng là 09:00–23:00 JST", () => {
  assert.match(
    workflow,
    /TZ=Asia\/Tokyo date \+%-H/,
    "Giờ phải đọc theo Asia/Tokyo. Đồng hồ runner là UTC, và lệch 9 tiếng " +
      "biến rào này thành thứ chặn nhầm ca — xem CLAUDE.md § business time.",
  );
  assert.match(
    workflow,
    /\[ "\$hour" -ge 9 \] && \[ "\$hour" -lt 23 \]/,
    "Cửa sổ cho deploy tự động là 23:00–09:00 JST, tức chặn khi 9 ≤ giờ < 23.",
  );
});

test("bị chặn thì FAIL, không phải bỏ qua trong im lặng", () => {
  const guard = workflow.slice(
    workflow.indexOf("Rào giờ phục vụ (deploy"),
    workflow.indexOf("- name: Checkout"),
  );

  assert.ok(guard.length > 0, "không cắt được khối rào để kiểm");
  assert.match(
    guard,
    /exit 1/,
    "Rào phải exit 1. Một deploy bị bỏ qua mà workflow vẫn xanh sẽ bị đọc " +
      "thành 'đã ship' — và `main` thì đã tiến rồi.",
  );
});

test("chỉ rào đường TỰ ĐỘNG — workflow_dispatch phải đi qua", () => {
  const guard = workflow.slice(
    workflow.indexOf("Rào giờ phục vụ (deploy"),
    workflow.indexOf("- name: Checkout"),
  );

  assert.match(
    guard,
    /if: github\.event_name == 'workflow_run'/,
    "Thiếu điều kiện này thì rào chặn cả `workflow_dispatch` — đúng cái nút " +
      "mà thông điệp lỗi bảo người vận hành bấm để deploy sau giờ đóng cửa, " +
      "và cũng là đường vá sự cố khẩn. Rào khi đó tự mâu thuẫn.",
  );
});
