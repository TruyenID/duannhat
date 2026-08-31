/**
 * #2827 — MỌI file trong `.github/workflows/` phải parse được thành YAML.
 *
 * Nghe hiển nhiên. Nó không hiển nhiên, và cách nó hỏng mới là lý do bài này
 * tồn tại:
 *
 * `workstation-manifest-restore.yml` được thêm vào với hai khối python nội
 * tuyến trong `run: |` mà nội dung nằm ở CỘT 0. Trong một literal block scalar,
 * dòng ở cột 0 KẾT THÚC khối — nên `import json` bị đọc như YAML và cả file
 * hỏng. Hệ quả trên GitHub:
 *
 *   · workflow được đăng ký nhưng `workflow_dispatch` KHÔNG dùng được —
 *     API trả `HTTP 422: Workflow does not have 'workflow_dispatch' trigger`;
 *   · mỗi lượt push sinh một lượt chạy ĐỎ mang tên là ĐƯỜNG DẪN FILE thay vì
 *     tên workflow, và `gh run view --log-failed` trả "log not found".
 *
 * Không dấu vết nào trong đó nói "YAML hỏng". Người đọc sẽ đi tìm lỗi ở chỗ
 * khác — chính xác là chuyện đã xảy ra.
 *
 * Rào này rẻ và quét toàn thư mục, nên nó canh cả những workflow chưa ai viết.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { join } from "node:path";
import yaml from "yaml";

const DIR = ".github/workflows";
const FILES = readdirSync(DIR).filter((f) => /\.ya?ml$/.test(f));

test("có workflow để quét — ghim mẫu số", () => {
  // Không có bài này thì một thư mục rỗng (đổi bố cục, đổi tên) sẽ làm bài dưới
  // xanh mà không kiểm gì. Đúng họ lỗi mà repo đã trả giá khi gộp monorepo:
  // năm cổng ngừng canh và không test nào đỏ.
  assert.ok(FILES.length >= 5, `chỉ thấy ${FILES.length} workflow — bố cục đổi?`);
});

for (const file of FILES) {
  test(`${file} parse được`, () => {
    const doc = yaml.parseDocument(readFileSync(join(DIR, file), "utf8"));
    const errors = doc.errors.map((e) => `${e.code} @ offset ${e.pos?.[0]}`);
    assert.deepEqual(errors, [], `${file} không parse được:\n  ${errors.join("\n  ")}`);
  });

  test(`${file} khai ít nhất một trigger`, () => {
    // Một file parse được nhưng thiếu `on:` cũng im lặng y hệt: GitHub không
    // bao giờ chạy nó, và không có gì đỏ để nói vì sao.
    const doc = yaml.parseDocument(readFileSync(join(DIR, file), "utf8")).toJS();
    // `on` là YAML 1.1 boolean true, nên parser có thể trả về khoá `true`.
    const triggers = doc?.on ?? doc?.[true];
    assert.ok(
      triggers && Object.keys(triggers).length > 0,
      `${file} không khai trigger nào`,
    );
  });

  test(`${file} mọi khối run: là shell hợp lệ`, () => {
    // #3065 — YAML parse được KHÔNG có nghĩa là workflow chạy được.
    //
    // Trong `workstation-release.yml`, một đoạn văn giải thích bị dán thẳng vào
    // giữa thân `run: |`, trong lòng vòng `for … done`, và mất luôn vài ký tự
    // đầu mỗi dòng:
    //
    //     go build … ./cmd/ws-server
    //     etention-days: 1`, KHÔNG phải 7 (#3033). Năm binary × ~33 MB × mỗi
    //     ợt chạy, giữ 7 ngày, là đủ để vượt trần 500 MB …
    //   done
    //
    // Bài `parse được` ngay trên **vẫn xanh**, và đúng: trong một block scalar
    // mọi thứ là văn bản, nên YAML không có ý kiến gì. Chỉ tới lúc runner đưa
    // chuỗi đó cho bash thì `etention-days:` mới thành một lệnh không tồn tại —
    // tức lỗi nằm im trong repo cho tới lượt chạy thật.
    //
    // Đây là vế thứ hai của cùng một câu hỏi: bài trên hỏi "GitHub đọc được
    // file này không", bài này hỏi "bash chạy được thứ bên trong không".
    const doc = yaml.parseDocument(readFileSync(join(DIR, file), "utf8")).toJS();
    const broken = [];
    let checked = 0;

    for (const [jobName, job] of Object.entries(doc?.jobs ?? {})) {
      const jobShell = job?.defaults?.run?.shell;
      for (const [i, step] of (job?.steps ?? []).entries()) {
        if (typeof step?.run !== "string") continue;
        const shell = step.shell ?? jobShell ?? doc?.defaults?.run?.shell ?? "bash";
        // pwsh/python/node có cú pháp khác — bài này chỉ nói về shell POSIX.
        if (!/^(bash|sh)\b/.test(shell)) continue;
        checked++;

        // `${{ … }}` được GitHub thay TRƯỚC khi bash thấy chuỗi, nên để nguyên
        // sẽ báo lỗi cú pháp oan. Thay bằng một token là giữ được phần còn lại
        // của khối trong tầm quét — bỏ qua cả khối (bản đầu tôi viết vậy) thì
        // rào tự miễn trừ đúng những bước phức tạp nhất, tức nó canh phần dễ.
        const src = step.run.replace(/\$\{\{[\s\S]*?\}\}/g, "GHEXPR");
        const r = spawnSync("bash", ["-n"], { input: src, encoding: "utf8" });
        if (r.status !== 0) {
          broken.push(`${jobName} › ${step.name ?? `bước ${i}`}: ${(r.stderr ?? "").trim().split("\n")[0]}`);
        }
      }
    }

    assert.deepEqual(broken, [], `${file} có khối run: không phải shell hợp lệ:\n  ${broken.join("\n  ")}`);
    assert.ok(checked > 0 || FILES.length === 0, `${file}: 0 khối run: được kiểm — xem lại đường dò`);
  });
}
