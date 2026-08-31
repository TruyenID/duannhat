# Khôi phục `manifest.json` của trang tải — chạy tay, không cần CI

`manifest.json` là thứ **duy nhất** trả lời hai câu: *"quán đang chạy bản nào"*
và *"muốn lùi thì lùi về đâu"*. Fleet là hai máy Windows **cài tay, không tự cập
nhật**, nên khi cần lùi một bản hỏng thì file này là bản đồ duy nhất.

Có sẵn một workflow làm việc này (`workstation-manifest-restore.yml`,
`workflow_dispatch`). Trang này tồn tại vì **đường lùi không được phụ thuộc CI**:
ngày 2026-08-16 CI hỏng **hai kiểu khác nhau trong vài giờ** — hết Actions budget
lúc ~05:32 UTC, rồi runner hết đĩa lúc ~09:28 UTC. Chọn runner nào cũng không
thoát, và cả hai đều xảy ra thật.

Tin tốt: **logic đã đứng riêng.** `.github/scripts/restore-workstation-manifest-history.py`
không tham chiếu `GITHUB_*`, `RUNNER_*` hay `secrets` nào. Workflow chỉ là lớp
bọc. Trang này viết ra hai thứ vốn chỉ sống trong YAML: **lệnh sinh kiểm kê** và
**cách ghi lên + đối chiếu**.

## Cần gì

- SSH vào `famgia@famgia.xbiz.jp` cổng `10022` (cùng khoá mà workflow dùng)
- `python3` trên máy của bạn
- một bản checkout của repo (để chạy hai script)

Hằng số, khớp `env:` của workflow:

```sh
SSH_HOST=famgia.xbiz.jp
SSH_PORT=10022
SSH_USER=famgia
APP_DIR=apps/tempo
PROD_MANIFEST_URL=https://tempo-prod.godx.jp/downloads/workstation/manifest.json
```

## 0. Chạy test của bộ gộp trước khi đụng production

```sh
node --test scripts/workstation-manifest-restore.test.mjs
```

Không xanh thì **dừng**. Đây là bước đầu tiên của workflow, và nó đứng đầu vì
một lý do: bộ gộp sai sẽ ghi đè manifest đang phục vụ.

## 1. Kéo manifest đang phục vụ — fail-closed

```sh
curl -fsS --max-time 30 "$PROD_MANIFEST_URL" -o manifest.prod.json
python3 -c 'import json; json.load(open("manifest.prod.json"))'
python3 -c 'import json; d=json.load(open("manifest.prod.json")); print("latest:", d.get("latest"), "| thế hệ:", len(d.get("versions", [])))'
```

**Không tải được thì DỪNG.** Đừng khởi tạo từ rỗng: một script mang tên "khôi
phục" mà bắt đầu từ file trắng sẽ xoá đúng thứ nó được gọi để cứu.

## 2. Kiểm kê ĐĨA production

Nguồn sự thật là **hiện trạng đĩa**, không phải suy diễn. Kích thước và hash
phải tính **trên chính máy đang phục vụ file** — `SHA256SUMS` là thứ người cài
đối chiếu trước khi chạy một nhị phân trên máy quán, nên một con số suy ra ở nơi
khác còn tệ hơn không có entry.

```sh
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" '
  set -eu
  root="$HOME/apps/tempo/public/downloads/workstation"
  cd "$root" || exit 0
  for d in v*/; do
    v="${d%/}"
    [ -d "$v" ] || continue
    for f in "$v"/*; do
      [ -f "$f" ] || continue
      printf "%s\t%s\t%s\t%s\n" "$v" "$(basename "$f")" \
        "$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f")" \
        "$(sha256sum "$f" | cut -d" " -f1)"
    done
  done
' > inventory.tsv

cut -f1 inventory.tsv | sort -u          # các thế hệ tìm thấy trên đĩa
test -s inventory.tsv || echo "KIỂM KÊ RỖNG — không có gì để khôi phục, dừng."
```

Bốn cột: `version` · `filename` · `size` · `sha256`.

## 3. Khai metadata cho từng bản muốn khôi phục

```sh
cat > meta.json <<'JSON'
{
  "v0.7.0": { "commit": "<sha>", "released_at": "2026-07-xxT00:00:00Z" }
}
JSON
python3 -c 'import json; json.load(open("meta.json"))'
```

