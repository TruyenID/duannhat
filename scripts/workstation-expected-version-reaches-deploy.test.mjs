/**
 * Con số "quán nên ở bản nào" phải ĐI ĐƯỢC từ repo xuống `.env` production.
 *
 * # Vì sao cần rào
 *
 * #3173 dựng `deploy:verify-workstation-expected-version` để feed expected-build
 * không bao giờ trả `version:null`. Rào đó đúng, và nó KHÔNG bắt được ca đã xảy
 * ra: nó chỉ hỏi *"giá trị đang có tải được không"*, không hỏi *"giá trị đang có
 * đến từ đâu"*. Con số nằm đặt tay ở dòng 88 của `.env` trên server, nên nó trôi
 * mà không ai thấy.
 *
 * Đo 2026-08-18: trang tải `latest = v0.8.31`, `.env` production vẫn `v0.8.30`,
 * và ba máy trạm đứng ở `v0.6.0` — trong khi `deploy:verify-*` vẫn EXIT=0 suốt,
 * vì v0.8.30 THẬT SỰ tải được. Rào xanh, feed đúng cú pháp, fleet cũ 15 bản.
 *
 * # Rào này phát biểu gì
 *
 * Ba mệnh đề, và cả ba đều phải đo được bằng văn bản:
 *
 *   1. `WORKSTATION_EXPECTED_VERSION` ở gốc cây khai một semver có tiền tố `v`;
 *   2. deploy ĐỌC file đó rồi GHI vào khoá cùng tên trong `.env` server;
 *   3. bước ghi nằm TRƯỚC `config:cache`.
 *
 * Mệnh đề 3 là thứ dễ mất nhất khi có người sắp xếp lại workflow. Production đọc
 * `bootstrap/cache/config.php`; ghi `.env` sau lượt cache là ghi vào một file
 * không ai đọc cho tới lần deploy kế tiếp — và triệu chứng của nó là "deploy
 * xong nhưng quán vẫn ở bản cũ", đúng thứ khó quy trách nhất.
 *
 * # Vì sao file nằm ở GỐC, không nằm trong `workstation/`
 *
 * `VERSIONED_TREES = ["workstation/"]`, và `test:version-fleet` phát biểu "delta
 * chạm `workstation/` thì `VERSION` phải đổi". Đặt file ở đó là dựng lại đúng
 * vòng tròn #3145: bump expected lên một bản ĐÃ phát hành sẽ đòi bump `VERSION`,
 * mà bump `VERSION` lại phát hành một bản mới hơn — expected vừa đặt đã cũ ngay
 * lúc merge. Hai con số trả lời hai câu hỏi khác nhau nên chúng phải ở hai cây
 * khác nhau.
 *
 * # Hai chiều
 *
 * Mọi phép kiểm dưới đây đều có phản chứng chạy trên văn bản đã bị bẻ. Một rào
 * chỉ biết xanh thì không phân biệt được "đúng" với "không đo gì" — và nó sẽ
 * xanh y hệt vào ngày ai đó xoá mất bước deploy.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const VERSION_FILE = "WORKSTATION_EXPECTED_VERSION";
const WORKFLOW = ".github/workflows/deploy-xserver.yml";

const versionFile = readFileSync(join(root, VERSION_FILE), "utf8");
const yaml = readFileSync(join(root, WORKFLOW), "utf8");

/**
 * Giá trị thật của file, đọc y hệt cách bước deploy đọc nó: bỏ dòng `#`, bỏ mọi
 * khoảng trắng. Trả `""` khi không còn gì — người gọi phân biệt rỗng với sai.
 */
