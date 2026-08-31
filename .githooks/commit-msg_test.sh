#!/usr/bin/env bash
# Test cho .githooks/commit-msg (#2740).
#
# Chạy: bash .githooks/commit-msg_test.sh .githooks/commit-msg
#
# Hook này sửa ĐẦU VÀO của mọi commit trong repo, nên nó phải đúng cả hai chiều:
# gỡ thì phải gỡ sạch, mà giữ thì phải giữ nguyên. Sai chiều "gỡ" thì trailer lọt
# tiếp như trước; sai chiều "giữ" thì nó ăn mất đồng tác giả THẬT của người khác —
# và cái sau tệ hơn hẳn, vì nó xoá công của một con người.
#
# MẪU THỬ LẤY TỪ LỊCH SỬ THẬT, không lấy từ trí nhớ. Vòng review 1 bắt được đúng
# lỗi đó: bộ test cũ chỉ có dạng viết HOA `Co-Authored-By:` nên 7/7 xanh trong khi
# hook bỏ lọt 3/5 trailer đang nằm trên `dev` — dạng chính tắc của git là
# `Co-authored-by:` viết thường.
set -uo pipefail

HOOK=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
TMP=$(mktemp -d)
FAIL=0

check() { # check <nhãn> <kỳ vọng> <thực tế>
  if [ "$2" = "$3" ]; then
    echo "  ok   $1"
  else
    echo "  FAIL $1"
    echo "       kỳ vọng: $(printf '%q' "$2")"
    echo "       thực tế: $(printf '%q' "$3")"
    FAIL=1
  fi
}

run_hook() { # run_hook <nội dung> → in ra message sau khi hook chạy
  local f="$TMP/msg"
  printf '%s\n' "$1" > "$f"
  "$HOOK" "$f" 2>/dev/null || true
  cat "$f"
}

echo "commit-msg: gỡ ghi công Claude, giữ mọi thứ khác (#2740)"

# ── chiều GỠ ────────────────────────────────────────────────────────────────
got=$(run_hook 'fix: chuyện gì đó

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')
check "gỡ trailer Co-Authored-By Claude" "fix: chuyện gì đó" "$got"

# Dạng CHÍNH TẮC của git — chữ thường. Chiếm 3/5 trailer thật trên `dev`, và là
# thứ bản hook đầu tiên bỏ lọt hoàn toàn.
got=$(run_hook 'fix: dạng chính tắc của git

Co-authored-by: Claude Opus 5 (1M context) <noreply@anthropic.com>')
check "gỡ trailer viết THƯỜNG (dạng chính tắc của git)" "fix: dạng chính tắc của git" "$got"

got=$(run_hook 'feat: abc

🤖 Generated with [Claude Code](https://claude.com/claude-code)')
check "gỡ dòng Generated with Claude" "feat: abc" "$got"

got=$(run_hook 'fix: hai dòng cùng lúc

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
🤖 Generated with [Claude Code](https://claude.com/claude-code)')
check "gỡ được cả hai dạng trong một message" "fix: hai dòng cùng lúc" "$got"

# ── chiều GIỮ — quan trọng hơn, vì sai ở đây là xoá công người thật ─────────
got=$(run_hook 'fix: có đồng tác giả thật

Co-Authored-By: Nguyen Van A <a@example.com>')
check "GIỮ đồng tác giả người thật" \
  'fix: có đồng tác giả thật

Co-Authored-By: Nguyen Van A <a@example.com>' "$got"

# Chặn bản vá "thêm -i cho xong": `-i` trần khớp luôn người thật TÊN Claude.
got=$(run_hook 'fix: đồng tác giả là người thật tên Claude

Co-authored-by: Claude Dubois <claude@example.com>')
check "GIỮ đồng tác giả người thật TÊN Claude" \
  'fix: đồng tác giả là người thật tên Claude

Co-authored-by: Claude Dubois <claude@example.com>' "$got"

got=$(run_hook 'fix: nhắc chữ Claude trong THÂN message

Bản sửa này gỡ phụ thuộc vào Claude Code trong tài liệu.')
check "GIỮ chữ Claude nằm trong thân, không phải trailer" \
  'fix: nhắc chữ Claude trong THÂN message

Bản sửa này gỡ phụ thuộc vào Claude Code trong tài liệu.' "$got"

got=$(run_hook 'fix: message thường, không có gì để gỡ')
check "message sạch đi qua nguyên vẹn" "fix: message thường, không có gì để gỡ" "$got"

# ── hook chỉ được đụng đúng thứ nó khai là đụng ─────────────────────────────
# Dòng trống TRONG thân là của tác giả (đoạn văn, khối code dán vào). Bản đầu
# dùng awk chế độ đoạn văn nên gộp mọi dãy dòng trống ở khắp message: gỡ 1 dòng
# trailer mà 2 dòng trống khác của tác giả cũng biến mất.
got=$(run_hook 'fix: có khối code trong thân

Trước đây:

    OrderUpdated::dispatch($order);

    // sau vòng lặp


Hai đoạn văn cách nhau hai dòng trống ở trên.

Co-authored-by: Claude Opus 5 <noreply@anthropic.com>')
check "GIỮ nguyên dòng trống trong THÂN khi gỡ trailer" \
  'fix: có khối code trong thân

Trước đây:

    OrderUpdated::dispatch($order);

    // sau vòng lặp


Hai đoạn văn cách nhau hai dòng trống ở trên.' "$got"

# ── mẫu số: hook phải THẬT SỰ chạy được ────────────────────────────────────
# Không có bài này thì mọi bài trên vẫn "ok" khi hook không executable hoặc
# thoát sớm — chúng chỉ so message với chính nó.
probe="$TMP/probe"
printf 'x\n\nCo-Authored-By: Claude X <noreply@anthropic.com>\n' > "$probe"
"$HOOK" "$probe" 2>/dev/null || true
if grep -q "Claude" "$probe"; then
  echo "  FAIL hook thật sự chạy và sửa được file"
  FAIL=1
else
  echo "  ok   hook thật sự chạy và sửa được file"
fi

rm -rf "$TMP"
[ "$FAIL" -eq 0 ] && echo "tất cả pass" || echo "CÓ CA ĐỎ"
exit "$FAIL"
