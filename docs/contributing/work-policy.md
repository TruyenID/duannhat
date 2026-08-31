# Luật làm việc — ghi công, commit, PR

## KHÔNG ghi công Claude ở bất cứ đâu

Không trailer, không dòng "Generated with", không chữ ký trong commit message,
thân PR, hay docs.

```
✗ Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
✗ 🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

Lịch sử commit là hồ sơ của **dự án**, không phải hồ sơ công cụ nào đã gõ.

**Chủ dự án chốt 2026-08-14, trong #2740.** Ghi cả ngày lẫn chỗ chốt là cố ý:
trước hôm đó luật này chỉ truyền miệng, và một session đã đo ra rằng
`grep -riE "co-authored|ghi công claude|generated with"` trên `CLAUDE.md`,
`docs/contributing/` và `.githooks/` trả về **rỗng** — nên nó nhả issue ở trạng
thái `blocked` thay vì tự dựng cơ chế. Đó là xử đúng: một agent không tự viết
khoá cho chỉ thị đang chi phối chính nó, dựa trên một quy tắc chưa ai xác nhận.
Từ nay câu hỏi đó có chỗ để tra.

### Nguồn của trailer nằm NGOÀI repo này

Harness của vai code **được chỉ thị thêm** đúng trailer đó (cấu hình Claude Code
của chủ dự án, không phải file nào trong cây này). Nên hook ở đây là tầng cưỡng
chế phía repo, không phải chỗ chữa gốc — gỡ dòng chỉ thị kia mới hết sinh ra
trailer. Hai tầng bù nhau: chỉ thị sửa được cho một máy, hook áp cho mọi máy và
mọi công cụ clone repo này.

### Vì sao phải có cơ chế, không chỉ có luật

Đo trên `origin/dev` ngày 2026-08-13, 30 commit gần nhất: **5 trailer máy**, và
**3 trong số đó viết thường** (`Co-authored-by:` — dạng chính tắc của git).
Reviewer đã ghi todo *"gỡ trong squash"* ở ít nhất **ba** verdict, và **không lần
nào được gỡ**: `tal` merge bằng `gh pr merge --merge` (`tal:3860,4378`), tức merge
commit chứ không squash, nên cái todo ấy không có đường nào thực hiện.

Nhắc từng PR không bao giờ đủ khi nguồn là một chỉ thị tự động. Phải chặn ở tầng
cơ chế.

### Cơ chế: `.githooks/commit-msg`

Repo đặt `core.hooksPath=.githooks`, nên hook áp cho mọi lượt `git commit` và
`git merge` — bất kể ai gõ hay công cụ nào gọi.

Hook **gỡ** dòng vi phạm chứ **không từ chối** commit. Từ chối thì tác nhân phải
soạn lại message và thử lại — tốn một vòng cho một dòng máy tự thêm, mà dòng đó
không mang thông tin nào của người viết.

Hai chỗ dễ làm sai, cả hai đã trả giá trong vòng review 1 của #2740:

1. **Đừng phân biệt hoa/thường.** Dạng chính tắc của git là `Co-authored-by:`, và
   đó là dạng chiếm đa số trong lịch sử thật. Bản hook đầu tiên khớp phân biệt
   hoa/thường nên bỏ lọt 3/5 trailer đang có — mà bộ test của nó vẫn 7/7 xanh, vì
   mẫu thử lấy từ trí nhớ chứ không lấy từ `git log`.
2. **Đừng neo vào chữ `Claude` đứng một mình.** Thêm `-i` cho xong thì
   `Co-authored-by: Claude Dubois <claude@example.com>` cũng khớp — **xoá công một
   người thật**. Neo vào thứ chỉ máy mới có: email `noreply@anthropic.com`, hoặc
   tên model đi ngay sau chữ Claude.

Hook chỉ được đụng đúng thứ nó khai là đụng: dòng trống **trong thân** message là
của tác giả (đoạn văn, khối code dán vào) nên phải qua nguyên vẹn; chỉ dòng trống
**ở cuối** — do chính việc gỡ để lại — mới bị cắt.

Chỉ chặn từ nay về sau. **Không rewrite lịch sử** — các commit đã merge giữ
nguyên.

### Rào

`bash .githooks/commit-msg_test.sh .githooks/commit-msg` — mười ca, đủ cả hai
chiều (gỡ / giữ), cộng một bài ghim mẫu số chứng minh hook thật sự chạy được.
Chạy trong CI qua `npm run test:hooks`.

Mẫu thử **lấy từ `git log`, không lấy từ trí nhớ**. Thêm ca mới thì kiểm lại bằng
chính lịch sử:

```sh
git log origin/dev -30 --format='%B' | grep -iE 'co-authored-by|generated with' | sort -u
```

Trước #2740, `pre-push_test.sh` tồn tại từ #1353 nhưng **không workflow nào gọi**
— hook là bash mà không ai canh. `test:hooks` chạy cả hai.

## Vị trí trong bộ luật

Luật cắt ngang khác nằm ở `CLAUDE.md` gốc repo. File này chỉ giữ phần **quy trình
làm việc** — thứ nói về cách commit và ghi công, không phải về kiến trúc.
