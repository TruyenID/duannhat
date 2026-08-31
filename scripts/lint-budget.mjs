#!/usr/bin/env node
/**
 * Ratchet lint cho các app JS-TS (#2585).
 *
 * `web/customer` và `web/pos` đang mang lỗi ESLint CÓ SẴN trên `main` — 18 và 3,
 * toàn rule đúng đắn của React Hooks (`react-hooks/refs`, `set-state-in-effect`).
 * Sửa chúng là việc riêng và phải làm cẩn thận; nhưng chờ sửa xong mới bật cổng
 * nghĩa là tiếp tục không có cổng nào, mà đó chính là thứ #2585 nói tới.
 *
 * Nên cổng canh **số lỗi không được TĂNG**. Hai app sạch có ngân sách 0, tức với
 * chúng đây là lint chặn như bình thường.
 *
 * Vì sao không dùng `continue-on-error` cho hai app kia: một bước luôn xanh là
 * một tick xanh nói dối, và toàn bộ #2585 là về những tick như thế.
 *
 * Ngân sách chỉ được GIẢM. Sửa bớt lỗi mà quên hạ số ⇒ script này đỏ và bảo bạn
 * hạ — nếu không, chỗ vừa dọn sẽ lặng lẽ bị dùng lại làm chỗ chứa lỗi mới.
 */
import { execFileSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const budgetsPath = join(root, "scripts", "lint-budgets.json");
const app = process.argv[2];
const rewrite = process.argv.includes("--update");

if (!app) {
  console.error("dùng: node scripts/lint-budget.mjs <đường-dẫn-app> [--update]");
  process.exit(2);
}

const budgets = JSON.parse(readFileSync(budgetsPath, "utf8"));
if (!(app in budgets)) {
  console.error(
    `\`${app}\` chưa có ngân sách trong scripts/lint-budgets.json.\n` +
      "Thêm một app vào cổng thì phải thêm luôn ngân sách ở đây — nếu không nó " +
      "chạy lint mà không ai biết ngưỡng là bao nhiêu.",
  );
  process.exit(2);
}

// `eslint` thoát khác 0 khi có lỗi, nên KHÔNG được để execFileSync ném: ta cần
// đọc JSON của chính lượt chạy đó. `stdio: pipe` + bắt lỗi rồi lấy `e.stdout`.
let raw;
try {
  raw = execFileSync("pnpm", ["exec", "eslint", ".", "-f", "json"], {
    cwd: join(root, app),
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  });
} catch (e) {
  raw = e.stdout ?? "";
}

// pnpm chèn dòng banner trước JSON; cắt từ dấu `[` đầu tiên.
const start = raw.indexOf("[");
if (start === -1) {
  console.error(`Không đọc được JSON của eslint cho ${app}. Output thô:\n${raw.slice(0, 2000)}`);
  process.exit(2);
}

const results = JSON.parse(raw.slice(start));
const errors = results.reduce((n, f) => n + f.errorCount, 0);
const budget = budgets[app];

if (rewrite) {
  budgets[app] = errors;
  writeFileSync(budgetsPath, JSON.stringify(budgets, null, 2) + "\n");
  console.log(`${app}: ghi ngân sách = ${errors}`);
  process.exit(0);
}

if (errors > budget) {
  const worst = results
    .filter((f) => f.errorCount > 0)
    .flatMap((f) => f.messages.filter((m) => m.severity === 2).map((m) => `  ${f.filePath}:${m.line}  ${m.message}  [${m.ruleId}]`));
  console.error(
    `${app}: ${errors} lỗi lint, ngân sách ${budget}.\n` +
      `Thay đổi này thêm ${errors - budget} lỗi mới. Sửa chúng — đừng nâng ngân sách.\n\n` +
      worst.slice(0, 40).join("\n"),
  );
  process.exit(1);
}

if (errors < budget) {
  console.error(
    `${app}: chỉ còn ${errors} lỗi, ngân sách vẫn ghi ${budget}.\n` +
      "Hạ ngân sách trong scripts/lint-budgets.json xuống " + errors + " (hoặc chạy " +
      `\`node scripts/lint-budget.mjs ${app} --update\`).\n` +
      "Không hạ thì chỗ vừa dọn lặng lẽ thành chỗ chứa lỗi mới.",
  );
  process.exit(1);
}

console.log(`${app}: ${errors} lỗi lint, đúng ngân sách ${budget}.`);
