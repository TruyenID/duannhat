/**
 * Smoke test deploy web phải hỏi HAI câu, không phải một.
 *
 *   1. mã HTTP        → "server còn thở"
 *   2. dấu vân tay    → "bundle vừa build đang được phục vụ"
 *
 * Chỉ hỏi câu 1 thì một bundle sáu tháng tuổi trả lời "có" y hệt bundle vừa
 * dựng. Đó là cách `/downloads` trả **500 qua BA lượt deploy xanh liên tiếp**
 * (#3222 → #3225 → #3227): mỗi vòng CI xanh, smoke xanh, trang vẫn 500, và ba
 * vòng đó tôi đi tìm nguyên nhân ở tầng AWS — nơi không sửa được.
 *
 * Chỉ hỏi câu 2 cũng sai: bundle đúng mà trang trả 500 thì vẫn hỏng. Hai phép
 * kiểm hỏi hai câu khác nhau, thay cái này bằng cái kia là đổi một lỗ mù lấy
 * một lỗ mù khác — nên bài dưới ghim CẢ HAI cùng tồn tại.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const WF = join(root, ".github/workflows");
const SPECS = join(root, ".github/amplify");

/**
 * Bỏ dòng comment trước khi hỏi. Không có bước này thì mọi bài dưới đây trả lời
 * "có" khi chuỗi chỉ còn nằm trong comment giải thích — tức xoá LỆNH mà giữ lời
 * giải thích về nó vẫn qua cổng. Đo được: đột biến M3/M4 chỉ ĐỎ khi đổi cả hai
 * chỗ, vì mỗi chuỗi xuất hiện đúng hai lần — một trong lệnh, một trong comment.
 */
