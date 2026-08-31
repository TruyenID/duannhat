---
name: issue-work
description: "Vai CODE của vòng lặp issue tự động (tal). Nhặt một issue — hoặc một CỤM issue cùng vùng file — giành lease nguyên tử, dựng worktree + branch issue-<số>, sửa, chạy test hẹp, mở PR rồi bàn giao cho session review. Chạy được ở cả Claude Code lẫn Codex. Dùng khi người nói chạy vòng lặp issue, xử lý backlog tự động, hoặc khi cần sửa tiếp một issue bị review yêu cầu sửa."
---

# issue-work — vai CODE

Một lần gọi = **một đơn vị việc**: một issue lẻ, hoặc một CỤM issue cùng vùng file
(xem "Gom CỤM"), hoặc — khi hàng đợi có nhiều cụm KHÁC vùng — vai **điều phối**
mở nhiều agent song song (xem "Điều phối NHIỀU agent" — **chỉ Claude Code**). Đừng
nhặt việc mới sau khi đơn vị việc của lượt này đã chốt; người/lệnh gọi bạn điều phối
nhịp giữa các lượt, không phải bạn.

CLI là `tal`: gọi trần khi plugin bật, hoặc `.claude/tools/agent-loop/tal` với bản trong
repo. Mọi lệnh chạy từ gốc repo, trừ khi đang ở trong worktree của issue.

## Skill này chạy ở agent nào — và khác nhau chỗ nào (#2369)

Nội dung thật nằm ở `.claude/skills/issue-work/`; `.codex/skills/issue-work` là
symlink trỏ vào đó. **Một nguồn, hai agent** — đừng nhân đôi file, hai bản sẽ trôi
lệch và không ai biết bản nào đúng.

| | Claude Code | Codex |
|---|---|---|
| Nạp skill | `.claude/skills/` | `.codex/skills/` (symlink) |
| Danh tính lease | `CLAUDE_CODE_SESSION_ID` | `CODEX_THREAD_ID` |
| Vòng lặp không tương tác | lệnh `/loop` | `codex exec` |
| Fan-out nhiều agent con | có | **không** — làm tuần tự từng cụm |

