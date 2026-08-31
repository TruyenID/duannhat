---
name: issue-review
description: "Vai REVIEW của vòng lặp issue tự động (tal). Nhặt PR chờ review, giành lease review (bắt buộc khác session đã code), loại ngay PR không có gì mới, đọc diff từ nguồn, chạy test hẹp, kết luận theo Conventional Comments, rồi merge cả lô qua tal merge-batch. Chạy được ở cả Claude Code lẫn Codex. Dùng khi người nói chạy session review, review PR của bot."
---

# issue-review — vai REVIEW

Một lần gọi = **một PR**. Người/lệnh gọi bạn điều phối nhịp, không phải bạn.

CLI là `tal`: gọi trần khi plugin bật, hoặc `.claude/tools/agent-loop/tal` với bản trong
repo. Mọi lệnh chạy từ gốc repo.

**Chạy ở Claude Code hay Codex đều được** (#2369) — `.codex/skills/issue-review`
là symlink vào chính thư mục này. Khác biệt giữa hai agent (danh tính lease, vòng
lặp `codex exec`, fan-out chỉ có ở Claude) ghi MỘT chỗ: mục "Skill này chạy ở
agent nào" trong `issue-work`. Codex chạy một lượt review không tương tác:

```sh
codex exec --sandbox workspace-write \
  -c 'sandbox_workspace_write.network_access=true' \
  -c 'sandbox_workspace_write.writable_roots=["<đường-dẫn-repo>/.git"]' \
  'Chạy skill issue-review: nhặt PR đầu hàng đợi review và kết luận'
```

---

## Nguyên tắc số 1 — ĐỌC NGƯỢC LẠI, ĐỪNG TIN

Mọi lỗi đắt nhất của vòng lặp này đến từ việc tin một câu trả lời thay vì đọc lại nguồn:
một lệnh báo thành công mà không làm gì, một nhãn còn sót từ vòng trước, một comment nói
"đã xong" từ ba ngày trước. Không ai nói dối — công cụ và trạng thái đều có thể lệch.

**Sau mỗi lệnh đổi trạng thái, đọc lại từ nguồn sự thật.**

| Muốn biết | ĐỌC | KHÔNG tin |
|---|---|---|
| Thân PR hiện tại | `gh pr view <PR> --json body -q .body` | dòng success của `tal pr` |
| PR đã review chưa | nhãn trên **issue** (`gh issue view <N> --json labels`) | nhãn trên PR — nó sống sót qua push mới |
| Có gì mới kể từ lần review | `--json commits` so với comment verdict cuối | số `round` trong marker — nó không tăng khi pass |
| Việc này đã ai làm chưa | `git log` / `git grep` trên `origin/<base>` | comment issue nói "đã xong" |
| Diff thật | `gh pr diff <PR>` | phần "Đã làm" trong thân PR |
| Ai đang giữ gì | `tal status` | phỏng đoán |

Ba ca thật đã cắn vì bỏ bước này: `tal pr` nuốt `--body-file` mà vẫn exit 0 nên thân PR kẹt
ở vòng 1 và một PR **đã sửa** bị trả về oan hai vòng liền (#1335); nhãn `review-passed` sót
trên PR làm rào merge tưởng một bản chưa ai xem là đã xem; một issue bị kết luận "còn lỗi"
trong khi code đã ship ba ngày trước.

---

## Bước 0 — chính sách của repo

```sh
tal config
```

In ra bề mặt chính sách đã giải **kèm nguồn từng giá trị**: nhánh nền/phát hành, tên
nhãn, `riskDomains` (quyết định tier review), và đường dẫn `policyDocs` kèm ✓/✘.

**Đọc `policyDocs.review` và `policyDocs.test`**: checklist riêng của repo — chỗ đã từng
cháy, quy ước bắt buộc, bẫy của ngôn ngữ/framework repo dùng. Phần lớn giá trị của review
nằm ở đó; luật chung dưới đây chỉ là nền, và cố ý không phụ thuộc ngôn ngữ.
Không có file đó → nói ra, review theo nền, đề nghị người viết.

---

## Bước 1 — chọn PR, và LOẠI NGAY thứ không đáng review

```sh
tal gc
tal review-queue --json
```

Trước khi đọc bất cứ thứ gì, kiểm **có gì mới hay không**:

```sh
gh pr view <PR> --json commits,comments -q \
  '{last_commit: (.commits|last|.committedDate),
    last_verdict: ([.comments[]|select(.body|contains("tal:review verdict"))]|last|.createdAt)}'
```

- **`last_commit` cũ hơn `last_verdict`** → không có commit mới kể từ lần review trước.
  **BỎ QUA.** Nói rõ "bản này đã review"; nếu nó vẫn nằm trong `review-queue` thì nhãn đang
  lệch — **báo ra**, đừng chữa bằng cách review lại. Đây là rào chống lặp rẻ nhất, và là
  chỗ vòng lặp từng phí nhiều nhất: một PR đã có ba verdict cho ba bản mà cả ba ghi `round=1`.
- **Có commit mới** → tiếp.

```sh
tal review-claim <PR> --json
```

- exit **5** = PR do chính session này code. Tách vai là điều kiện để review có nghĩa —
  **không** dùng `--allow-self`. Chọn PR khác.
- exit **75** = session khác đang review. Bỏ qua, không chờ.
- PR đã merge/đóng → `tal` từ chối cả claim lẫn verdict (#2153): điểm blocking còn
  đúng thì nó đang nằm trên `dev` — mở issue MỚI, đừng ghi verdict lên PR đã khép.
- Hàng đợi rỗng → đọc cho đúng NGHĨA nào (#2172): `rỗng THẬT` = hết việc, kết thúc
  lượt; `đang bị session khác giữ` (kèm ai giữ, còn bao lâu) = việc vẫn còn, chỉ không
  phải của mình — nói rõ như vậy, đừng báo "hết việc", và ĐỪNG đụng vào ref của họ.

---

## Bước 2 — đọc từ NGUỒN

```sh
gh pr diff <PR>                                    # sự thật về code
gh pr view <PR> --json body -q .body               # thân PR THẬT
gh pr view <PR> --json commits -q '.commits[].messageHeadline'
gh issue view <issue> --comments                   # đã ai làm/kết luận gì chưa
```

**Thân PR có thể là bản CŨ.** `tal pr` nuốt im lặng `--body-file` khi PR đã tồn tại
(#1335), và vòng sửa **luôn** rơi vào đường đó. Nếu thân PR mô tả khác diff:

> **đừng kết luận tác giả chưa sửa.** Đó chính là cái làm PR #1318 bị trả về oan hai vòng.
> Xác định sự thật từ diff + commit message, rồi nêu chênh lệch dưới dạng `todo` về thân PR
> — **không** phải `issue (blocking)` về code.

**Vòng ≥ 2:** đối chiếu từng điểm `(blocking)` vòng trước. Điểm còn sót là điểm cũ lặp lại
— nói vậy, đừng trình bày như phát hiện mới. Điểm đã xử lý thì ghi nhận một dòng.

---

## Bước 3 — soi

**Đi hết `policyDocs.review` trước** — đó là nơi checklist theo DOMAIN của repo sống
(ví dụ ở repo này: tiền, thuế, thời gian nghiệp vụ, quy ước frontend, schema/codegen).
Skill này cố ý không
chép chúng: chúng khác nhau ở mỗi dự án, và chép là tạo ra hai bản sẽ trôi khỏi nhau.

Nền chung, đúng với mọi dự án:

- **Dữ liệu không sửa lại được**: bản ghi bất biến có bị ghi đè không; thao tác đảo
  chiều có idempotent theo id sự kiện không (webhook/retry giao lại là chuyện thường).
- **Cờ debug/bypass**: giá trị bật ghi cứng trong file được commit; guard theo môi trường
  bị nới thành biến mà production đặt được.
- **Bí mật / cấu hình của một máy** lọt vào file commit.
- **Di trú dữ liệu**: đổi schema mà không có migration đi kèm; đường DDL chỉ được kiểm
  trên engine của test chứ không phải engine production.
- **Thất bại im lặng**: thao tác đổi dữ liệu mà lỗi không đến được người dùng.
- **Test**: có test chứng minh hành vi mới, và test đó **thật sự chạy** (nằm trong
  testsuite, không phải một thư mục không ai gọi).

### Test XANH không phải bằng chứng — đòi CHIỀU NGƯỢC LẠI

Câu hỏi phải hỏi ở mọi PR có test mới: **gỡ bản sửa ra thì test này có đỏ không?**

Nếu thân PR không trả lời, đó là `question (blocking)` — và với PR chạm tiền, dữ liệu bất
biến, hay tính nguyên tử thì tự chạy lấy: revert đúng phần sửa trong cây đã trộn (xem
"Kiểm PR thì phải kiểm TRONG cây đã trộn PR"), chạy lại đúng file test đó.

**Revert bằng `cp` từ bản sao lưu, không bằng `git checkout -- <path>`** (#2700).
Lệnh kia khôi phục từ INDEX, nên nó xoá luôn mọi thay đổi CHƯA COMMIT của file —
và cây đã trộn thường có đúng thứ đó (lượt merge thử, bản vá đang thử nghiệm).
`cp <file> /tmp/x.good` trước, `cp /tmp/x.good <file>` + `diff -q` sau.

Năm test **vô nghĩa** đã lọt qua trong một phiên vì không ai chạy chiều ngược. Bốn kiểu
hỏng, nhận ra chúng nhanh hơn là chạy lại:

| Dấu hiệu trong diff | Vì sao vô nghĩa |
|---|---|
| Test đọc **mã nguồn** (`file_get_contents`, `substr_count`, regex trên file) | Nó ghim **VỊ TRÍ** của code, không ghim hành vi. Sẽ đỏ khi dời code đúng cách, và xanh khi hành vi hỏng mà chuỗi vẫn còn |
| Test **mock** đúng class/method chứa thứ đang được kiểm | Mock nuốt luôn guard. Guard bị mock thì test chứng minh mock hoạt động, không hơn |
| Tiêm lỗi bằng cách **đập môi trường** (`Schema::drop`, xoá bảng) | Lỗi thường rơi **sớm hơn** chỗ đang đo — ví dụ ở một guard đọc bảng đó trước khi tới đoạn cần kiểm |
| Đầu vào của test **không kích hoạt** nhánh đang kiểm | Gửi trùng giá trị đang có ⇒ guard "chỉ chạy khi ĐỔI" không chạy lần nào. Test đo một con số không liên quan |

Chỗ tiêm lỗi đúng là chỗ **nằm giữa** hai thứ PR đang buộc lại với nhau — model event, cổng
được inject. Tiêm ở đó ghim **thuộc tính**; mock một service thường chỉ ghim **cách cài đặt**.

### Bẫy của matcher/framework — ở `policyDocs.test`, không ở đây

Mỗi stack có một kiểu test "xanh vĩnh viễn" riêng: matcher biến thiên nuốt câu giải thích
thành đối số, assertion chạy trên engine khác production, harness gọi thiếu tham số. Danh
sách của repo NÀY nằm trong `policyDocs.test` — đọc trước khi review một PR có test mới,
và dùng nó để khoanh vùng đọc chứ không để kết luận.

Luật chung áp cho mọi stack: **matcher biến thiên nào cũng nuốt đối số thêm**, nên với bài
**ratchet** (cấm một thứ quay lại) thì đừng đọc mã — đòi bằng chứng: cho thứ đó quay lại,
test có đỏ không.

### Con số trong thân PR là ỨNG VIÊN — lấy mẫu mà kiểm

PR khai "N chỗ" thì **mở 2–3 chỗ ra xem**. Ba kiểu thổi phồng đã lặp lại:

1. khớp trong **comment/docblock**;
2. **cùng tên method, khác thứ** — `$model->update()` vs `$service->update()`;
3. **đúng cú pháp, sai phân loại** — CRUD mỏng hợp lệ bị đếm là nợ.

Sai một con số không chỉ làm báo cáo xấu, nó **điều hướng công việc**: ADR 0001 §1b ghi lại
một phép đo hỏng đã bịa ra chu trình 496 cạnh, và chu trình không tồn tại đó được viết vào
kế hoạch như hạng mục lớn nhất. Con số sai trong DoD ⇒ `issue (blocking)`.

**Tài liệu:**

```sh
tal docs-check <PR>
```

Là **gợi ý**, không phải rào. Chấm theo phạm vi PR:

| Loại thay đổi | Thiếu doc thì |
|---|---|
| Đổi **quy ước** (thứ tự lệnh bắt buộc, điều bị cấm) | `issue (blocking)` |
| Đổi **hành vi** người dùng/vận hành thấy được | `issue (blocking)` |
| Thêm một **cạm bẫy** mới | `issue (blocking)` |
| Đổi **API** mà không regen tài liệu API | `issue (blocking)` |
| Refactor nội bộ, đổi tên, thêm test | không cần — **đừng đòi** |
| **Tầng nền** của chuỗi tầng (schema trước, hành vi sau) | **chưa** cần — doc đi cùng tầng hành vi |

**Xung đột — phát hiện ở đây, đừng để nổ lúc merge.** Lease khoá theo **ISSUE**, không khoá
theo **FILE**, nên hai issue chạm cùng file là conflict **do thiết kế**:

```sh
gh pr diff <PR> --name-only | sort > /tmp/f1
for o in $(gh pr list --state open --json number,headRefName \
           -q '.[]|select(.headRefName|test("^issue-"))|.number'); do
  [ "$o" = "<PR>" ] && continue
  c=$(comm -12 /tmp/f1 <(gh pr diff $o --name-only | sort))
  [ -n "$c" ] && echo "PR #$o trùng: $(echo $c | tr '\n' ' ')"
done
```

Có trùng → ghi **thứ tự merge đề nghị** vào verdict. Người merge phải biết trước.

---

## Bước 4 — test HẸP, không full suite

Full suite chạy hàng chục phút. Chạy nó cho từng PR vừa tốn vừa **trả lời sai câu hỏi**:
điều cần biết trước khi vào base là *cả lô cùng nhau* có xanh không — hai PR xanh riêng lẻ
vẫn có thể đỏ khi nằm cạnh nhau, và đó chính là trạng thái base sẽ ở.

Ở đây chỉ chạy **test hẹp đủ để kiểm điều mình đang nghi**.

**Full suite là cổng `baseBranch → promotionBranch` (`tal config` in ra hai tên đó),
không phải cổng `PR → nhánh nền` (#1454).** Bước 6 KHÔNG
chạy nó nữa. Câu hỏi "cả lô cùng nhau có xanh không" vẫn được trả lời — bằng việc trộn thật
cả lô lên base trong cây tạm — chỉ là không trả lời bằng cách chạy hàng nghìn test mỗi lần
merge.

### Test đỏ ≠ PR sai. Loại trừ môi trường TRƯỚC

Ba dấu hiệu nói rằng đỏ là do **cách bạn chạy**, không phải do PR — gặp thì dừng và sửa
cách chạy, đừng viết finding:

| Dấu hiệu | Gần như chắc chắn là |
|---|---|
| đỏ **tức thì** (< 1s) với **0 assertion** | app chưa boot: thiếu vendor, thiếu `.env`, sai cwd |
| **mọi** test đỏ cùng một thông điệp | môi trường, không phải logic |
| lỗi nhắc đường dẫn ngoài cây bạn đang kiểm | autoload/symlink trỏ nhầm cây |

**Đọc dòng "Chạy:" của chính harness trước khi chạy nó.** Đã trả giá: chạy
`bash .githooks/pre-push_test.sh` **thiếu tham số `$1`** (đường dẫn hook) → biến `HOOK`
giải ra `/`, hai ca đỏ, và suýt bị báo thành lỗi của PR #1363. Gọi đúng
`bash .githooks/pre-push_test.sh .githooks/pre-push` thì **5/5 xanh**.

Và **luôn đo nền trước**: chạy cùng bộ test trên `origin/<base>` chưa trộn PR. `27 passed`
trước, `28 passed` sau, là bằng chứng; `28 passed` một mình thì không nói được gì.

### Kiểm PR thì phải kiểm TRONG cây đã trộn PR

`grep` chạy ở worktree chính là đang đọc `dev`, **không phải PR**. Đã trả giá: kết luận
#1378 "chưa gỡ hết `is_alcohol`" trong khi PR đã gỡ sạch — chỉ là tôi grep nhầm cây.

```sh
git worktree add -f --detach /tmp/rev-<PR> origin/<base>
git -C /tmp/rev-<PR> merge --no-edit <head-sha-cua-PR>
grep -rn "…" /tmp/rev-<PR>/…              # ĐỌC Ở ĐÂY
```

Cây tạm này dùng lại được cho nhiều PR: `git -C /tmp/rev reset --hard origin/<base>` giữa
hai lượt, nên chỉ phải chạy `setup` (xem `tal config`) **một lần**.

---

## Bước 5 — kết luận, rồi ĐỌC LẠI

Viết nội dung ra file, ghi **sha đã review** vào đó (vì `round` không phân biệt được bản):

```sh
gh pr view <PR> --json headRefOid -q .headRefOid | cut -c1-9
tal review-verdict <PR> pass    --body-file <file>     # hoặc: changes
```

**Bắt buộc đọc lại** — verdict là thứ điều khiển rào merge:

```sh
gh issue view <issue> --json labels -q '[.labels[].name]|join(", ")'
gh pr view <PR> --json comments -q '.comments|last|.body' | head -3
```

- `pass` → issue phải có nhãn review-passed, và vào lô chờ `merge-batch`.
- `changes` → issue phải có nhãn changes-requested (ưu tiên cao nhất hàng đợi code).
- Nhãn không đúng như trên → **nói ra**, đừng chạy verdict lần hai (sẽ thành verdict trùng,
  đúng cái đang phải chữa).
- Quá `maxAttempts` vòng chưa đạt → tal chuyển dead-letter. **Đừng review vòng nữa.**

Lease review tự nhả sau verdict.

---

## Bước 6 — merge cả lô

```sh
tal merge-batch --dry-run     # lô gồm PR nào
tal merge-batch               # trộn lô lên base trong cây tạm → merge cả lô
tal merge-batch --suite       # …và chạy full suite trước khi merge — CHỈ KHI ĐƯỢC YÊU CẦU
```

**MERGE TRƯỚC, suite SAU.** `merge-batch` (không suite) trộn cả lô lên dev
NGAY → full suite chạy MỘT lần trên dev-đã-gộp, ở nền → đỏ thì sửa tiếp trên
dev. Không giữ lô đứng chờ suite. Hai bẫy:
- `merge-batch --suite | tail` — pipe NUỐT exit code lẫn thông điệp chết;
  chạy nền thì redirect ra file, đọc file, đừng pipe.
- Kill một merge-batch đang chạy ⇒ **lock cổng thành xác**: lượt sau báo
  "session khác đang chạy cổng merge" vĩnh viễn. Chắc chắn nó chết rồi thì
  `tal unlock merge-batch --force` rồi chạy lại.

**Full suite là cổng `baseBranch → promotionBranch`, KHÔNG phải cổng `PR → nhánh nền`
(#1454).** Rào cưỡng chế bằng máy chỉ còn
**review đạt**. CI cũng không bị đòi mặc định: ở repo nào mà CI *chính là* full suite thì
đòi nó là bắt người chờ full suite qua đường vòng.

Vì sao đổi: đo trong một phiên, full suite chạy **ít nhất 6 lần**, 9-10 phút mỗi lần, và
bắt được đúng **một** thứ mà test hẹp của cùng thư mục cũng bắt được. Đổi lại nó khoá chết
một cặp PR bù nhau (#1444 ↔ #1450 — mỗi cái sửa đúng thứ làm cái kia đỏ) tới mức phải
`--force` mới ra. Ở đó cổng không bảo vệ gì; nó chỉ chặn.

**Giá trị thật của `merge-batch` không phải cái suite** — mà là trộn thật cả lô lên base
trong một cây tạm: chứng minh các PR đứng được cạnh nhau và không conflict. Phần đó
vẫn chạy mọi lần.

**Đánh đổi, biết trước:** một hồi quy chỉ-full-suite-thấy sẽ vào base. Đã xảy ra một lần
(#1414). Chủ repo đã cân giữa "chậm mỗi lần" và "thỉnh thoảng đỏ" và chọn tốc độ — cần
chắc thì `--suite`.

Không merge lẻ khi có thể gom lô. **`--force` chỉ khi người yêu cầu rõ.**

- Trộn conflict → PR đó bị **bỏ khỏi lô** (in ra rõ, và nó nhận một comment), các PR còn
  lại vẫn đi tiếp — một nhánh cũ không giữ con tin cả hàng đợi. Chủ PR bị bỏ phải merge
  base vào rồi chạy lại.
- Full suite đỏ → **không merge gì cả**; nói rõ test nào đỏ kèm output thật.
- Sau merge, đọc lại: `gh pr view <PR> --json state,mergedAt` và `git log origin/<base>`.

**Đọc đúng LOẠI thất bại (#1329).** Thông điệp nói thẳng, đừng đoán — hai loại này từng
lẫn vào nhau và lỗi sống nhiều ngày vì thế:

- **`CỔNG HỎNG` (exit 3)** = cây tạm không dựng được (thiếu `vendor/`, `node_modules`,
  `.env`). **Chưa test nào chạy, nên không kết luận gì được về PR.** Sửa
  `setup` / `setupVerify` trong `.claude/agent-loop.json`, đừng đi tìm test hỏng.
- **`full suite ĐỎ` (exit 2)** = test thật sự đỏ trên lô đã trộn. Đây mới là tín hiệu về code.

---

## Khi công cụ nói dối

Dấu hiệu: lệnh exit 0 nhưng đọc lại thấy không đổi. Đã xảy ra ba lần trong một ngày.

1. **Đừng chạy lại lệnh** — nó sẽ lại exit 0 và bạn mất thêm một vòng.
2. **Đi đường vòng bằng `gh`/`git` trực tiếp** để hoàn thành việc (ví dụ
   `gh pr edit <PR> --body-file <file>` khi `tal pr` không cập nhật thân).
3. **Mở issue cho lỗi công cụ đó**, kèm lệnh tái hiện và đoạn code nghi ngờ.
4. **Nếu lỗi nằm ở file đang có người giữ lease** (`tal status`) → **đừng sửa**. Bàn phần
   mình đã kiểm vào comment issue của họ. Hai bản sửa song song vào cùng file chính là vấn
   đề đang phải chữa.

---

## Không bao giờ

- **Không merge khi thiếu một trong hai điều kiện.** Không `--force`.
- **Không review PR của chính session mình.** Máy chặn ở CẢ BA cửa
  (`review-claim`, `review-verdict`, `merge` — #1397), và từ #2300 verdict còn đòi
  đang GIỮ lease `pr-<N>` với sha khớp bản đã claim. "Đã code" nghĩa là đã bàn
  giao PR qua `tal pr` — claim-để-đọc không làm mất quyền review (#2091). Vẫn tự
  giữ luật: rào máy là lưới cuối, không phải giấy phép thử.
- **Không tự merge PR của chính mình.** Nếu tình thế buộc phải (bản vá sửa chính công cụ
  đang hỏng, hoặc người dùng yêu cầu trực tiếp sau khi đã biết mức độ): **comment lên PR
  nói rõ đã phá luật, vì sao, và chỉ đúng chỗ đáng soi lại** — để một session sau còn
  review sau-merge được. Không giấu.
- **Không sửa code trong PR.** Bạn nêu vấn đề; session code sửa. Ghi vào worktree của issue
  sẽ bị hook chặn — đúng thiết kế.
- **Không phán khi chưa đọc file.** Không dựng được kịch bản sai cụ thể (input/state → kết
  quả sai) thì đó không phải `issue (blocking)`: hạ xuống `question` hoặc `suggestion`.
- **Không bịa vấn đề để tỏ ra đã làm việc.** Review sạch là kết quả hợp lệ — nói thẳng
  "không có điểm chặn".

---

## Định dạng — [Conventional Comments](https://conventionalcomments.org/)

`label (decoration): subject` rồi xuống dòng giải thích. Chỉ `(blocking)` mới chặn merge.

| label | dùng khi |
|---|---|
| `issue (blocking)` | lỗi thật, phải sửa mới merge được |
| `issue (non-blocking)` | vấn đề thật nhưng chấp nhận merge trước |
| `question` | chưa đủ căn cứ để phán là lỗi |
| `suggestion (non-blocking)` | có cách tốt hơn, không bắt buộc |
| `nitpick (non-blocking)` | sở thích, tuyệt đối không chặn |
| `praise` | chỗ làm tốt, nói thật lòng |
| `todo` | việc nhỏ nhưng phải làm |

Mỗi điểm chỉ rõ `file:line` + **kịch bản sai cụ thể**:

```md
issue (blocking): src/billing/refund.ts:84 — hoàn tiền hai lần khi webhook lặp

Không khoá theo `provider_event_id`, nên webhook giao lại (chuyện thường) tạo bản ghi hoàn
tiền thứ hai. Tiền ra khỏi két hai lần cho một giao dịch.

todo: thân PR còn là bản vòng 1 (thiếu phần lệnh test đã chạy) — #1335, không phải lỗi code.

nitpick (non-blocking): tên biến `$x` dòng 91 nên nói rõ nghĩa.
```

---

## Báo cáo cuối lượt

PR nào, sha nào, verdict gì, vì sao. PR bị loại vì "không có gì mới" cũng phải nói. Nếu có
merge thì nói lô gồm PR nào và full suite chạy ra sao. Không kể lại từng bước.

## PR cụm — một PR đóng nhiều issue là HỢP LỆ (#2178)

Từ #2178, vai code được gom nhiều issue cùng vùng file vào một PR (ví dụ #2183
đóng #2177+#2178+#2179). Khi review PR cụm: đối chiếu diff với TỪNG issue được
nêu — thiếu một issue trong diff không phải lỗi nếu body nói rõ phần đó dừng
có chủ đích (điều kiện dừng là kết quả hợp lệ). Đòi hỏi: mỗi thay đổi hành vi
phải chỉ được về một issue trong cụm; thay đổi không thuộc issue nào ⇒ hỏi.
Báo cáo review cũng theo hợp đồng bảng + số (`agent-loop-skills.md` §8).

## Tier model cho review: rủi ro ⇒ fable, còn lại opus đủ

> **Tên tier là của Claude Code.** Ở agent không chọn được model (Codex), phần
> chọn tier không áp dụng — nhưng **luật thì áp**: diff đụng `riskDomains` phải
> được review bằng cấu hình mạnh nhất bạn có, và nếu không có thì nói thẳng
> trong verdict rằng review chạy ở tier thấp hơn vùng rủi ro đòi hỏi.

**Rủi ro quyết định tier của REVIEW, không phải tier của người viết bản sửa.**

- Diff đụng một vùng khai trong **`riskDomains`** (`tal config`) thì session/agent
  review PHẢI là **fable** (bỏ trống `model` để kế thừa session fable), kể cả khi
  bản sửa do opus/sonnet viết. Đây là hàng rào cuối trước khi một thay đổi không
  sửa lại được lên production. Skill KHÔNG ghi cứng danh sách đó — vùng rủi ro của
  một hệ POS khác của một thư viện, và một danh sách chép trong skill sẽ trôi.
- PR thường (UI, docs, refactor một vùng, test cho hành vi sẵn có) — review
  **opus** là đủ; đừng đốt fable vào diff không có đường hỏng đắt.
- Phân vân ⇒ coi là rủi ro. Hợp đồng báo cáo không đổi theo tier.
