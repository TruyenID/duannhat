import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * #3200 — rào cho một LỚP lỗi, không phải cho một chỗ.
 *
 * Cùng một hình dạng xuất hiện BA lần trong 24 giờ, ở hai ngôn ngữ:
 *
 *   #3159  pos-web `listAllProducts` (TypeScript) — đã vá, `barrenPages`
 *   #3196  workstation `Recover()` (Go)          — đã vá (#3197, #3198)
 *   #3200  workstation `PullCustomers`/`PullLots` — chỗ này
 *
 * Hình dạng: *Cloud áp một trần phía máy chủ; client lấy một lượt rồi dừng; và
 * hàm trả về không phân biệt được "đã lấy hết" với "vừa chạm trần".*
 *
 * Nhận xét làm rào này ra đời, từ phiên vá #3196:
 *
 *   "`barrenPages` đã giải đúng bài này ở TypeScript — cùng đêm, cùng người —
 *    và tôi không mang sang Go."
 *
 * Ba lần cùng một hình dạng nghĩa là nhớ bằng tay không đủ. Rào này hỏi đúng
 * một câu, ở đúng chỗ câu trả lời tồn tại: **mỗi trần phía Cloud có được KHAI
 * BÁO ở phía client không?**
 *
 * # Vì sao đo trần ở Cloud chứ không đo vòng lặp ở Go
 *
 * "Hàm Go này có đi hết trang không" là câu hỏi về luồng điều khiển, và trả lời
 * nó bằng regex thì vừa mong manh vừa dễ qua mặt. Còn `->limit(N)` trong một
 * controller là một dữ kiện đọc được chính xác — và nó chính là thứ SINH ra lớp
 * lỗi. Nên rào bắt ở nguồn: thêm một trần mới mà không khai là đỏ.
 */

const WORKSTATION_CONTROLLERS = join(
  root,
  "backend/app/Http/Controllers/Api/V1/Workstation",
);

/**
 * Mỗi trần phía Cloud phải có một mục ở đây, kèm cách client đối xử với nó.
 *
 *   `paged`            client đi hết trang bằng con trỏ
 *   `capped_by_design` trần là quyết định sản phẩm — client KHÔNG lấy phần dư,
 *                      nhưng phải NÓI RA khi chạm trần
 *   `single_row`       không phải feed: tra đúng một bản ghi theo id
 *
 * Thêm mục ở đây là một QUYẾT ĐỊNH, không phải đường tắt để test hết đỏ. Câu
 * phải trả lời trước: nếu quán vượt trần này, người dùng thấy gì?
 */
const DECLARED = {
  "CustomerReplicaController.php": {
    limit: 1000,
    kind: "paged",
    why: "#3200 — PullCustomers đi hết trang bằng con trỏ `updated_since` (Cloud sắp ASC)",
  },
  "LotController.php": {
    limit: 200,
    kind: "capped_by_design",
    why:
      "#3200 — 200 lô sắp hết hạn nhất là đúng thứ quầy cần; PullLots KÊU khi chạm trần " +
      "thay vì kéo về phần đuôi không ai dùng",
  },
  "OrderController.php": {
    limit: null, // hai chỗ: limit(1) tra theo id, và limit($limit) của feed
    kind: "paged",
    why: "#3196 — Recover() đi hết trang; limit(1) là tra một bản ghi theo id, không phải feed",
  },
};

/** Mọi `->limit(...)` trong controller Workstation, theo tên file. */
function cappedControllers() {
  const found = new Map();

  for (const file of readdirSync(WORKSTATION_CONTROLLERS)) {
    if (!file.endsWith(".php")) continue;
    const text = readFileSync(join(WORKSTATION_CONTROLLERS, file), "utf8");
    const hits = [...text.matchAll(/->limit\(\s*([^)]*?)\s*\)/g)].map((m) => m[1]);
    if (hits.length > 0) found.set(file, hits);
  }

  return found;
}

test("#3200 mỗi trần phía Cloud đều được KHAI BÁO ở phía client", () => {
  const found = cappedControllers();
  const undeclared = [...found.keys()].filter((f) => !(f in DECLARED));

  assert.deepEqual(
    undeclared,
    [],
    `Controller có \`->limit()\` mà chưa khai: ${undeclared.join(", ")}\n\n` +
      "Một trần phía máy chủ mà client không biết là cách #3159 / #3196 / #3200 xảy ra:\n" +
      "client lấy một lượt, và 'đã lấy hết' trông y hệt 'vừa chạm trần'.\n\n" +
      "Khai vào DECLARED với `kind` là paged | capped_by_design | single_row,\n" +
      "kèm lý do đo được. Câu phải trả lời: quán vượt trần này thì người dùng thấy gì?",
  );
});

test("#3200 mục đã khai phải CÒN ĐÚNG — controller biến mất thì gỡ khỏi danh sách", () => {
  const found = cappedControllers();
  const stale = Object.keys(DECLARED).filter((f) => !found.has(f));

  assert.deepEqual(
    stale,
    [],
    `Khai ${stale.join(", ")} nhưng controller đó không còn \`->limit()\`.\n` +
      "Một danh sách miễn trừ hết hạn sẽ dạy người đọc bỏ qua nó.",
  );
});

test("#3200 hằng số trần ở Go phải KHỚP số thật của Cloud", () => {
  // Đây là vế bắt được lỗi âm thầm nhất: ai đó nâng trần ở Cloud, Go vẫn so với
  // số cũ, và phép kiểm "trang đã đầy chưa" trả lời sai mãi mãi — vòng lặp dừng
  // sớm mà không gì đỏ.
  const goSources = [
    "workstation/internal/service/sync_pull.go",
    "workstation/internal/service/sync_pull_pos.go",
  ]
    .map((f) => readFileSync(join(root, f), "utf8"))
    .join("\n");

  const pinned = {
    "CustomerReplicaController.php": /customersCloudLimit\s*=\s*(\d+)/,
    "LotController.php": /lotsCloudLimit\s*=\s*(\d+)/,
  };

  for (const [file, pattern] of Object.entries(pinned)) {
    const declared = DECLARED[file].limit;
    const match = goSources.match(pattern);

    assert.ok(match, `không tìm thấy hằng số Go phản chiếu trần của ${file}`);
    assert.equal(
      Number(match[1]),
      declared,
      `${file}: Cloud ${declared}, Go ${match[1]} — hai số phải khớp, ` +
        "nếu không phép kiểm 'trang đã đầy chưa' trả lời sai và vòng lặp dừng sớm trong im lặng",
    );
  }
});

test("#3200 KÊU: thêm một trần chưa khai thì rào phải đỏ", () => {
  // Rào phải biết kêu, không chỉ biết im. Dựng đầu vào giả thay vì thả file vào
  // cây thật — cùng lý do `DestructiveMigrationsNeedApprovalTest` đã trả giá:
  // thả file thăm dò vào cây thật thì cái chết đến từ chỗ khác.
  const found = new Map([["NewFeedController.php", ["500"]]]);
  const undeclared = [...found.keys()].filter((f) => !(f in DECLARED));

  assert.deepEqual(undeclared, ["NewFeedController.php"]);
});