**Bản nào không có trong `meta.json` sẽ bị BỎ QUA** — cố ý. Script fail-closed ở
hai chỗ, cùng một lý do *thà bỏ một bản còn hơn công bố một bản sai*:

- thiếu bất kỳ nền tảng nào trong `REQUIRED_PLATFORMS` ⇒ bỏ cả version đó;
- **không biết commit ⇒ bỏ**, vì *"quán đang chạy commit nào"* chính là câu hỏi
  manifest sinh ra để trả lời.

Tra commit theo version: xem `docs/reference/` hoặc tag phát hành; đừng đoán.

## 4. Dựng manifest mới — xem trước, chưa ghi

```sh
python3 .github/scripts/restore-workstation-manifest-history.py \
  --manifest manifest.prod.json \
  --inventory inventory.tsv \
  --meta      meta.json \
  --out       manifest.new.json

diff <(python3 -m json.tool manifest.prod.json) \
     <(python3 -m json.tool manifest.new.json)
```

Script **không** đụng `latest`, **không** đụng entry đã có, **không** đụng
binary. Diff chỉ được có phần **thêm vào**. Thấy dòng bị xoá ⇒ dừng và đọc lại.

## 5. Ghi lên — CHỈ `manifest.json`

```sh
scp -P "$SSH_PORT" manifest.new.json \
  "$SSH_USER@$SSH_HOST:$APP_DIR/public/downloads/workstation/manifest.json"
```

**Không** `rsync` cả cây. Binary không được đụng tới.

## 6. Đối chiếu lại từ production

```sh
sleep 3
curl -fsS --max-time 30 "$PROD_MANIFEST_URL" -o manifest.after.json
python3 .github/scripts/verify-manifest-matches.py \
  --expected manifest.new.json --actual manifest.after.json
```

Bước này **bắt buộc**. `scp` thành công chỉ chứng minh file đã đi; nó không
chứng minh thứ đang được phục vụ là file đó — cùng luật đã trả giá cả ngày
2026-08-16: *đo nội dung, đừng đọc trạng thái*.

## Hiện trạng — kiểm lại trước khi tin, kể cả trang này

**Đo 2026-08-16: manifest KHÔNG khuyết.** Bản đang phục vụ có **7 thế hệ**:

| version | commit | phát hành |
|---|---|---|
| v0.8.4 | `1f9a0ba73` | 2026-08-16 |
| v0.8.2 | `3e210f463` | 2026-08-15 |
| v0.8.1 | `e2bb90e5c` | 2026-08-14 |
| v0.8.0 | `ba9077575` | 2026-08-13 |
| v0.7.0 | `654ba1f9b` | 2026-08-13 |
| v0.6.0 | `d414f2bc3` | 2026-08-13 |
| v0.5.0 | `74528ec79` | 2026-08-13 |

Bản đầu của trang này viết *"đường lùi đang khuyết, manifest liệt kê đúng một"* —
chép từ header của `workstation-manifest-restore.yml`, tức phép đo **2026-08-14**,
mà **không kéo manifest thật về kiểm**. Đúng cái lỗi trang này đang dạy người
khác tránh.

Nên luật áp cho cả chính nó: **bảng trên cũng sẽ cũ.** Trước khi dùng quy trình
này, chạy bước 1 và **đếm thế hệ**; đừng tin con số chép trong tài liệu, trong
header workflow, hay trong một issue.

### Tra `commit` cho `meta.json` — hai nguồn đúng, một nguồn sai

Bước 3 đòi `commit` của từng bản. Lấy từ:

1. **chính `manifest.json` đang phục vụ** — bảng trên lấy từ đó;
2. `headSha` của lượt `workstation-release` **đã thành công** tương ứng.

**KHÔNG suy từ commit merge của lượt bump `VERSION`** — đã thử, **sai cả bốn
bản**. Và **không dùng git tag**: repo này toàn tag ngày (`v2026.8.5c`…), không
có semver cho các bản đó.

## Liên quan

- [Cổng xanh/đỏ vì nó KHÔNG CHẠY](cong-xanh-do-vi-khong-chay.md) — vì sao không
  nên để một đường khôi phục phụ thuộc CI
- [The automated issue loop (tal)](agent-issue-loop.md)
