# Chạy full suite sao cho kết quả CHỨNG MINH ĐƯỢC

```sh
backend/bin/full-suite                 # đo commit đang ở HEAD
backend/bin/full-suite --ref <sha>     # đo đúng một commit
backend/bin/full-suite --keep          # giữ worktree + artifacts để soi
```

**Đừng chạy `vendor/bin/pest` trần cho toàn bộ suite.** Nó vẫn đúng cho một file
hay một thư mục, nhưng dùng cho cả suite thì kết quả không chứng minh được điều
người ta tưởng nó chứng minh — lý do bên dưới.

## Hai lỗ hổng nó bịt, và cả hai đều đã cắn thật

### 1. Cây làm việc DÙNG CHUNG bị sửa giữa lúc chạy

Ngày 2026-08-06, cùng một commit cho **ba** kết quả: 9114, 9123, 9145 test.

`/Users/satoshi-mini/Herd/tempo` là cây chung của mọi phiên agent. Reflog hôm đó:
`HEAD` đổi **13 lần trong 3 tiếng**. Một lượt full suite mất **~11 phút**, nên
gần như chắc chắn có người commit/pull chen vào giữa. File test bị thêm, sửa,
xoá **trong lúc PHPUnit đang duyệt**.

Không lượt nào sai. Chúng chỉ **không đo cùng một thứ** — và không có gì trong
output nói ra điều đó.

`tal` sinh worktree riêng đúng vì lý do này, nhưng không có gì ngăn người ta chạy
suite ở cây chính, mà kết quả sai thì trông y hệt kết quả đúng.

**Cách bịt:** script LUÔN dựng worktree mới ghim vào một commit. Chạy nhầm ở cây
dùng chung trở thành điều *không thể xảy ra*, không phải điều *bị khuyên tránh*.

### 2. Không ai đối chiếu "đáng lẽ chạy" với "đã chạy"

Pest cộng `passed + failed + skipped` rồi in. Nếu N test **không chạy** — vì bất
kỳ lý do gì — không có dòng cảnh báo nào và **exit code vẫn 0**. Cả CI lẫn người
đọc đều thấy màu xanh.

Đó là lý do phải mất một buổi mới nhìn ra: **con số xanh không tự tố cáo được
rằng nó thiếu.**

**Cách bịt:** `bin/reconcile-tests.py` so tập test `--list-tests-xml` phát hiện
với tập `--log-junit` đã chạy. Lệch một test cũng đỏ, kèm tên lớp.

## Những gì script kiểm, ngoài việc chạy test

| Kiểm | Vì sao |
|---|---|
| Worktree mới, ghim commit | không ai sửa được cây giữa lúc chạy |
| `HEAD` + trạng thái cây, đo **trước và sau** | nếu vẫn có gì đổi, kết quả bị tuyên bố **không dùng được** |
| Mọi file `../workstation/…` mà `backend/tests` đọc đều có mặt (13 file lúc viết dòng này; script tự đếm) | thiếu một file thì test golden đó tự `markTestSkipped` — **không** đỏ, và cũng **không** lệch đối chiếu, vì test bị skip vẫn là một `<testcase>` trong junit. Suite sẽ "xanh" trên tập đã bị rút bớt. Không tìm thấy tham chiếu nào cũng là ĐỎ: một phép kiểm rỗng không phải một phép kiểm đạt |
| `vendor` khớp `composer.lock` **của commit đó** | vendor lệch = chạy thư viện khác với thứ commit ghim |
| `.env` dựng từ `.env.example`, **không mượn `.env` cá nhân** | mượn là mượn cả biến chỉ máy đó có; kết quả không tái hiện được mà trông y hệt tái hiện được |
| Mọi cờ `--fail-on-*` | "không đỏ" phải nghĩa là "không có vấn đề nào", không phải "không có vấn đề thuộc loại mặc định bị bỏ qua" |
| Thay đổi chưa commit | **cảnh báo thẳng** rằng chúng không nằm trong phép đo, thay vì lặng lẽ đo thiếu |

## Đọc kết quả

Xanh chỉ được tin khi có **cả hai** dòng:

```
✓ đối chiếu KHỚP: 9145 test phát hiện = 9145 test đã chạy, trên NNN lớp
✓ TOÀN BỘ suite xanh, và đã chứng minh chạy đủ số test.
```

Thiếu dòng đầu thì con số ở dòng sau không có nghĩa.

## Vì sao đối chiếu theo LỚP chứ không theo tên test

Hai nguồn dùng hai định dạng định danh khác nhau:

```
--list-tests-xml  →  id        = P\Tests\Feature\X\YTest::__pest_evaluable_it_abc_def
--log-junit       →  classname = Tests.Feature.X.YTest , name = "it abc def"
```

Ghép **tên test** giữa chúng buộc phải đoán lại quy tắc slug của Pest, và một quy
tắc đoán sai sẽ báo lệch giả — tức tái tạo đúng căn bệnh đang chữa. Tên **lớp**
thì chuẩn hoá được tuyệt đối (bỏ tiền tố `P\`, đổi `\` thành `.`), nên phép so
theo lớp là **đúng**, không phải gần đúng. Lệch một test vẫn lộ ra vì số lượng
theo từng lớp phải khớp.

## Liên quan

- `full-suite` là cổng `dev→main`. Per-PR CI vào `dev` **cố ý** không chạy nó
  (`backend-tests.yml` job `pest` chỉ chạy khi `base_ref == main`).
- macOS đi kèm **bash 3.2** — script tránh `mapfile`/`readarray`. Đã trả giá một
  lượt chạy hỏng vì chỗ đó.
