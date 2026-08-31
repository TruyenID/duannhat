/**
 * #2898 — số hiệu phải đi theo thứ đã đi.
 *
 * # Sự việc
 *
 * `VERSION` đứng yên ở `0.8.1` trong khi **25 file `workstation/`** đi qua
 * `main` và lên production. Fleet là hai máy Windows **không tự cập nhật**, và
 * cách duy nhất tra version → commit là `manifest.json` của trang tải — nên hai
 * máy mang cùng số hiệu mà chạy hai bản khác nhau là một câu hỏi không trả lời
 * được: *"máy nào đã chạy migration 087 chưa?"*
 *
 * # Vì sao nó lọt
 *
 * Không rào nào đối chiếu hai dữ kiện đó. **Mỗi lượt promote đều đúng theo cách
 * nhìn của chính nó** — một lượt chạm workstation mà chưa bump thì "để lượt sau
 * bump" là hợp lý; chỉ khi cộng nhiều lượt lại mới thấy số hiệu đứng yên. Đó là
 * lý do phép kiểm phải so với **lần bump gần nhất**, không so với lượt trước.
 *
 * # Hàm thuần, để đo được
 *
 * Tách phép quyết định khỏi phần hỏi git: bài test dựng được mọi hình dạng đầu
 * vào mà không cần một kho git giả. Cùng lý lẽ đã trả giá ở
 * `DestructiveMigrationsNeedApprovalTest` — thả file thăm dò vào cây thật thì
 * cái chết đến từ chỗ khác, và nó trông y hệt rào đang chạy.
 */

/** Thư mục mà một thay đổi trong đó bắt buộc phải kèm số hiệu mới. */
export const VERSIONED_TREES = ["workstation/"];

/**
 * File trong cây đó nhưng KHÔNG đi vào binary quán cài (#3066).
 *
 * # Vì sao cần
 *
 * PR #3063 phải bump VERSION **hai lần**, lần thứ hai chỉ vì
 * `workstation/internal/handler/testdata/pos-api-manifest.json` — fixture của
 * rào parity. Số hiệu bản phát hành không đổi nghĩa gì khi file đó đổi.
 *
 * # Điều tôi đã tin sai, và phép đo bác nó
 *
 * Lý lẽ hiển nhiên là *"Go bỏ qua `testdata/` nên nó không thể vào binary"*.
 * **Sai.** Go bỏ qua `testdata/` khi PHÂN GIẢI PACKAGE, nhưng `go:embed` với
 * tới được. Đo trực tiếp 2026-08-17, module tối thiểu:
 *
 *     //go:embed testdata/x.txt
 *     var s string
 *     → go build rc=0 ·  go list → EmbedFiles: ["testdata/x.txt"]
 *
 * Nên đây KHÔNG phải bảo đảm của ngôn ngữ. Nó là một tính chất **của cây hiện
 * tại**, và tính chất thì phải có người canh: xem
 * `workstation-exempt-files-are-not-in-the-binary.test.mjs`, bài đó hỏi thẳng
 * `go list` xem có file miễn trừ nào lọt vào `GoFiles`/`EmbedFiles` không.
 * Không có bài đó thì miễn trừ này là một lời hứa suông.
 *
 * # Vì sao chỉ hai mẫu này, không có `.md`
 *
 * Cân bất đối xứng: rào kêu THỪA thì bump thêm một số (rẻ); rào IM khi cần kêu
 * thì hai máy mang cùng số hiệu mà chạy khác bản, và câu hỏi *"máy nào đã chạy
 * migration 087"* vĩnh viễn không trả lời được.
 *
 * `.md` trông an toàn nhưng KHÔNG chứng minh được: `posweb.go` và `frontend.go`
 * embed `all:pos-web/dist` và `all:frontend/dist` — cây dist do CI dựng, ở máy
 * cá nhân chỉ có 1 file stub. Tức phép đo "không .md nào bị embed" chạy ở máy
 * cá nhân là đo trên một cây RỖNG. Không đo được thì không miễn trừ.
 */