export function parseExpectedVersion(text) {
  return text
    .split("\n")
    .filter((line) => !/^\s*#/.test(line))
    .join("")
    .replace(/\s/g, "");
}

const SEMVER_V = /^v\d+\.\d+\.\d+$/;

/**
 * Đường đi của con số qua workflow, quy về những mốc ĐO ĐƯỢC bằng vị trí dòng.
 *
 * Trả `-1` cho mốc vắng mặt thay vì ném: bài test muốn nói "thiếu bước ghi"
 * khác với "thứ tự sai", và một ngoại lệ gộp cả hai thành một thông điệp.
 */
export function deployWiring(text) {
  const lines = text.split("\n");
  const at = (re) => lines.findIndex((line) => re.test(line));

  return {
    // Bước đọc file ở gốc cây — nguồn DUY NHẤT được phép.
    readsFile: at(new RegExp(`grep[^\\n]*${VERSION_FILE}`)),
    // Ghi vào khoá cùng tên trong `.env` server.
    writesKey: at(/^\s*KEY=WORKSTATION_EXPECTED_VERSION\s*$/),
    // Lượt dựng lại cache config — mốc mà mọi thay đổi `.env` phải đứng TRƯỚC.
    configCache: at(/artisan config:cache/),
    // Rào #3173, vẫn phải còn và vẫn phải chạy sau khi giá trị đã vào `.env`.
    verifies: at(/deploy:verify-workstation-expected-version/),
    // Deploy TỰ chọn bản mới nhất là thứ #3173 từ chối — bắt tại chỗ.
    picksLatest: at(/\.latest[^\n]*manifest\.json|manifest\.json[^\n]*\.latest/),
  };
}

test(`${VERSION_FILE} khai một semver có tiền tố v`, () => {
  const version = parseExpectedVersion(versionFile);

  assert.notEqual(version, "", `${VERSION_FILE} không có dòng giá trị nào ngoài chú thích`);
  assert.match(
    version,
    SEMVER_V,
    `${VERSION_FILE} phải là dạng vX.Y.Z (đang là: [${version}])`,
  );
});

test("phản chứng: file chỉ có chú thích, hoặc thiếu tiền tố v, đều KHÔNG lọt", () => {
  assert.equal(parseExpectedVersion("# chỉ chú thích\n# thêm dòng nữa\n"), "");
  assert.doesNotMatch(parseExpectedVersion("0.8.31\n"), SEMVER_V);
  assert.doesNotMatch(parseExpectedVersion("v0.8\n"), SEMVER_V);
  assert.doesNotMatch(parseExpectedVersion("latest\n"), SEMVER_V);

  // Chiều IM: chú thích quanh một giá trị đúng không được làm hỏng phép đọc.
  assert.equal(parseExpectedVersion("# lý do\nv0.8.31\n"), "v0.8.31");
});

test("deploy ĐỌC file ở gốc cây và GHI vào .env production", () => {
  const w = deployWiring(yaml);

  assert.notEqual(w.readsFile, -1, `${WORKFLOW} không đọc ${VERSION_FILE}`);
  assert.notEqual(
    w.writesKey,
    -1,
    `${WORKFLOW} không ghi WORKSTATION_EXPECTED_VERSION vào .env`,
  );
});

test("bước ghi .env đứng TRƯỚC config:cache", () => {
  const w = deployWiring(yaml);

  assert.notEqual(w.configCache, -1, `${WORKFLOW} không còn bước config:cache`);
  assert.ok(
    w.writesKey < w.configCache,
    `ghi .env ở dòng ${w.writesKey + 1} nhưng config:cache ở dòng ${w.configCache + 1} — ` +
      "production đọc bootstrap/cache/config.php, nên giá trị mới sẽ không có tác dụng " +
      "cho tới lần deploy sau",
  );
});

test("rào #3173 vẫn còn, và vẫn chạy SAU khi giá trị đã vào .env", () => {
  const w = deployWiring(yaml);

  assert.notEqual(
    w.verifies,
    -1,
    "deploy:verify-workstation-expected-version đã biến mất — không còn ai xác nhận " +
      "bản được đẩy xuống là bản tải được thật",
  );
  assert.ok(w.verifies > w.writesKey, "rào #3173 phải đo giá trị MỚI, không phải giá trị cũ");
});

test("deploy KHÔNG được tự chọn bản mới nhất", () => {
  const w = deployWiring(yaml);

  assert.equal(
    w.picksLatest,
    -1,
    `${WORKFLOW} đang đọc \`latest\` từ manifest. Đó là deploy tự quyết "quán nên ở bản ` +
      'nào" — thứ #2635/#3173 giao cho HQ. Với WORKSTATION_EXPECTED_AUTO_APPLY=true nó ' +
      "thành: mọi merge vào main tự khởi động lại máy quán lúc 2h sáng.",
  );
});

test("phản chứng: workflow bị bẻ thì từng phép kiểm PHẢI đỏ", () => {
  // Xoá bước ghi.
  const noWrite = yaml.replace(/^\s*KEY=WORKSTATION_EXPECTED_VERSION\s*$/m, "          KEY=NOPE");
  assert.equal(deployWiring(noWrite).writesKey, -1);

  // Xoá phép đọc file.
  const noRead = yaml.replace(new RegExp(VERSION_FILE, "g"), "SOMETHING_ELSE");
  assert.equal(deployWiring(noRead).readsFile, -1);

  // Gỡ rào #3173.
  const noVerify = yaml.replace(/deploy:verify-workstation-expected-version/g, "true");
  assert.equal(deployWiring(noVerify).verifies, -1);

  // Deploy tự chọn `latest`.
  const picksLatest = yaml.replace(
    /^\s*- name: Reconcile, migrate & cache on server\s*$/m,
    '          EXPECTED=$(jq -r .latest public/downloads/workstation/manifest.json)\n$&',
  );
  assert.notEqual(deployWiring(picksLatest).picksLatest, -1);

  // Đảo thứ tự: bước ghi rơi xuống sau config:cache.
  const reordered = ["a: 1", "  artisan config:cache", "          KEY=WORKSTATION_EXPECTED_VERSION"].join("\n");
  const w = deployWiring(reordered);
  assert.ok(w.writesKey > w.configCache, "văn bản đảo thứ tự phải cho ra mốc ghi SAU cache");
});