**Bẫy đã đo (#2369):** agent lồng nhau **thừa kế** biến danh tính của tiến
trình cha (đo được: `codex exec` mang theo cả `CLAUDE_CODE_SESSION_ID` của phiên
Claude gọi nó). Cha và con cùng khai một danh tính ⇒ lease thôi loại trừ. `tal`
**không** xếp hạng biến — mọi chiều lồng nhau đều có thật nên thứ tự tĩnh nào
cũng sai một chiều; nó **kết hợp** mọi biến đang có mặt, nên cha và con khác
nhau mà không cần biết ai lồng ai. Kiểm bằng `tal doctor` — dòng `session id`
in ra nguồn đang dùng, và cảnh báo riêng khi `TAL_SESSION` đang đè (một
`export` quên gỡ làm hai shell trùng danh tính).

Chạy một lượt bằng Codex, không tương tác:

```sh
codex exec --sandbox workspace-write \
  -c 'sandbox_workspace_write.network_access=true' \
  -c 'sandbox_workspace_write.writable_roots=["<đường-dẫn-repo>/.git"]' \
  'Chạy skill issue-work: nhặt issue đầu hàng đợi và làm tới khi mở được PR'
```

**HAI CỜ ĐÓ KHÔNG PHẢI TUỲ CHỌN** (#2400 — đo bằng ba lượt chạy thật):

| thiếu cờ | chết ở đâu |
|---|---|
| `network_access` | `gh` không phân giải được `github.com` ⇒ `tal queue`/`tal claim` chết ngay bước đầu |
| `writable_roots` có `.git` | sandbox loại trừ `.git` ⇒ `tal claim` không tạo nổi `refs/heads/issue-<n>.lock` |

Bản đầu của mục này (#2369) chỉ ghi `--sandbox workspace-write`, tức **chạy theo
là chết**. Cả hai lượt hỏng đó Codex đều xử đúng — không claim nửa vời, không
`--force`, không để lại lease/label dở — nên chúng vô hại; nhưng tài liệu sai
thì vẫn là tài liệu sai.


Vòng lặp thì gói lệnh trên trong `while`/cron của bạn — `codex exec` chạy MỘT
lượt rồi thoát, đó là hợp đồng của nó.

---

## Nguyên tắc số 1 — ĐỌC NGƯỢC LẠI, ĐỪNG TIN

Lỗi đắt nhất ở vai này không phải viết code sai. Là **làm lại việc đã có**, hoặc **tưởng đã
gửi thứ chưa gửi**. Cả hai đến từ việc tin một câu trả lời thay vì đọc lại nguồn.

| Muốn biết | ĐỌC | KHÔNG tin |
|---|---|---|
| Việc này đã ai làm chưa | `git log --all --grep="#<N>"`, `git grep` trên `origin/<base>` | mô tả issue, hay comment "chưa làm" |
| Bản sửa đã tới base chưa | `git merge-base --is-ancestor <sha> origin/<base>` | comment nói "đã ship" kèm sha |
| Thân PR mình vừa gửi | `gh pr view <PR> --json body -q .body` | dòng success của `tal pr` (#1335) |
| Lease mình đang giữ | `tal assert` | thẻ `.tal-lease.json` đọc bằng mắt |
| Regen có sinh migration | `git status backend/database/migrations/…` | "generate complete" |
| Ai đang giữ file mình sắp sửa | `tal status` | phỏng đoán |
| **Mình đang đứng ở thư mục nào** | `pwd` ở đầu output, hoặc `git -C <đường dẫn>` | `cd X && lệnh` — xem dưới |
| **Có bao nhiêu PR / issue đang mở** | hỏi lại bằng truy vấn **khác** khi kết quả là `0` | một lần chạy trả `0` |
| **Một sha có thật không** | `git cat-file -e <sha>^{commit}` | sha gõ tay |

**`cd` KHÔNG phải một cái rào.** `cd X && lệnh` mà `cd` trượt thì **lệnh vẫn chạy**,
ở thư mục cũ. Đã trả giá thật: một `git merge` chạy nhầm vào worktree chung đang ôm
WIP chưa commit của session khác, phải `git merge --abort` để cứu. Cùng lỗi lặp lại
lần hai trong cùng phiên.

```sh
git -C "$WT" merge origin/dev          # ĐÚNG — đường dẫn đi cùng lệnh
cd "$WT" && git merge origin/dev       # SAI — cd trượt thì merge vào chỗ khác
```

Worktree vừa bị `tal gc` xoá, hoặc đăng ký worktree cũ còn sót, đều làm `cd` trượt —
và cả hai xảy ra thường xuyên trong vòng lặp này.

**Số `0` là một khẳng định, không phải mặc định.** `gh pr list … -q length` trả `0`
một lần thì chưa đủ để nói "không còn PR nào". Đã trả giá: báo với người dùng "0 PR
mở ở cả 8 repo" trong khi đang có **13**. Trước khi báo một con số 0 ra ngoài, hỏi
lại bằng đường khác (`--state all` rồi lọc, hoặc liệt kê thay vì đếm).

Trong chín lượt đầu của vòng lặp này, **bốn** lượt phát hiện issue đã xong hoặc không có
việc — và mỗi lần đều nhờ đọc comment + kiểm trên `origin` trước khi sửa. Một lượt nữa suýt
dựng lại một bản sửa đã ship ba ngày trước.

---

## Bước 0 — chính sách của repo

```sh
tal config
```

In ra bề mặt chính sách đã giải, **kèm nguồn của từng giá trị** (`env` / `config` /
`mặc định`): nhánh nền và nhánh phát hành, tên nhãn, `formatCmd`, `riskDomains`, và
đường dẫn `policyDocs` kèm dấu ✓/✘ cho từng file.

**Đọc hết `policyDocs`** (`work`, `test`) trước khi sửa dòng đầu tiên: thứ tự lệnh bắt
buộc, cạm bẫy của ngôn ngữ/framework repo này, cách chạy test theo vùng. Skill này chỉ
mang **cơ chế**; mọi thứ thuộc về một dự án cụ thể sống trong `agent-loop.json` +
`policyDocs`. Thiếu file → nói ra, làm theo luật chung, đề nghị người viết.

Skill này KHÔNG ghi cứng tên nhánh, tên lệnh test, lệnh formatter hay vùng rủi ro —
lấy từ `tal config`. Ghi cứng là thứ làm nó chỉ chạy được ở một repo.

---

## Bước 1 — hàng đợi, và tránh đụng nhau

```sh
tal gc
tal queue --json
tal status          # ai đang giữ gì
```

Hàng đợi **đã xếp sẵn thứ tự đúng** — lấy **phần tử đầu tiên**, đừng chọn theo cảm tính.
Thứ tự: nhãn changes-requested (sửa theo review) trước mọi thứ → severity → số nhỏ trước.

**Lease khoá theo ISSUE, KHÔNG khoá theo FILE.** Hai issue chạm cùng file là conflict do
thiết kế. Trước khi nhặt:

- **Kiểm PR ĐANG MỞ, không chỉ kiểm lease.** Lease hết hạn sau TTL, còn PR thì sống tới khi
  merge — nên `tal status` **không đủ**. Một PR mở đã sửa file bạn sắp sửa là conflict chắc
  chắn, dù không lease nào còn sống:

  ```sh
  for o in $(gh pr list --state open --json number,headRefName \
             -q '.[]|select(.headRefName|test("^issue-"))|.number'); do
    echo "PR #$o: $(gh pr diff $o --name-only | tr '\n' ' ')"
  done
  ```

  File bạn sắp sửa nằm trong danh sách đó → chọn issue khác, hoặc bàn vào PR ấy. **Tuyệt đối
  không viết lại một file đang có PR mở chạm tới** — tôi đã làm đúng điều đó và tự tạo ra một
  conflict cho người khác dọn.
- Xem `tal status`: lease đang sống thì càng chắc chắn phải tránh.
- **Issue sửa chính vòng lặp** (`tal`, các skill `issue-*`) thì làm **tuần tự, một cái một
  lúc**. Đó đang là file nóng nhất repo. Thấy có người giữ → **đừng mở bản sửa thứ hai**;
  bàn phần mình đã kiểm vào comment issue của họ.

Hàng đợi rỗng → nói rõ, kết thúc lượt. Issue chỉ vào hàng đợi khi người đã gắn nhãn `ready`
— thiếu nhãn đó là **cố ý**, đừng `--force` để lách.

---

## Bước 2 — giành issue, rồi đọc lại

```sh
tal claim <N> --json     # → worktree, branch, epoch, group, attempts
tal assert               # đọc lại: mình có thật đang giữ không
cd <worktree>
```

- exit **75** = issue đã có chủ. **Không phải lỗi.** Nhặt issue kế tiếp, hoặc kết thúc lượt.
- Sub-issue không có branch riêng: tal tự leo lên parent và giành **cả nhóm** kiểu
  all-or-nothing. Muốn branch riêng cho một sub-issue → `tal claim <sub> --split`.
- **Không sửa một dòng nào trước khi claim thành công.** Hook `PreToolUse` sẽ chặn nếu bạn
  ghi vào worktree của issue mình không giữ, nhưng đừng dựa vào hook — nó là rào cuối.

---

## Bước 3 — KIỂM ĐÃ CÓ AI LÀM CHƯA, trước khi viết gì

Đây là bước tiết kiệm nhiều nhất. Bỏ nó là nguy cơ ghi đè việc của người khác.

```sh
gh issue view <N> --comments          # ĐỌC HẾT, không đọc mỗi cái đầu
git log --oneline --all --grep="#<N>" # đã có commit nào nhắc issue này chưa
gh pr list --state open --search "issue-<N>"   # ĐÃ CÓ PR MỞ CHƯA — xem dưới
```

**Issue đã có PR mở thì KHÔNG claim.** Việc đã có người làm; claim vào chỉ ghi đè
quyền tác giả trong ledger, và hệ quả không hiện ra ngay: từ lúc đó `tal review-claim`
coi bạn là người đã code PR đó và **từ chối cho bạn review nó** — kể cả khi bạn không
viết một dòng nào. Đã trả giá thật ở #1353/#1363. Thay vào đó:

- PR đang chờ review → đổi vai, review nó (skill `issue-review`);
- PR bị trả về mà chủ cũ im lặng → claim là đúng, nhưng comment nói rõ mình tiếp quản.

Với mỗi sha mà comment khai là "đã sửa": **tự kiểm nó đã tới base chưa**, đừng tin:

```sh
git merge-base --is-ancestor <sha> origin/<base> && echo "CÓ trên base" || echo "CHƯA"
```

Bốn kết cục, chọn đúng một:

| Tình huống | Làm gì |
|---|---|
| Code đã có trên base, checklist đã xong | `tal release --state no-op --note "<đã kiểm gì>"` + comment nêu **cách kiểm**, không chỉ kết luận |
| Còn việc, nhưng chờ người quyết / chờ ops / chờ cửa sổ thời gian | `tal release --state blocked --note "…"` + comment nói rõ **chờ ai / chặn bởi gì** |
| Phạm vi quá lớn cho một PR (nhiều repo, nhiều tầng) | tách **sub-issue theo tầng**, parent thành tracker (`blocked`, bỏ nhãn `ready`), rồi làm tầng đầu |
| Có việc thật, vừa một PR | tiếp bước 4 |

Vòng **sửa theo review**: đọc verdict mới nhất trên PR, xử lý **hết** điểm `(blocking)`.
Điểm `(non-blocking)`/`nitpick` được phép bỏ — nhưng **nói rõ bỏ cái nào và vì sao**.

### Con số từ `grep` là ỨNG VIÊN, không phải phép đo

Trước khi đưa một con số vào issue, PR hay Definition of Done: **mở từng chỗ ra xem**. Một
phiên đã công bố ba con số sai liên tiếp, mỗi lần sai theo một kiểu khác:

| công bố | thật | cái grep nhầm |
|---|---:|---|
| 106 chỗ ghi DB trong controller | **65** | 39 chỗ là `$this->service->update(...)` — gọi SERVICE, không phải model |
| "7 chỗ dễ" | **2** | 5 chỗ là vòng lặp / vắt qua hai service, không phải dời một dòng |
| 32 chỗ rổ B | **11** | 3 khớp trong **comment**, 18 là CRUD mỏng hợp lệ |

Ba kiểu nhầm lặp đi lặp lại, kiểm cả ba mỗi lần:

1. **Khớp trong comment/docblock.** `sed -n '<dòng>p' <file>` từng chỗ. Một lần còn là
   comment do **chính mình** vừa viết ở issue trước rồi hai giờ sau đếm nó là nợ.
2. **Cùng tên method, khác thứ.** `$model->update()` với `$service->update()` trông y hệt
   với `grep '\->update('`.
3. **Đúng cú pháp, sai phân loại.** `$user->update(['locale' => $x])` là CRUD mỏng hợp lệ,
   không phải "nghiệp vụ đặt sai chỗ". Đếm nó là nợ rồi đi "sửa" là làm cho đủ chỉ tiêu.

Một chỉ số sai không chỉ khiến con số xấu — nó **điều hướng công việc**. ADR 0001 §1b ghi
lại một lần một phép đo hỏng đã bịa ra chu trình 496 cạnh và chu trình không tồn tại đó
được viết vào kế hoạch như hạng mục lớn nhất. Luật rút ra nằm ngay trong ADR:

> **Trước khi refactor để thoả một chỉ số, hãy kiểm chính chỉ số đó.**

---

## Bước 4 — sửa

Theo `policyDocs.work` và mọi CLAUDE.md/AGENTS.md của thư mục đang chạm. Commit theo
Conventional Commits. Gọi `tal renew` sau mỗi bước dài (mỗi lần chạy test, mỗi ~10 phút) —
TTL tính theo đồng hồ server, im lặng quá hạn là bị thu hồi giữa lúc đang làm.

**Tên branch/worktree chỉ được là `issue-<số>`.** Cần thêm nhánh → **mở sub-issue** rồi
`tal claim <sub> --split`. Hook chặn mọi tên khác.

**Chỉ sửa trong worktree mà `tal claim` in ra.** Không `cd` về repo gốc để sửa — ở đó có
thể đang có WIP của session khác. Không bao giờ commit lên base branch.

---

## Bước 5 — test HẸP

**CẤM chạy full suite.** Vòng code lặp nhiều lần; mỗi lần full suite ăn hết lease và tốn
vô ích. Chạy đúng phạm vi mình chạm — lệnh cụ thể trong `policyDocs.test`; không có file đó
thì suy ra từ repo (theo filter / theo thư mục / theo package, **không** toàn bộ).

**Full suite là cổng `baseBranch → promotionBranch`** — `tal config` in ra đúng hai tên
đó. KHÔNG phải cổng của PR này, cũng không phải cổng merge vào nhánh nền (#1454). Đo
trong một phiên: nó chạy ít nhất 6 lần, 9-10 phút mỗi lần, và bắt được đúng một thứ mà
test hẹp của cùng thư mục cũng bắt được.

**Formatter (`formatCmd` trong `tal config`) chạy MỘT lần, bằng tay, ngay trước khi
commit.** Không chạy lại ở mỗi bước, mỗi worktree.

Mở rộng phạm vi **chỉ khi chính bạn thấy dấu hiệu lan** (sửa migration, sửa model dùng
chung, chạy codegen) — và nói rõ trong PR vì sao phải mở rộng.

Chạy **thật**. Test đỏ mà không sửa được thì **nói ra trong PR**, đừng mở PR khoe xanh. Ghi
vào thân PR **đúng lệnh đã chạy và kết quả thật**.

Cạm bẫy theo ngôn ngữ/framework (engine test khác engine production, matcher nuốt thông
điệp, migration chạy trước mỗi test) nằm ở `policyDocs.test` — đọc trước khi tin một lượt
xanh.

### Test XANH chưa chứng minh gì — CHẠY CHIỀU NGƯỢC LẠI

**Gỡ bản sửa của mình ra, chạy lại test, xem nó có ĐỎ không. Rồi trả lại.**

Không có bước này thì "test xanh" chỉ nói rằng test **chạy được**, không nói rằng nó **đo
đúng thứ mình vừa sửa**. Trong MỘT phiên, nghi thức này bắt được **năm** test vô nghĩa —
bốn trong số đó do chính người viết bản sửa tạo ra, và cả năm đều "xanh" trước khi kiểm:

| test tưởng là bằng chứng | vì sao nó vô nghĩa |
|---|---|
| ghim tính nguyên tử của sync-UP | nó `substr_count` chuỗi `DB::transaction` trong **file controller** — tức ghim VỊ TRÍ, không ghim tính nguyên tử. Đỏ khi wrapper dời xuống service dù không có gì đổi |
| ghim rollback của fork IAM | `Schema::drop` làm guard ném **trước** lúc fork, nên lỗi không bao giờ rơi vào chỗ đang đo |
| ghim guard số dư Stripe | mock nuốt đúng cái method chứa guard |
| ghim số truy vấn của guard giữa ca | PATCH gửi **trùng giá trị đang có** nên không guard nào chạy |
| ghim luật payload hộp thư | chạy trên pilot chứ không chạy trên đường thật |

**SAO LƯU TRƯỚC KHI TIÊM, khôi phục bằng `cp` — KHÔNG bằng `git checkout --`.**

```sh
cp <file> /tmp/x.good          # TRƯỚC khi tiêm
…tiêm lỗi, chạy test, xem ĐỎ…
cp /tmp/x.good <file>          # trả lại
diff -q <file> /tmp/x.good     # xác nhận đã về nguyên trạng
```

`git checkout -- <path>` khôi phục từ **INDEX**, nên trong một worktree issue —
nơi việc chưa commit là trạng thái BÌNH THƯỜNG giữa lúc làm và lúc `tal pr` — nó
**xoá sạch mọi thay đổi chưa commit của file đó**, không chỉ dòng vừa tiêm vào.
Đã trả giá hai lần trong MỘT phiên: lần đầu mất bản sửa của chính mình (gõ lại
được), lần sau **mất phần việc chưa commit của một agent con** — mã không nằm
trong đầu ai, không có bản sao trên đĩa, build gãy 9 lỗi, phải nhờ chính agent đó
dựng lại từ transcript (#2700).

Ghi **cả hai chiều** vào thân PR:

```
gỡ <thứ vừa sửa> ra  → ĐỎ: <tên test + thông điệp>
trả lại              → XANH: <số test>
```

Chọn chỗ tiêm lỗi cho đúng: nó phải rơi **giữa** hai thứ mình đang buộc lại với nhau. Tiêm
ở seam mà cả hai đều đi qua (model event, cổng được inject) thì ghim **thuộc tính**; mock
một service thì thường chỉ ghim **cách cài đặt** — và mock chính thứ chứa guard là tự bịt
mắt mình.

### Bẫy theo NGÔN NGỮ/FRAMEWORK — ở `policyDocs.test`, không ở đây

Matcher biến thiên nuốt thông điệp, engine test khác engine production, migration
chạy trước mỗi test — mỗi stack một kiểu. Chúng sống trong `policyDocs.test` của repo
chứ không trong skill; đọc file đó trước khi tin một lượt xanh.

Luật chung áp cho mọi stack: **trước khi truyền thông điệp cho một matcher, kiểm chữ
ký của nó** — matcher biến thiên nào cũng nuốt đối số thêm. Và bài test là **ratchet**
(cấm một thứ quay lại) thì nghi thức chiều-ngược ở trên là bắt buộc: cho thứ đó quay
lại thật, xem có đỏ không.

---

## Bước 6 — mở PR, rồi ĐỌC LẠI THÂN PR

```sh
tal assert
tal pr --title "fix(scope): …" --body-file <file>
```

`tal pr` tự: push `--force-with-lease` → mở/cập nhật PR → chèn
`Closes #N` cho cả nhóm → nhả lease → chuyển issue sang chờ review.

**BẮT BUỘC đọc lại thân PR.** `tal pr` nuốt im lặng `--body-file`/`--title` khi PR **đã tồn
tại** (#1335) và vẫn báo thành công — vòng sửa **luôn** rơi vào đường đó:

```sh
gh pr view <PR> --json body -q .body | head -5     # đúng cái mình vừa gửi chưa?
gh pr edit <PR> --body-file <file>                 # workaround khi chưa
```

Bỏ bước này thì thân PR kẹt ở vòng 1, reviewer đọc mô tả sai và trả về một PR **đã sửa** —
đã xảy ra hai vòng liền trên PR #1318.

Thân PR phải có: **sửa gì**, **vì sao**, **lệnh test đã chạy + kết quả thật**, **phần cố ý
không làm**.

**Tài liệu là một phần của "xong" — và từ #1639 `tal pr` TỰ kiểm trước khi nhả lease.**

Không còn phải nhớ gọi `tal docs-check` sau `tal pr`: nếu một luật trong `docsRules` bị kích
hoạt mà file doc tương ứng không có trong diff, `tal pr` **giữ lease** và in ra thiếu cái gì.
Sửa doc, commit, chạy lại `tal pr` — PR cập nhật tại chỗ.

Trước #1639 thứ tự này **không chạy được**: lease đã nhả khi PR mở, nên phát hiện của
docs-check rơi vào một worktree bị hook chặn ghi, và cách duy nhất để sửa trong lượt là
`tal claim --force` — lách một cổng đang chặn, tức luật cấm.

```sh
tal docs-check <PR>     # vẫn dùng được: xem TOÀN CẢNH, gồm cả hai kiểm chung
```

Chỉ `docsRules` mới giữ lease. Hai kiểm chung ("đổi N file code mà không chạm .md nào") kêu
ở gần như mọi PR nên chúng chỉ là gợi ý — biến chúng thành rào là dạy người ta bỏ qua rào.

Thay đổi thật sự không cần doc thì `tal pr --docs-ok`: nó nhả lease **và** ghi vào PR đúng
những luật đã bỏ qua, để reviewer đọc được và chặn nếu bạn sai. Đừng chỉ viết trong thân PR
rồi mong reviewer tự đối chiếu.

---

## Khi công cụ nói dối

Dấu hiệu: lệnh exit 0 nhưng đọc lại thấy không đổi.

1. **Đừng chạy lại lệnh** — nó sẽ lại exit 0.
2. **Đi đường vòng bằng `gh`/`git` trực tiếp** để hoàn thành việc.
3. **Mở issue cho lỗi công cụ**, kèm lệnh tái hiện và đoạn code nghi ngờ.
4. **Nếu lỗi ở file đang có người giữ lease** → **đừng sửa**; bàn vào comment issue của họ.

---

## Không bao giờ

- **Không sửa gì trước khi `tal claim` thành công.**
- **Không push khi `tal assert` fail.** Lease đã cấp cho người khác thì commit của bạn là
  rác — DỪNG, báo lại, đừng biến xung đột thành hỏng dữ liệu.
- **Không merge.** Merge là việc của vai review, qua `tal merge-batch`, khi review đạt.
- **Không commit WIP của người khác.** Stage **đường dẫn tường minh**, không `git add <dir>`,
  không `git add -A` ở worktree chung.
- **Không `--no-verify`, không `--force`, không `--skip-*`** để vượt một cổng đang chặn.
  Cổng đang chặn vì có thứ thật cần xử lý.
- **Không đổi tên branch khỏi `issue-<số>`.**
- **Không gõ tay một sha.** Luôn lấy bằng `$(git rev-parse …)` và xác nhận bằng
  `git cat-file -e <sha>^{commit}` trước khi dùng — nhất là với
  `git update-index --cacheinfo`, nơi một sha bịa tạo pointer trỏ vào hư không mà
  git **không kêu một tiếng nào** lúc ghi. Suýt xảy ra thật khi giải conflict gitlink.
- **Không sửa file tracked ở worktree chung để tự gỡ kẹt.** Nếu buộc phải (công cụ
  hỏng đến mức không chạy được lệnh nào): sửa tối thiểu, **trả lại ngay** bằng
  `git checkout -- <path>`, và kiểm `git status` sạch trước khi làm tiếp. Session
  khác đang làm trong cùng cây đó.
- **`git checkout -- <path>` CHỈ dùng khi file KHÔNG mang thay đổi chưa commit.**
  Nó khôi phục từ index, nên ở worktree issue nó xoá luôn việc chưa commit. Phép
  kiểm là `git status --short <path>`, không phải trí nhớ. Muốn hoàn tác một lượt
  tiêm lỗi thì `cp` từ bản sao lưu (xem nghi thức chiều-ngược).

---

## Báo cáo cuối lượt

Issue nào, PR nào, lệnh test nào + kết quả, còn treo gì và chờ ai. Nếu kết cục là
`no-op`/`blocked`/tách tầng thì nói **đã kiểm bằng cách nào** — một kết quả âm có bằng
chứng vẫn là một kết quả. Không kể lại từng bước.

## Gom CỤM issue theo vùng file (#2178 — đọc trước khi claim)

Mặc định KHÔNG còn là 1-issue-1-worktree. Trước khi claim, nhìn hàng đợi: nhiều
issue chạm **cùng vùng file** ⇒ claim issue CHÍNH, làm cả cụm trong MỘT worktree,
MỘT lượt cổng, MỘT PR — body ghi rõ đóng từng issue (nhánh `dev` không tự đóng
qua keyword: đóng tay kèm ship-note sau merge). Mỗi worktree tốn nguyên một
lượt dựng môi trường (`setup` trong `tal config` — vendor, node_modules, .env) — đó là khoản cụm tiết
kiệm được.

Ràng buộc:
- Việc cùng-file phải TUẦN TỰ trong cụm (golden/parity ghi đè nhau nếu song song).
- Nhánh vẫn `issue-<số>` của issue chính — hook chặn tên khác.
- Ví dụ mẫu: #2062+#2064+#2065 (một lượt), #2177+#2178+#2179 (PR #2183).

**Hợp đồng báo cáo cho agent con** (nếu bạn fan-out): bảng + số + file:dòng,
4 field bắt buộc — sửa gì · phép đo · pass/fail thật · kết quả kiểm đột biến.
Cấm tường thuật. Tái xác minh của bạn = spot-check 1–2 claim nặng nhất bằng phép
đo trực tiếp, KHÔNG dựng lại điều tra — nhưng cũng đừng bỏ: một phiên đã bác
4 claim agent + 1 rào rỗng.

Chi tiết + số đo: `docs/guide/agent-loop-skills.md` §8.

## Điều phối NHIỀU agent song song

> **CHỈ CLAUDE CODE.** Mục này cần công cụ mở agent con. Codex không có nó —
> nếu bạn là Codex, bỏ qua mục này và làm **tuần tự từng cụm**, mỗi cụm một lượt
> `codex exec`. Mọi luật khác trong tài liệu này áp cho cả hai.

Hàng đợi có ≥2 cụm **khác vùng file** ⇒ đừng làm tuần tự: session chính chuyển vai
**điều phối**, mở một agent cho MỖI cụm, chạy song song, tổng hợp một lần.

Luật vai điều phối:

1. **Điều phối KHÔNG tự code khi đang fan-out.** Việc của bạn: claim lease từng
   issue chính **kèm `--region <path>` cho từng vùng sẽ đụng** (#2270 — rào máy
   chặn hai session điều phối đụng cùng file qua hai issue khác nhau; bị từ chối
   vì chồng vùng = có người đang giữ, chọn cụm khác thay vì `--force`),
   dựng worktree, PHÂN VÙNG file tuyệt đối rời nhau cho từng agent
   (ghi rõ danh sách DO-NOT-TOUCH trong prompt), phóng tất cả agent **trong một message** (chạy song song), rồi
   với từng báo cáo: **commit → spot-check → PR → merge**. Commit là việc của điều
   phối, KHÔNG phải của agent. **COMMIT TRƯỚC khi spot-check** (#2700): agent giao
   lại một cây đầy việc CHƯA COMMIT, nên mọi thao tác hoàn tác trong lúc kiểm —
   nhất là `git checkout --` — đều có thể xoá trắng phần việc đó. Commit xong thì
   sai lầm tệ nhất chỉ còn là một commit phải sửa, không phải mã bốc hơi. Nếu
   spot-check lộ vấn đề thì `git commit --amend` hoặc commit thêm; đừng để cây ở
   trạng thái không thể khôi phục — trước mỗi cụm lệnh ghi phải kiểm
   `git -C <W> rev-parse --abbrev-ref HEAD` và `--show-toplevel` (worktree mồ côi).
2. **Hai agent không bao giờ chung một file.** Cùng worktree được, nếu vùng rời
   nhau thật. Cùng-file ⇒ tuần tự, hoặc SendMessage giao thêm cho agent đang
   giữ vùng đó thay vì tự sửa chen ngang.
3. **Chi phí sàn**: mỗi agent nạp ~20–30k token file luôn-nạp trước khi làm gì.
   Việc < ~15 phút tự làm rẻ hơn là thuê agent. Fan-out đáng khi mỗi nhánh việc
   là một cụm thật sự (nhiều file, có gate riêng).
4. Agent chết/treo: đọc partial output trước khi phóng lại — đừng nhân đôi việc.
5. **Lease sống 45 phút — fan-out dài thì `tal renew` khi mỗi agent về.** Hook
   chặn ghi vào worktree có lease quá hạn và chặn theo CWD của shell, nên
   claim-lại phải chạy từ GỐC repo, trong một lệnh Bash RIÊNG trước đó.
6. **`tal pr` NHẢ lease** — trước `tal merge` phải `tal claim <n> --force` lại.
   Sau merge, ref lease có thể thành mồ côi
   ("sổ không ghi ai giữ") ⇒ `tal unlock <key> --force` rồi mới claim tiếp được.
7. **MERGE TRƯỚC, suite SAU** (cả vai điều phối lẫn review): lô đã
   review-passed thì `tal merge-batch` KHÔNG `--suite` — merge ngay, full suite
   chạy MỘT lần trên dev-đã-gộp ở nền, đỏ thì sửa tiếp trên dev. `--suite` chỉ
   khi người yêu cầu đích danh (#1454).

### Phân tầng model: LÀM = opus mặc định · RỦI RO + REVIEW rủi ro = fable · cơ học = sonnet

| Tier | Dùng cho |
|---|---|
| **`sonnet`** | việc CƠ HỌC, khuôn có sẵn, tiêu chí pass/fail tự chấm được: rename theo danh sách, sửa link/label docs hàng loạt, thêm khoá i18n theo mẫu, sửa fixture theo khuôn đã chỉ, viết doc từ NGUỒN đã chỉ định, chạy một gate rồi báo số, quét grep + lập bảng ứng viên |
| **`opus`** | **MẶC ĐỊNH cho phần LÀM**: bug fix, feature, điều tra — mọi việc không rơi vào hàng dưới. Fix logic có test hẹp sẵn, điều tra một trục có câu hỏi rõ, viết test cho hành vi đã tồn tại, refactor theo pattern anh em, UI/UX, docs cần đọc-hiểu |
| **`fable`** (bỏ trống `model` để kế thừa session fable) | **việc RỦI RO, và MỌI REVIEW/VERIFY của diff rủi ro**. Vùng nào là "rủi ro" thì đọc `riskDomains` trong `tal config` — mỗi dự án một khác, skill KHÔNG ghi cứng |

Nguyên tắc kèm theo:
- **Rủi ro quyết định tier của REVIEW, không phải tier của người LÀM.** Diff đụng
  một vùng trong `riskDomains` thì verify PHẢI là fable, dù bản sửa là opus viết;
  ngược lại PR thường thì review opus là đủ. Một verify fable trên diff của tier
  thấp rẻ hơn một bản sửa sai phải làm lại — và rẻ hơn nhiều một bug tiền lên
  production.
- Tier nào thì hợp đồng báo cáo cũng NHƯ NHAU (bảng + số + file:dòng), và điều
  phối vẫn spot-check bằng phép đo trực tiếp — model rẻ sai kiểu rẻ, model đắt
  sai kiểu tự tin; phép đo không phân biệt.
- KHÔNG bao giờ giao sonnet một việc mà phạm vi của nó chạm `riskDomains` — kể
  cả phần "chỉ đổi comment" của việc đó.
