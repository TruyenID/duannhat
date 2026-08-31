#!/usr/bin/env bash
# Test cho .githooks/pre-push — quan hệ pointer submodule ↔ tip nhánh (#1353).
#
# Chạy: bash .githooks/pre-push_test.sh .githooks/pre-push
#
# Vì sao có file này: hook là bash, không có test nào, và luật pointer của nó là
# thứ chặn/không chặn cả một lượt push — sai một chiều thì chặn oan mọi người,
# sai chiều kia thì để lọt một pointer không ai clone được. Fixture dựng umbrella
# + submodule thật (origin là bare cục bộ), không mạng, không đụng repo này.
#
# Hai ca AHEAD và DIVERGED phải tạo commit NGAY TRONG clone submodule của
# umbrella; tạo ở clone khác thì hook chỉ thấy "pointer không tồn tại" — đo nhầm
# ca mà vẫn xanh, đúng loại lỗi test này sinh ra để tránh.
set -uo pipefail

HOOK=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")   # tuyệt đối: fixture cd liên tục
ROOT=$(mktemp -d)
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

q() { "$@" >/dev/null 2>&1; }

# ── origin bare cho submodule + umbrella ────────────────────────────────────
q git init --bare -b dev "$ROOT/sub.git"
q git init --bare -b dev "$ROOT/umb.git"

# ── submodule: 3 commit trên dev ────────────────────────────────────────────
q git init -b dev "$ROOT/sub"
cd "$ROOT/sub"
for i in 1 2 3; do echo $i > f$i; q git add .; q git commit -m "c$i"; done
C1=$(git rev-parse HEAD~2); C2=$(git rev-parse HEAD~1); C3=$(git rev-parse HEAD)
q git remote add origin "$ROOT/sub.git"; q git push -u origin dev

# ── umbrella: nhúng submodule, pointer = C3 ─────────────────────────────────
q git init -b dev "$ROOT/umb"
cd "$ROOT/umb"
echo x > readme; q git add .; q git commit -m init
git -c protocol.file.allow=always submodule add "$ROOT/sub.git" sub >/dev/null 2>&1 || { echo "FIXTURE: submodule add hỏng"; exit 1; }
q git -C sub checkout dev
q git add .; q git commit -m "add sub"
q git remote add origin "$ROOT/umb.git"

run_case() {
  local label="$1" want="$2" ptr="$3"
  cd "$ROOT/umb"
  q git -C sub checkout dev
  # đặt pointer trong CÂY của commit sẽ push, không đụng branch của repo con
  q git update-index --cacheinfo 160000,"$ptr",sub
  q git commit -q -m "point at ${ptr:0:7}" 2>/dev/null || q git commit -q --amend -m "point at ${ptr:0:7}"
  local sha; sha=$(git rev-parse HEAD)
  local out rc
  out=$(echo "refs/heads/dev $sha refs/heads/dev 0000000000000000000000000000000000000000" | bash "$HOOK" 2>&1)
  rc=$?
  local got=allow; [[ $rc -ne 0 ]] && got=block
  if [[ "$got" == "$want" ]]; then echo "  ok   $label → $got"; else echo "  FAIL $label → $got (mong đợi $want)"; fi
  echo "$out" | sed 's/^/        | /' | head -8
}

echo "── quan hệ pointer ↔ tip nhánh submodule ──"
run_case "pointer = tip (C3)"                allow "$C3"
run_case "pointer là TỔ TIÊN, sau 2 commit"  allow "$C1"
run_case "pointer KHÔNG có trong repo con"   block "0000000000000000000000000000000000000001"

# Hai ca dưới đây phải tạo commit NGAY TRONG clone submodule của umbrella —
# tạo ở clone khác thì hook chỉ thấy "pointer không tồn tại", tức là đo nhầm ca.
# ca AHEAD: commit con của tip, nằm trên nhánh phụ nên dev vẫn ngang origin
cd "$ROOT/umb/sub"; q git checkout -q -b side dev; echo 4 > f4; q git add .; q git commit -m c4
C4=$(git rev-parse HEAD); q git checkout -q dev
run_case "pointer ĐỨNG TRƯỚC tip (C4)"       block "$C4"

# ca DIVERGED: nhánh mồ côi, không bên nào với tới bên nào
cd "$ROOT/umb/sub"; q git checkout -q --orphan other; q rm -f f1 f2 f3 f4 2>/dev/null; echo z > z
q git add .; q git commit -m orphan; D=$(git rev-parse HEAD); q git checkout -qf dev
run_case "pointer PHÂN KỲ với tip"           block "$D"

# ── #1733: nhánh dev của repo con BỊ XOÁ trên remote ────────────────────────
#
# Đây là ca đã xảy ra thật: merge PR dev → main làm GitHub tự xoá head branch ở
# sáu repo con cùng lúc. Hook cũ báo "cannot fetch origin dev — is the remote
# reachable?", hướng người đọc sang mạng/quyền trong khi nhánh chỉ đơn giản là
# không còn. Ca này ghim rằng hook nói ĐÚNG chuyện gì đã xảy ra.
echo "── nhánh dev của repo con biến mất khỏi remote (#1733) ──"
cd "$ROOT/umb"
q git -C sub checkout dev
q git update-index --cacheinfo 160000,"$C3",sub
q git commit -q -m "point at tip" 2>/dev/null || q git commit -q --amend -m "point at tip"
SHA=$(git rev-parse HEAD)
q git -C "$ROOT/sub.git" branch -D dev          # ← đúng thứ GitHub đã làm
OUT=$(echo "refs/heads/dev $SHA refs/heads/dev 0000000000000000000000000000000000000000" | bash "$HOOK" 2>&1)
if [[ $? -eq 0 ]]; then echo "  FAIL nhánh bị xoá → allow (mong đợi block)"; else echo "  ok   nhánh bị xoá → block"; fi
if echo "$OUT" | grep -q "KHÔNG CÒN nhánh"; then
  echo "  ok   nói đúng nguyên nhân (nhánh không còn), không đổ cho mạng"
else
  echo "  FAIL vẫn đổ cho mạng/quyền:"
  echo "$OUT" | sed 's/^/        | /' | head -6
fi

rm -rf "$ROOT"
