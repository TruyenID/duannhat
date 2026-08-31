# Cổng xanh/đỏ vì nó KHÔNG CHẠY

Ngày 2026-08-16 repo này gặp **năm** ca cùng một họ trong một ngày: một cổng báo
xanh hoặc đỏ **vì bản thân nó không chạy được**, không phải vì nội dung nó canh.

Bốn lớp đầu là cổng **nói dối xanh**. Lớp thứ năm ngược hướng — cổng **nói thật
đỏ**, nhưng bị đọc thành nhiễu rồi cho qua.

Điều làm cả bốn nguy hiểm là chúng **đọc y hệt kết quả thật**. Một guard không
bao giờ chạy in ra log giống hệt một guard luôn đúng. Một job hết quota in ra
"failure" giống hệt một bài test hỏng. Một issue bị đóng nhầm đọc giống hệt một
issue đã xong.

Nên câu hỏi thường đặt — *"cổng này có đúng không?"* — không phân biệt được
chúng. Câu phân biệt được là:

> **Lượt chạy này CÓ chạm vào cổng không?**
> **Và thứ tôi đang đọc là NỘI DUNG hay chỉ là TRẠNG THÁI?**

## Bảng tra nhanh

| lớp | triệu chứng | bước tra nói thật |
|---|---|---|
| 1. `paths:` không phủ thứ guard canh | cổng **vắng mặt**, PR xanh | `npm run test:gate-paths` |
| 2. guard mồ côi — có script, không workflow nào gọi | **luôn xanh**, chưa bao giờ chạy | cùng rào trên |
| 3. hết quota Actions | **0 step, không log**, đọc y hệt test hỏng | `gh api repos/<owner>/<repo>/check-runs/<id>/annotations` |
| 4. tracker nói "xong" mà nội dung chưa vào | issue/PR `closed`, `dev` chưa có gì | grep thẳng nội dung trên `origin/dev` |
| 5. máy chạy cổng hỏng | **đỏ ngắt quãng**, chạy lại thì xanh — đọc y hệt flake | hỏi **bước nào đỏ**, đừng nhận dạng chuỗi lỗi |

---

## Lớp 1 — `paths:` loại trừ chính thứ guard canh

Guard đúng, nằm trong workflow đúng, nhưng workflow ấy lọc bằng `paths:` **không
chứa** thứ guard phát biểu về. PR đúng loại mà guard sinh ra để canh thì không
kích hoạt workflow, nên guard không chạy — và PR xanh.

Đã vấp ba lần:

- **#2874** — phép kiểm review-lease chỉ nằm ở `merge-batch`, đường ít bị dùng
  nhất; cả ba sự cố đều đi `gh pr merge`.
- **#2959** — rào version nằm sau `paths:` không có `workstation/**`. 23 file
  `workstation/` lên production, CI xanh suốt.
- **#2971** — `paths:` chỉ liệt kê **hai file workflow**, nên
  `deploy-xserver.yml` (chạy `migrate --force` + nhiều `db:seed` vào DB
  production) sửa được mà không rào nào chạy.

**Rào:** `scripts/gate-paths-cover-its-guards.test.mjs`. Mỗi guard khai nó canh
đường dẫn nào; `paths:` của **từng trigger** phải phủ được.

Không gộp `push` với `pull_request`. Bản đầu của rào ấy gộp, và phép thử ngược
cho thấy nó **không kêu** khi subject bị gỡ khỏi riêng `push`: bản sao bên
`pull_request` che mất. Nhưng đó đúng là hình dạng #2959 — cổng chạy lúc mở PR
rồi im lúc đẩy thẳng vào `dev`, mà đẩy thẳng mới là đường ra production.

## Lớp 2 — guard mồ côi

Script tồn tại, test bên trong đúng và xanh, **không workflow nào gọi nó**.

Phát hiện ở #2971 bằng phép đo cơ học — quét `package.json`, đòi mọi script
`test:*` phải được một workflow gọi. Lượt chạy đầu tiên đỏ ngay với **bốn** guard
mồ côi: `workflows-parse`, `omnify-check`, `ws-manifest-restore`, `ws-publish`.

Nối cả bốn vào CI thì **hai cái đỏ ngay**, và cả hai là báo động giả cùng kiểu:
chúng ghim `paths:` bằng **chuỗi nguyên văn**, nên khi bộ lọc được *mở rộng*
thành `.github/workflows/**` — rộng hơn hẳn, phủ đúng thứ chúng canh — chúng vẫn
đỏ.

> Đó là lý do guard mồ côi nguy hiểm: nó **đúng lúc viết ra**, rồi cây đổi dưới
> chân nó, và không ai biết vì nó chưa bao giờ chạy.