export const NOT_IN_BINARY = [
  { pattern: /(^|\/)testdata\//, why: "fixture của test; chỉ an toàn chừng nào không bị go:embed — có rào canh" },
  { pattern: /_test\.go$/, why: "go list xếp vào TestGoFiles, không vào GoFiles" },
];

/** File này có nằm ngoài binary quán cài không. */
export function isExemptFromVersioning(file) {
  return NOT_IN_BINARY.some(({ pattern }) => pattern.test(file));
}

/**
 * #3145 — file MANG số phiên bản, tức cổng đang đếm chính cái đuôi của mình.
 *
 * # Vòng tròn
 *
 * `workstation/frontend/package.json` nằm trong cây cần số hiệu, và rào
 * `test:version` ("MỘT số hiệu cho cả cây") bắt buộc nó khai đúng số — nên
 * **mọi lần bump đều phải sửa nó**. Bump ở một commit rồi đồng bộ file này ở
 * commit sau ⇒ cổng thấy một file trong cây đã đổi kể từ lần bump gần nhất ⇒
 * đỏ. Gỡ bằng cách bump thêm một số — mà lần bump đó lại sửa đúng file ấy.
 *
 * Đã cắn hai lần trong một ngày: PR #3142 (thủ phạm DUY NHẤT là file này, phải
 * bump 0.8.22 → 0.8.23) và nhánh `dev-review` (bump hai lần liên tiếp).
 *
 * # Vì sao KHÔNG miễn trừ cả đường dẫn
 *
 * File này cũng khai **dependency**, và đổi dependency thì binary CÓ đổi. Một
 * miễn trừ theo đường dẫn sẽ nuốt luôn ca đó — đúng cái bẫy mà bài
 * "#3066 KÊU: .go thật đổi CÙNG LƯỢT với testdata" đã ghim cho miễn trừ trước.
 *
 * Nên miễn trừ này xét **NỘI DUNG của delta**: chỉ khi hai bản JSON giống hệt
 * nhau sau khi bỏ đi các khoá mang số phiên bản. Một byte khác ở chỗ khác là
 * không miễn.
 */
export const VERSION_ONLY_MANIFEST = /(^|\/)package(-lock)?\.json$/;

/** Các khoá MANG số phiên bản, bỏ ra trước khi so hai bản. */
function stripVersionKeys(value) {
  const clone = structuredClone(value);
  delete clone.version;

  // `package-lock.json` chép lại số hiệu của chính gói ở đây; bỏ sót nó thì
  // lock file không bao giờ được miễn và vòng tròn còn nguyên cho nửa kia.
  if (clone.packages && typeof clone.packages === "object" && clone.packages[""]) {
    delete clone.packages[""].version;
  }

  return clone;
}

/** Thứ tự khoá không phải nội dung; so bằng dạng đã sắp. */
function stableStringify(value) {
  if (value === null || typeof value !== "object") {
    return JSON.stringify(value) ?? "null";
  }

  if (Array.isArray(value)) {
    return `[${value.map(stableStringify).join(",")}]`;
  }

  return `{${Object.keys(value)
    .sort()
    .map((k) => `${JSON.stringify(k)}:${stableStringify(value[k])}`)
    .join(",")}}`;
}

/**
 * Delta của một manifest JSON có CHỈ chạm số phiên bản không.
 *
 * Trả `false` khi không parse được — **không đo được thì không miễn trừ**. Đó
 * là cùng cân bất đối xứng mà docblock của cổng đã chọn: kêu thừa thì bump thêm
 * một số (rẻ), im khi cần kêu thì hai máy mang cùng số hiệu mà chạy khác bản.
 *
 * @param {string} before  nội dung file ở lần bump gần nhất
 * @param {string} after   nội dung file ở HEAD
 */
export function manifestDeltaIsVersionOnly(before, after) {
  let a;
  let b;

  try {
    a = JSON.parse(before);
    b = JSON.parse(after);
  } catch {
    return false;
  }

  if (a === null || b === null || typeof a !== "object" || typeof b !== "object") {
    return false;
  }

  return stableStringify(stripVersionKeys(a)) === stableStringify(stripVersionKeys(b));
}

/**
 * Thay đổi này có đòi bump không, và đã bump chưa.
 *
 * @param {object} input
 * @param {string[]} input.changedFiles  file đổi KỂ TỪ lần bump gần nhất
 * @param {boolean}  input.versionChanged  `VERSION` có nằm trong tập đó không
 * @param {string[]} [input.versionOnlyManifests]  manifest mà người gọi ĐÃ ĐO
 *   được rằng delta chỉ chạm số phiên bản (#3145). Người gọi đo, không phải hàm
 *   này — giữ hàm thuần để bài test dựng được mọi hình dạng đầu vào mà không
 *   cần một kho git giả.
 * @returns {{ ok: boolean, reason: string, offending: string[] }}
 */
export function checkVersionTracksFleet({
  changedFiles,
  versionChanged,
  versionOnlyManifests = [],
}) {
  const versionOnly = new Set(versionOnlyManifests);

  const offending = changedFiles
    .filter((f) => VERSIONED_TREES.some((tree) => f.startsWith(tree)))
    .filter((f) => !isExemptFromVersioning(f))
    .filter((f) => !(VERSION_ONLY_MANIFEST.test(f) && versionOnly.has(f)));

  if (offending.length === 0) {
    // Không chạm cây nào cần số hiệu ⇒ không đòi gì. Rào phải biết IM, nếu
    // không mọi PR sửa lỗi chính tả cũng phải bump và người ta sẽ tắt nó.
    return { ok: true, reason: "không chạm cây cần số hiệu", offending: [] };
  }

  if (versionChanged) {
    return { ok: true, reason: "đã bump", offending };
  }

  return {
    ok: false,
    reason:
      `${offending.length} file trong cây cần số hiệu đã đổi kể từ lần bump gần nhất, ` +
      "nhưng VERSION đứng yên",
    offending,
  };
}