const code = (path) =>
  readFileSync(path, "utf8")
    .split("\n")
    .filter((l) => !/^\s*#/.test(l))
    .join("\n");

const deployWorkflows = () =>
  readdirSync(WF).filter((f) => /^(admin|customer|pos)-web-deploy\.ya?ml$/.test(f));

test("CÓ workflow deploy web để mà hỏi", () => {
  // Mẫu số bằng không có ba nguồn; "không hàng nào thuộc diện được hỏi" là
  // nguồn thứ ba. Đổi tên/bố cục thì bài này ĐỎ, không im.
  assert.equal(
    deployWorkflows().length,
    3,
    `thấy ${deployWorkflows().length} workflow deploy web, chờ 3 — bố cục đã đổi, sửa bài test chứ đừng xoá`,
  );
});

test("#3231 mỗi deploy web đối chiếu dấu vân tay của bundle với commit vừa merge", () => {
  const missing = deployWorkflows().filter((f) => {
    const b = code(join(WF, f));
    return !b.includes("/build-info.json") || !b.includes("merge-base --is-ancestor");
  });
  assert.deepEqual(
    missing,
    [],
    "Deploy KHÔNG kiểm bundle đang phục vụ:\n  " +
      missing.join("\n  ") +
      "\n\nMã HTTP xanh với mọi bundle, kể cả bundle của sáu tháng trước.",
  );
});

test("#3231 phép kiểm mã HTTP vẫn còn — dấu vân tay THÊM vào, không THAY THẾ", () => {
  const missing = deployWorkflows().filter(
    (f) => !code(join(WF, f)).includes("%{http_code}"),
  );
  assert.deepEqual(
    missing,
    [],
    "Deploy bỏ mất phép kiểm mã HTTP:\n  " +
      missing.join("\n  ") +
      "\n\nBundle đúng mà trang trả 500 thì vẫn hỏng.",
  );
});

test("#3231 mỗi buildspec PHÁT ra dấu vân tay, lấy từ git clone của Amplify", () => {
  const specs = existsSync(SPECS)
    ? readdirSync(SPECS).filter((f) => f.endsWith("-buildspec.yml"))
    : [];
  assert.ok(specs.length >= 3, `chỉ thấy ${specs.length} buildspec — bố cục đã đổi`);

  const missing = specs.filter((f) => {
    const b = code(join(SPECS, f));
    // Nguồn đúng là `git rev-parse HEAD` trong CHÍNH clone Amplify đang dựng.
    // Bản đầu của bài này đòi AWS_COMMIT_ID — nghe như "commit Amplify thật sự
    // dựng" nhưng job kiểu RELEASE đặt nó là chuỗi "HEAD" (đo 2026-08-18, job
    // 126 app d3cqu96a6b470f): merge-base không nhai được và smoke đỏ mọi lượt
    // deploy dù bundle đúng. Lấy github.sha thì vẫn sai như cũ — dấu vân tay
    // chỉ lặp lại câu hỏi thay vì trả lời nó.
    // Hỏi dòng GHI, không hỏi đường dẫn có xuất hiện ở đâu đó. Buildspec còn
    // một dòng `cat public/build-info.json` để in ra log; nhận nó thay cho dòng
    // ghi thì rào xanh trong khi không có gì được phát ra — đột biến M6 bắt được.
    return (
      !/writeFileSync\(\s*['"]public\/build-info\.json['"]/.test(b) ||
      !/git rev-parse HEAD/.test(b) ||
      /process\.env\.AWS_COMMIT_ID/.test(b)
    );
  });
  assert.deepEqual(
    missing,
    [],
    "Buildspec không phát dấu vân tay (hoặc lấy sai nguồn):\n  " + missing.join("\n  "),
  );
});

/**
 * Ba bài trên chỉ đọc CHỮ trong file. Bài dưới chạy đúng đoạn lệnh ấy trên dữ
 * liệu thật — vì "có chuỗi trong file" và "logic ra quyết định đúng" là hai
 * chuyện, và loại rào chỉ grep chữ đã trượt ở đây trước rồi.
 */
const ONE_LINER = (() => {
  // Lấy đúng đoạn lệnh ĐANG chạy trên CI, không chép lại nó vào đây. Bản đầu của
  // bài này chép cứng one-liner và vì thế XANH ngay cả khi lệnh thật thoái hoá —
  // đột biến M5 bắt được. Một bài test chạy bản sao thì nó đang canh bản sao.
  const body = readFileSync(join(WF, "pos-web-deploy.yml"), "utf8");
  const m = body.match(/node -e "([^"]+)"\)$/m) || body.match(/node -e "([^"]+)"/);
  assert.ok(m, "không tìm thấy lệnh đọc commit trong pos-web-deploy.yml — bố cục đã đổi");
  return m[1].replace(/\\"/g, '"');
})();

const extract = (payload) =>
  execFileSync("node", ["-e", ONE_LINER], { input: payload, encoding: "utf8" });

test("#3231 đọc commit chịu được payload rác — không được ném, phải trả rỗng", () => {
  assert.equal(extract('{"commit":"abc123","branch":"main"}'), "abc123");
  assert.equal(extract("{}"), "", "thiếu khoá ⇒ rỗng ⇒ smoke đỏ có thông điệp");
  assert.equal(extract("<html>404</html>"), "", "trang 404 ⇒ rỗng, KHÔNG được ném");
  assert.equal(extract(""), "", "không đọc được ⇒ rỗng");
  assert.equal(extract('{"commit":null}'), "", "null ⇒ rỗng, không phải chuỗi 'null'");
});

test("#3231 phép so tổ tiên: commit MỚI HƠN được chấp nhận, commit CŨ thì không", () => {
  // Vế này chống rào-kêu-oan: hai PR merge sát nhau thì Amplify dựng cái sau, mà
  // cái sau CHỨA cái này — deploy vẫn tới nơi. Đúng chuyện đã xảy ra hôm nay với
  // #3215/#3216, nơi tôi đọc `cancelled` thành "bị chặn". Một rào kêu oan không
  // bị tranh luận, nó bị TẮT.
  const git = (...a) => execFileSync("git", a, { cwd: root, encoding: "utf8" }).trim();
  const head = git("rev-parse", "HEAD");
  const parent = git("rev-parse", "HEAD~1");

  const contains = (older, newer) => {
    try {
      execFileSync("git", ["merge-base", "--is-ancestor", older, newer], { cwd: root });
      return true;
    } catch {
      return false;
    }
  };

  assert.equal(contains(head, head), true, "cùng commit ⇒ chấp nhận");
  assert.equal(contains(parent, head), true, "bundle MỚI HƠN vẫn chứa commit ta merge ⇒ chấp nhận");
  assert.equal(contains(head, parent), false, "bundle CŨ HƠN ⇒ phải ĐỎ");
});