Hỏi *"paths có PHỦ không"*, đừng hỏi *"chuỗi này có xuất hiện không"* — dùng
`scripts/lib/workflow-paths.mjs` (`covers` / `pathsCover`).

**Bẫy kèm theo:** nếu bài kiểm đọc file workflow dưới dạng text thô, comment
giải thích trong file ấy thường **nhắc lại nguyên văn** cờ đang được canh
(`--no-renames`, `paths:`…). Đột biến gỡ cờ khỏi *lệnh* sẽ vẫn XANH vì chuỗi còn
sống trong comment. Đo trên phần lệnh đã bỏ comment.

## Lớp 3 — hết quota Actions

**Đây là lớp không ai nghĩ tới.**

Job đỏ sau ~3 giây với **0 step** và **không log**. Không step nào fail, vì không
step nào chạy. Nhìn từ `gh pr checks` nó đọc **y hệt một lượt test đỏ**, và
`gh run rerun` cho kết quả giống hệt — nên nó cũng không giống flake.

Cách duy nhất biết sự thật:

```sh
gh run view <run-id> --repo <owner>/<repo> --json jobs -q '.jobs[].databaseId' |
  while read id; do
    gh api repos/<owner>/<repo>/check-runs/$id/annotations -q '.[].message'
  done
# → The job was not started because an Actions budget is preventing further use.
```

Đo được 2026-08-16, cùng nội dung cây, cách nhau 9 phút:

| giờ (UTC) | kết quả |
|---|---|
| 05:23 | **success**, đủ bốn job web |
| 05:32 | **failure**, 0 step |

**Chỉ runner GitHub bị ảnh hưởng.** Đếm tại chỗ, đừng tin con số chép lại:

```sh
grep -rln "runs-on: ubuntu-latest" .github/workflows/
```

Lúc viết dòng này ra **hai** workflow, và hệ quả của chúng rất khác nhau:

| workflow | kích hoạt | mất gì khi hết quota |
|---|---|---|
| `web-apps` | mọi PR | bốn cổng `web/{admin,customer,pos}` + `app/kds` **mù** |
| `workstation-manifest-restore` | `workflow_dispatch` tay | **đường lùi của quán** không chạy được |

Cái thứ hai dễ bị bỏ sót vì nó không chạy hằng ngày. Nhưng manifest là thứ duy
nhất trả lời *"quán đang chạy bản nào"* và *"muốn lùi thì lùi về đâu"* — fleet là
hai máy Windows cài tay, không auto-update. Cần nó đúng lúc hết quota thì nó sẽ
đỏ với 0 step, và người đang xử lý sự cố sẽ đọc nhầm thành "workflow hỏng".

`arch-gate`, `workstation-go`, `omnify-gate`, `agent-loop-gate`, `promote-gate`
đều self-hosted và chạy bình thường. `backend-tests` có comment cấm tái lập
`ubuntu-latest` — đừng đếm nhầm nó vào danh sách trên.

Nên trạng thái đúng là "hai workflow mù, phần còn lại còn dùng được", không phải
"CI hỏng".

Nới quota là **quyết định billing của chủ dự án**. API billing không tra được
bằng token thường (`repos/.../actions/billing/usage` → 404; endpoint user-level
đòi scope `user`), nên **đừng hứa bao giờ nó mở lại**.

⚠️ **Bốn cổng đó đỏ thì không merge PR web, và không gọi nó là flake.**

## Lớp 4 — tracker nói "xong" trong khi nội dung chưa vào

Trạng thái issue/PR là thứ **người và công cụ ghi vào**, không phải thứ đo được
từ cây mã. Nó sai được, và sai im lặng.

