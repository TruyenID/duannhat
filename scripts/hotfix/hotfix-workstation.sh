#!/usr/bin/env bash
# Hotfix một máy trạm CỤ THỂ qua SSH — không chờ GitHub, không chờ release.
#
#   scripts/hotfix/hotfix-workstation.sh <ssh-host> [--skip-frontend]
#
# Đây chính là quy trình đã cứu Tsukiji 2026-08-18 (image_fetcher nện 404
# mỗi 5s), đóng gói lại: build binary linux-amd64 với frontend + pos-web
# nhúng, scp sang máy, backup binary cũ, swap, restart tempo-ws, tự smoke.
#
# Cái nó BỎ QUA: hàng đợi runner GitHub, cổng CI, chu trình release.
# Cái nó KHÔNG bỏ qua: smoke sau swap (service active + /api/device/status),
# backup binary cũ (rollback = mv ngược lại + restart), và DANH TÍNH build —
# version đóng dấu `v<VERSION>-hotfix-<sha>[-dirty]` nên máy tự khai nó đang
# chạy bản vá tay; trang /downloads và expected-version sẽ nhìn thấy điều đó.
#
# Nghĩa vụ đi kèm: hotfix xong PHẢI đưa commit lên dev→main để bản release
# chính thức kế tiếp chứa nó — máy hotfix là NHÁNH TẠM, không phải trạng thái
# đích. --skip-frontend chỉ dành cho vá thuần Go (nhanh hơn ~2 phút) và sẽ
# nhúng lại dist ĐANG CÓ trên đĩa — đừng dùng khi không chắc dist là gì.
set -euo pipefail

HOST=${1:?usage: hotfix-workstation.sh <ssh-host> [--skip-frontend]}
SKIP=${2:-}
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT/workstation"

if [ "$SKIP" != "--skip-frontend" ]; then
  echo "==> build frontend"
  (cd frontend && pnpm install --ignore-workspace --silent && pnpm run build >/dev/null)
  echo "==> build pos-web (workstation mode)"
  (cd "$ROOT/web/pos" && pnpm install --silent && pnpm run build:workstation >/dev/null)
  rm -rf pos-web/dist && mkdir -p pos-web/dist
  cp -R "$ROOT/web/pos/dist/." pos-web/dist/
  touch pos-web/dist/.gitkeep
fi
test -f frontend/dist/index.html || { echo "frontend/dist thiếu — bỏ --skip-frontend"; exit 1; }
test -f pos-web/dist/index.html || { echo "pos-web/dist thiếu — bỏ --skip-frontend"; exit 1; }

VER="v$(tr -d '[:space:]' < "$ROOT/VERSION")-hotfix-$(git rev-parse --short HEAD)"
if [ -n "$(git status --porcelain 2>/dev/null)" ]; then VER="${VER}-dirty"; fi

OUT="$(mktemp -d)/ws-server-linux-amd64"
echo "==> build $VER"
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build \
  -ldflags "-s -w -X 'github.com/dxs-platform/workstation-app/internal/config.Version=${VER}'" \
  -o "$OUT" ./cmd/ws-server

echo "==> deploy to $HOST"
scp -o BatchMode=yes "$OUT" "$HOST:~/ws-server.new"
# shellcheck disable=SC2029
ssh -o BatchMode=yes "$HOST" '
  set -e
  cp ~/Tempo-Workstation/ws-server ~/Tempo-Workstation/ws-server.bak.$(date +%Y%m%d-%H%M%S)
  mv ~/ws-server.new ~/Tempo-Workstation/ws-server
  chmod +x ~/Tempo-Workstation/ws-server
  systemctl --user restart tempo-ws
  sleep 6
  systemctl --user is-active tempo-ws
  PORT=$(python3 -c "import json;print(json.load(open(\"$HOME/.ws-app/config.json\")).get(\"server_port\",6969))" 2>/dev/null || echo 6969)
  curl -sf -m 10 "http://localhost:${PORT}/api/device/status" | head -c 200; echo
  journalctl --user -u tempo-ws -n 3 --no-pager
'
echo "==> OK — $HOST đang chạy $VER (backup: ~/Tempo-Workstation/ws-server.bak.*)"
