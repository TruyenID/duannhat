import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { test } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * #3012 — không symlink nào được TRACK mà trỏ ĐƯỜNG DẪN TUYỆT ĐỐI.
 *
 * Đã lọt một lần: `node_modules → /Users/<ai đó>/Herd/tempo/node_modules` vào
 * `dev` qua PR #3006. Trên máy khác nó trỏ vào hư không, và nó vi phạm thẳng
 * luật "không commit file ghim host/đường dẫn của MỘT máy".
 *
 * Hai thứ cộng lại mới cho nó lọt, và rào này chỉ đóng một:
 *   - `.gitignore` ghi `node_modules/` (có gạch chéo) nên chỉ khớp THƯ MỤC,
 *     không khớp symlink cùng tên — đã sửa cùng lượt;
 *   - `git add -A` trong worktree cuốn nó vào — thứ CLAUDE.md vốn đã cấm, và
 *     một luật bằng chữ thì không tự cưỡng chế được.
 *
 * Symlink TƯƠNG ĐỐI thì hợp lệ và repo đang dùng thật (`.codex/skills/*` trỏ
 * `../../.claude/skills/*`). Rào phải IM với chúng, nếu không nó sẽ bị tắt.
 */

/** Mọi symlink đang được git track, kèm đích của nó. */
function trackedSymlinks() {
  const rows = execFileSync("git", ["ls-tree", "-r", "HEAD"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  })
    .split("\n")
    .filter((line) => line.startsWith("120000 "));

  return rows.map((line) => {
    const [meta, path] = line.split("\t");
    const sha = meta.split(/\s+/)[2];
    const target = execFileSync("git", ["cat-file", "-p", sha], {
      cwd: root,
      encoding: "utf8",
    }).trim();

    return { path, target };
  });
}

test("không symlink nào được track mà trỏ đường dẫn tuyệt đối", () => {
  const pinned = trackedSymlinks().filter(({ target }) => target.startsWith("/"));

  assert.deepEqual(
    pinned.map(({ path, target }) => `${path} → ${target}`),
    [],
    "Symlink ghim đường dẫn của MỘT máy. Trên máy khác nó trỏ vào hư không.\n" +
      "Gỡ bằng `git rm --cached <path>`; nếu cần một đường dẫn cục bộ thì để nó\n" +
      "nằm ngoài git, đừng commit.",
  );
});

test("IM: symlink TƯƠNG ĐỐI là hợp lệ và repo đang dùng thật", () => {
  const links = trackedSymlinks();
  const relative = links.filter(({ target }) => !target.startsWith("/"));

  // Nếu phép đo này rỗng thì bài trên cũng rỗng — nó sẽ xanh vĩnh viễn mà không
  // canh gì. Cùng bẫy với #3005 (clone nông làm tập thay đổi rỗng).
  assert.ok(
    relative.length > 0,
    "không đọc được symlink nào — `git ls-tree` có chạy đúng cây không?",
  );
});