Ca thật cùng ngày (#2974, sửa một placeholder i18n của pos-web): issue bị đóng
**hai lần trong năm phút**, PR bị đóng và nhánh remote bị xoá — trong khi nội
dung chưa hề vào `dev`:

```sh
git show origin/dev:web/pos/src/i18n/en.json | grep -c '{{sku}}'   # → 1, tức CHƯA sửa
```

Phép đo đúng là **grep thẳng nội dung trên `origin/dev`**, không phải đọc nhãn.

Hai điều cần biết khi lần lại một ca kiểu này:

- **Xoá nhánh head thì GitHub tự đóng PR mở từ nó.** Nên "PR đóng" và "nhánh bị
  xoá" thường là **MỘT sự kiện**, không phải hai — chỉ cần giải thích cú xoá.
  Đã trả giá ở #1413: closed lúc 01:58:50, head_ref_deleted lúc 01:58:51.
- **`tal gc` không phải nghi phạm mặc định.** `delete_merged_branches` chỉ lấy PR
  `--state merged`; một nhánh chưa từng có PR nào merge thì nó không chạm tới.
  Kiểm trước khi quy kết:

  ```sh
  gh pr list -R <owner>/<repo> --state all --json number,state,headRefName,mergedAt \
    -q '.[]|select(.headRefName=="issue-<n>")'
  ```

Và vì mọi session đẩy bằng **cùng một tài khoản GitHub**, `author` / `mergedBy`
**không** phân biệt được ai làm gì. Chủ thật của một issue nằm ở ledger `tal`
trong comment cuối, không suy ra được từ vùng file hay từ tên tài khoản.

## Lớp 5 — máy chạy cổng hỏng, và cái đỏ bị đọc thành flake

Bốn lớp trên là cổng **nói dối xanh**. Lớp này ngược hướng và vì thế cần một
phản xạ khác: cổng **nói thật đỏ**, tín hiệu đầy đủ, nhưng bị đọc nhầm thành
nhiễu rồi cho qua.

Đo được cùng ngày, sau khi `web-apps` chuyển sang self-hosted (#3001/#3004) và
runner gánh thêm bốn build web (Next.js × 3 + Vite):

```
The stream or file ".../backend/storage/logs/laravel-2026-08-16.log"
could not be opened in append mode: No space left on device
Tests: 3 failed, 467 passed
```

Chạy lại ⇒ **xanh**. Lượt `backend-tests` liền trước ⇒ **xanh**. Đĩa sát ngưỡng,
hỏng ngắt quãng.

**Vì sao nó nguy hiểm**: triệu chứng — đỏ ngắt quãng, chạy lại thì xanh — trùng
khít với định nghĩa flake. Với luật *"chạy lại xanh là flake"* thì nó được merge
qua mà không ai mở log.

**Phân biệt với flake thật**: flake thật đỏ ở **cùng một chỗ** (một assert, một
ca phụ thuộc thứ tự). Hạ tầng hỏng đỏ ở **chỗ khác nhau mỗi lần** — ghi log, tải
cache, checkout, một ca không liên quan gì tới nhau. Số ca đỏ cũng không nói lên
gì: ở ca trên là `3 failed, 467 passed`, một tỉ lệ trông rất giống "vài test mong
manh".

### Bước tra: hỏi BƯỚC NÀO đỏ, đừng nhận dạng chuỗi lỗi

Bản đầu của mục này dạy nhận chuỗi (`No space left on device`…). Cách đó hỏng,
và hỏng trong cùng một ngày: ba lốt do **cùng một** cái đĩa đầy gây ra, mà chỉ
**một** cái nhắc tới "space".

| lốt | chỗ đỏ |
|---|---|
| `No space left on device` khi ghi `laravel-*.log` | `arch-gate` |
| `RecursiveDirectoryIterator(/tmp/domain-guard-…): Failed to open directory` — 1 failed, 469 passed | `arch-gate` (đo bởi phiên khác trên PR #2999) |
| `ENOENT … open '/home/satoshi/setup-pnpm/package.json'` | `web/customer` trên PR #3021 |

Ai học thuộc hai mẫu đầu sẽ trượt mẫu thứ ba. Luật không phụ thuộc chuỗi:

> **Đừng tìm chữ "space"; hỏi BƯỚC NÀO đỏ.**
>
> - `checkout` · `setup-*` · tạo thư mục tạm ⇒ **hạ tầng**
> - trong `test` / `build`, **cùng một assert mỗi lần** ⇒ **mã**

Vế thứ hai mới là thứ phân biệt được, vì hạ tầng hỏng thì chỗ đỏ **đổi mỗi
lượt**. Đó cũng là lý do "chạy lại thấy xanh" bị đọc thành flake: nó *đúng là*
không lặp lại — chỉ là vì lý do khác.

Một dấu hiệu phụ rất rẻ: hạ tầng thường chết **trước khi mã của bạn chạy**. Ở ca
`setup-pnpm`, các bước `install` · `lint` · `typecheck` · `test` · `build` đều
`skipped`, và job đỏ sau **16 giây**.

### Ngoại lệ: hạ tầng hỏng NGAY TRONG `test`

Ca `RecursiveDirectoryIterator` đỏ **bên trong `test`**, tức rơi vào vế "mã" của
luật trên — nhưng nó vẫn là hạ tầng: thư mục tạm **tạo không thành** vì hết chỗ,
rồi test đọc nó và báo "không tồn tại".

Ba dấu hiệu cứu được người đọc:

- **một** test lẻ chết ở thao tác **I/O** (mở thư mục, tạo file tạm), không ở
  phép so nghiệp vụ;
- tỉ lệ kiểu **469/470** làm nó trông càng giống một ca lẻ mong manh;
- lượt sau assert **không lặp lại**.

Nói ngoại lệ này ra là bắt buộc — nếu không, luật mới chỉ dời điểm mù chứ không
xoá nó.

### Cổng đỏ ĐÚNG, nhưng thuộc một lượt chạy KHÁC

Một biến thể không dính gì tới máy hỏng, nhưng cho ra đúng cảm giác sai: bảng
checks trộn kết quả của **hai lượt chạy khác nhau**.

Gắn nhãn `full-suite` **sau** khi tạo PR sinh một lượt thứ hai từ sự kiện
`labeled`. Đo trên `origin/dev`:

| workflow | nghe `labeled`? |
|---|---|
| `backend-tests.yml` | **có** — và đây là nơi gác nhãn `full-suite` |
| `web-apps.yml` | **không** — không khai `types:`, nên mặc định `[opened, synchronize, reopened]` |

Hệ quả: `backend-tests` chạy lượt mới, `web-apps` **giữ nguyên kết quả cũ**. Bảng
checks đọc lên như *"PR này có một cổng đỏ"*, trong khi thật ra **không lượt chạy
nào vừa-đỏ-vừa-mới**.

- **Tránh**: gắn nhãn **ngay khi tạo PR**, đừng gắn sau.
- **Tra khi đã lỡ**: so `databaseId` của run giữa các cổng, đừng đọc màu. Hai
  cổng thuộc hai run khác nhau thì không so được với nhau.

### Bằng chứng chéo từ một nhánh khác

Phép đo rẻ nhất để tách *"máy hỏng"* khỏi *"mã hỏng"*: tìm một **nhánh khác**
chạy **cùng job, cùng runner, trong cùng khoảng thời gian**.

Ca thật: `app/kds` đỏ ở bước `install` trên một nhánh, năm bước sau `skipped`;
cùng job đó trên nhánh `issue-2935` chạy **40 giây và xanh**. Một mình lượt đỏ
không kết luận được gì; **cặp** thì có.

Mạnh hơn "chạy lại thử" ở hai điểm: không phải chờ, và không nhập nhằng với
flake — flake thì hai nhánh cùng đỏ ở **cùng một assert**, còn máy hỏng thì chỉ
nhánh xui mới trúng.

**Tín hiệu KHÔNG thiếu ở đây** — dòng lỗi đã nói thẳng. Thứ hỏng là **luật đọc
tín hiệu**. Nên cách vá đúng là mục này, không phải thêm một bước preflight đo
dung lượng vào mọi job nặng: vá một vấn đề của con người bằng máy móc là chữa
sai tầng, và nó làm mọi lượt chạy đắt thêm để bù cho một thói quen đọc.

⚠️ **Hệ quả mới sau #3004**: giờ **mọi** cổng nằm trên cùng một runner. Trước
đây đĩa đầy chỉ giết `backend-tests`; nay nó giết cả bốn cổng web lẫn
`omnify-gate`. Một sự cố hạ tầng đơn lẻ có thể làm đỏ toàn bộ bảng check, và
toàn bộ bảng ấy sẽ trông như "hôm nay CI dở chứng".

---

## Luật rút ra

1. **Rào phải chứng minh cả hai chiều.** Nó phải biết KÊU và biết IM. Một rào
   kêu oan không bị tranh luận — nó bị TẮT.
2. **Đột biến phải tự chứng minh là đã áp được.** Một đột biến không áp được cho
   ra đúng kết quả mà "rào bị mù" cho ra. Hai bẫy đã dính trong một lượt: `zsh`
   **không** word-split biến chưa trích dẫn (`node --test $T` nhận đúng một tên
   file rồi chết), và `paths:` viết flow-style một dòng nên đột biến theo *dòng*
   không khớp gì. Cả hai in ra "xanh" cho phép đo chưa bao giờ chạy.
3. **Đo NỘI DUNG, đừng đọc TRẠNG THÁI.** `git show origin/dev:<file>` trả lời
   được câu mà nhãn issue không trả lời được.
4. **Số 0 và sự vắng mặt đều là khẳng định cần bằng chứng.** "Không có gì đỏ"
   có thể nghĩa là không có gì hỏng — hoặc không có gì chạy.

## Liên quan

- [The automated issue loop (tal)](agent-issue-loop.md) — lease, máy trạng thái,
  cổng merge
- [The issue-loop skills](agent-loop-skills.md) — hai vai, luật nào được máy
  cưỡng chế và luật nào chỉ là chữ
- [Running the full suite so the result is provable](full-suite.md) — vì sao một
  lượt chạy trông-như-đủ không chứng minh được điều nó có vẻ chứng minh
