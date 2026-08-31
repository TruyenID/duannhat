/**
 * MỌI lời gọi `ssh` trong đường deploy production phải mang đủ ba cờ danh tính.
 *
 * Vì sao cần rào cho một thứ trông hiển nhiên: bước `Setup SSH` ghi khoá và
 * known_hosts vào `$RUNNER_TEMP/ssh`, **không** vào `~/.ssh` — runner tự quản
 * dùng chung cho nhiều repo nên nó cố ý không đụng HOME. Hệ quả là một lời gọi
 * `ssh` trần KHÔNG thấy host key, và chết với `Host key verification failed`
 * (exit 255).
 *
 * Cái làm nó đắt là THỜI ĐIỂM nổ. Bước thiếu cờ nằm giữa dãy: rsync đã đẩy code
 * mới lên server xong, còn `migrate` + dựng lại cache + smoke test thì nằm SAU
 * nó nên bị `skipped`. Deploy dừng ở trạng thái nửa vời mà từ ngoài nhìn vào
 * production **vẫn trả lời bình thường** — route cũ còn trong route cache cũ.
 *
 * Đo được ngày 2026-08-17, run 32039727697: `/api/v1/pos/menus/{menu}/products`
 * trả 302 (route cũ, còn trong cache) trong khi `/api/v1/pos/menus/{menu}/sections`
 * trả **404** — route mới của #3163 chưa bao giờ được đăng ký. Cùng lúc đó
 * pos-web đã deploy bản gọi đúng route ấy và đã TẮT đường tải cả thực đơn
 * (`enabled: false`), nên lưới POS không còn đường lùi: 404 ở `/sections` là
 * quán không có menu. Không một cảnh báo nào ở giữa hai sự việc đó.
 *
 * Nói cách khác: cái hỏng không phải SSH, mà là **một deploy thất bại giữa
 * chừng đọc lên giống hệt một deploy thành công**. Rào này chặn nguyên nhân duy
 * nhất đã từng gây ra nó.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const WORKFLOW = ".github/workflows/deploy-xserver.yml";
const yaml = readFileSync(join(root, WORKFLOW), "utf8");

/** Mọi dòng mở đầu một lời gọi `ssh` (không tính `ssh-keyscan`, `-e "ssh …"`). */
function sshInvocations(text) {
  return text
    .split("\n")
    .map((line, i) => ({ line, lineNo: i + 1 }))
    .filter(
      ({ line }) =>
        /(^|\s)ssh\s+-p\s/.test(line) &&
        !line.includes("ssh-keyscan") &&
        !/-e\s+"ssh/.test(line),
    );
}

/**
 * Cờ phải có mặt. `-i` một mình chưa đủ: thiếu `UserKnownHostsFile` là chết ở
 * host key, và thiếu `IdentitiesOnly` thì agent của runner có thể chen một khoá
 * khác vào rồi hỏng theo cách khác hẳn.
 */
const REQUIRED = [
  '-i "$RUNNER_TEMP/ssh/id_deploy"',
  '-o UserKnownHostsFile="$RUNNER_TEMP/ssh/known_hosts"',
  "-o IdentitiesOnly=yes",
];

/** Lời gọi thường ngắt dòng bằng `\`, nên phải đọc cả phần nối. */
function continuedBlock(text, lineNo) {
  const lines = text.split("\n");
    let out = "";
  for (let i = lineNo - 1; i < lines.length; i++) {
    out += lines[i];
    if (!lines[i].trimEnd().endsWith("\\")) break;
  }
  return out;
}

test("đường deploy CÓ gọi ssh — nếu không, bài test này đang đo khoảng không", () => {
  // Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào thuộc
  // diện được hỏi". Không có phép đếm này thì một lần đổi bố cục workflow sẽ
  // làm rào im lặng thay vì đỏ.
  assert.ok(
    sshInvocations(yaml).length >= 3,
    `${WORKFLOW}: không tìm thấy đủ lời gọi ssh — bố cục đã đổi, hãy sửa bài test chứ đừng xoá nó`,
  );
});

test("mọi lời gọi ssh mang đủ ba cờ danh tính", () => {
  const offenders = [];

  for (const { lineNo } of sshInvocations(yaml)) {
    const block = continuedBlock(yaml, lineNo);
    const missing = REQUIRED.filter((flag) => !block.includes(flag));
    if (missing.length > 0) {
      offenders.push(`  ${WORKFLOW}:${lineNo} thiếu ${missing.join(" · ")}`);
    }
  }

  assert.deepEqual(
    offenders,
    [],
    `Lời gọi ssh thiếu cờ danh tính — sẽ chết "Host key verification failed" (exit 255)\n` +
      `và mọi bước SAU nó (migrate, dựng lại cache, smoke test) bị bỏ qua trong im lặng:\n` +
      offenders.join("\n"),
  );
});

test("bước dựng lại cache nằm SAU các bước ghi .env, và không bước nào chen vào giữa mà thiếu cờ", () => {
  // Thứ tự này load-bearing: `migrate`/`optimize` phải chạy sau khi .env đã
  // đúng. Nhưng nó cũng có nghĩa mọi bước ghi .env đứng CHẶN đường tới cache —
  // nên một bước ghi .env hỏng làm production chạy code mới với route cache cũ.
  const cacheStep = yaml.indexOf("Reconcile, migrate & cache on server");
  assert.ok(cacheStep > 0, `${WORKFLOW}: không còn bước reconcile/migrate/cache`);

  const before = yaml.slice(0, cacheStep);
  for (const { lineNo } of sshInvocations(before)) {
    const block = continuedBlock(yaml, lineNo);
    assert.ok(
      REQUIRED.every((flag) => block.includes(flag)),
      `${WORKFLOW}:${lineNo} — bước SSH đứng TRƯỚC bước dựng lại cache mà thiếu cờ danh tính. ` +
        `Nó hỏng thì cache không bao giờ được dựng lại, và production phục vụ code mới bằng route cache cũ.`,
    );
  }
});
