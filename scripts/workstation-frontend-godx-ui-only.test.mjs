/**
 * workstation/frontend CHỈ được dùng MỘT thư viện UI: @godxjp/ui.
 *
 * Ruling chủ dự án 2026-08-18. Trước đó app này trộn ba tầng: component
 * @godxjp/ui (npm 18.x), icon lucide-react, và một lượng lớn div Tailwind
 * tự viết. Hai dòng @godxjp/ui (npm 18.x cho workstation · bản in-tree cho
 * pos/admin) đã đủ rối; thêm một thư viện UI thứ ba lọt vào là ba nguồn
 * chân lý cho cùng một nút bấm.
 *
 * Rào này khoá hai mặt MÁY ĐO ĐƯỢC:
 *   1. dependencies của package.json là ALLOWLIST — thêm dep mới phải sửa
 *      test này, tức phải nói to lý do trong PR.
 *   2. src không được import thư viện UI nào khác (kể cả import trực tiếp
 *      @radix-ui/* — Radix chỉ được đi QUA @godxjp/ui).
 *
 * Phần "cấm Tailwind trần, mọi UI qua component" là luật CHỮ trong
 * workstation/CLAUDE.md — một regex đếm className không phân biệt được
 * "div layout hợp lệ" với "tự dựng button", nên không giả vờ đo nó ở đây.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const APP = "workstation/frontend";

// Mỗi mục một lý do. Không có lý do thì không có mặt ở đây.
const ALLOWED_DEPS = new Set([
  "@godxjp/ui", // thư viện UI DUY NHẤT
  "lucide-react", // icon — chính @godxjp/ui cũng dựa trên nó; emoji bị cấm toàn repo
  "@sentry/react", // observability, không phải UI
  "@wailsio/runtime", // cầu Wails, bắt buộc
  "react",
  "react-dom",
  "react-hook-form", // logic form, không render gì
  "react-router",
  "use-sync-external-store",
]);

const BANNED_IMPORT = new RegExp(
  [
    "@radix-ui/", // chỉ được đi QUA @godxjp/ui
    "@headlessui/",
    "@mui/",
    "@mantine/",
    "@chakra-ui/",
    "styled-components",
    "@emotion/",
    "antd",
    "daisyui",
    "bootstrap",
    "semantic-ui",
    "@ant-design/",
  ]
    .map((s) => s.replace(/[/\\^$*+?.()|[\]{}]/g, "\\$&"))
    .join("|"),
);

test("dependencies của workstation/frontend là allowlist đã duyệt", () => {
  const pkg = JSON.parse(readFileSync(join(root, APP, "package.json"), "utf8"));
  const extra = Object.keys(pkg.dependencies ?? {}).filter((d) => !ALLOWED_DEPS.has(d));
  assert.deepEqual(
    extra,
    [],
    "Dep mới ngoài allowlist:\n  " +
      extra.join("\n  ") +
      "\n\nworkstation/frontend chỉ được dùng @godxjp/ui làm thư viện UI. " +
      "Dep phi-UI chính đáng thì thêm vào ALLOWED_DEPS kèm lý do một dòng.",
  );
});

test("src không import thư viện UI nào ngoài @godxjp/ui", () => {
  const files = execFileSync("git", ["ls-files", `${APP}/src/**/*.ts`, `${APP}/src/**/*.tsx`], {
    cwd: root,
    encoding: "utf8",
  })
    .split("\n")
    .filter(Boolean);
  assert.ok(files.length > 10, `chỉ thấy ${files.length} file — bố cục đã đổi, sửa test`);

  const offenders = [];
  for (const f of files) {
    const src = readFileSync(join(root, f), "utf8");
    for (const m of src.matchAll(/from\s+["']([^"']+)["']/g)) {
      if (BANNED_IMPORT.test(m[1])) offenders.push(`${f}: ${m[1]}`);
    }
  }
  assert.deepEqual(
    offenders,
    [],
    "Import thư viện UI ngoài @godxjp/ui:\n  " + offenders.join("\n  "),
  );
});

test("@godxjp/ui hiện diện và theo dòng 18.x", () => {
  const pkg = JSON.parse(readFileSync(join(root, APP, "package.json"), "utf8"));
  const spec = pkg.dependencies?.["@godxjp/ui"] ?? "";
  assert.match(
    spec,
    /^\^18\./,
    `@godxjp/ui spec là "${spec}" — workstation frontend đi dòng npm 18.x; ` +
      "đổi lineage (ví dụ về bản in-tree) là quyết định kiến trúc, sửa test này kèm lý do.",
  );
});
