#!/usr/bin/env bash
# Stage ws-server binaries + shop-friendly bundles under
# backend/public/downloads/workstation/, then refresh manifest.json.
# Called from workstation-release.yml after the five-platform matrix.
set -euo pipefail

VERSION="${1:?usage: publish-workstation-downloads.sh <version> [commit-sha]}"
COMMIT_SHA="${2:-unknown}"
DIST_DIR="${3:-dist}"
ROOT="${4:-backend/public/downloads/workstation}"

# Linux CI has sha256sum; allow macOS dry-runs via shasum.
if ! command -v sha256sum >/dev/null 2>&1; then
  sha256sum() { shasum -a 256 "$@"; }
fi

# Resolve absolute paths BEFORE cd into DIST_DIR — otherwise relative ROOT/TARGET
# resolve under dist/ and `cp … "$TARGET/"` fails with "No such file or directory".
#
# `SCRIPT_DIR` obeys the same rule and is here for the same reason (#2824). The
# refactor that split the manifest merge into its own file called it as
# `$(dirname "$0")/merge-workstation-manifest.py`, and `$0` is the RELATIVE path
# the caller used — so after the `cd` below it resolved to
# `dist/.github/scripts/…` and the release died one step before publishing.
# `BASH_SOURCE[0]` rather than `$0` so it still resolves when sourced.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$(cd "$DIST_DIR" && pwd)"
mkdir -p "$ROOT/archive"
ROOT="$(cd "$ROOT" && pwd)"
TARGET="$ROOT/$VERSION"
MANIFEST="$ROOT/manifest.json"
rm -rf "$TARGET"
mkdir -p "$TARGET"

cd "$DIST_DIR"
missing=""
for t in linux-amd64 linux-arm64 darwin-amd64 darwin-arm64 windows-amd64.exe; do
  [ -f "ws-server-$t" ] || missing="$missing ws-server-$t"
done
if [ -n "$missing" ]; then
  echo "::error::missing binaries:$missing"
  ls -la
  exit 1
fi

# --- Shop bundles: zip/tar with a double-clickable launcher + short README -----
# Bare binaries stay published for assisted auto-update; the /downloads page
# points humans at these bundles.

pack_unix() {
  local id="$1"
  local binary="ws-server-$id"
  local bundle="Tempo-Workstation-${id}.tar.gz"
  local dir="pack-$id"
  rm -rf "$dir"
  mkdir -p "$dir"
  cp "$binary" "$dir/ws-server"
  chmod +x "$dir/ws-server"

  cat > "$dir/start.sh" <<'EOF'
#!/usr/bin/env bash
cd "$(dirname "$0")"
chmod +x ./ws-server
# macOS Gatekeeper quarantine (no-op on Linux)
xattr -d com.apple.quarantine ./ws-server 2>/dev/null || true
echo "Tempo Workstation starting…"
echo "Open http://localhost:8080/ in a browser (POS: /pos)."
exec ./ws-server
EOF
  chmod +x "$dir/start.sh"

  # Double-clickable on macOS Finder (opens Terminal.app).
  cat > "$dir/start.command" <<'EOF'
#!/bin/bash
cd "$(dirname "$0")"
chmod +x ./ws-server ./start.sh
xattr -d com.apple.quarantine ./ws-server 2>/dev/null || true
xattr -d com.apple.quarantine ./start.command 2>/dev/null || true
exec ./start.sh
EOF
  chmod +x "$dir/start.command"

  cat > "$dir/README.txt" <<EOF
Tempo Workstation ${VERSION}
===========================

1. Unpack this archive (double-click the .tar.gz, or: tar -xzf ${bundle})
2. macOS: double-click start.command
   Linux: run ./start.sh  (or: chmod +x start.sh && ./start.sh)
3. Open http://localhost:8080/ in a browser
4. Pair the device from HQ admin (pairing code)

Data folder: ~/.ws-app/
Stop: Ctrl+C in the terminal window.

日本語:
1. 解凍する
2. macOS は start.command をダブルクリック / Linux は ./start.sh
3. ブラウザで http://localhost:8080/
4. 管理画面でペアリング

Tiếng Việt:
1. Giải nén
2. macOS: double-click start.command / Linux: ./start.sh
3. Mở http://localhost:8080/
4. Pair thiết bị trên admin HQ
EOF

  tar -czf "$bundle" -C "$dir" .
  rm -rf "$dir"
  echo "$bundle"
}

pack_windows() {
  local id="windows-amd64"
  local binary="ws-server-windows-amd64.exe"
  local bundle="Tempo-Workstation-windows-amd64.zip"
  local dir="pack-$id"
  rm -rf "$dir"
  mkdir -p "$dir"
  cp "$binary" "$dir/ws-server.exe"

  cat > "$dir/start.bat" <<'EOF'
@echo off
cd /d "%~dp0"
echo Tempo Workstation starting...
echo Open http://localhost:8080/ in your browser (POS: /pos).
ws-server.exe
pause
EOF

  cat > "$dir/README.txt" <<EOF
Tempo Workstation ${VERSION}
===========================

1. Unzip this file
2. Double-click start.bat  (or ws-server.exe)
3. Open http://localhost:8080/ in a browser
4. Pair the device from HQ admin (pairing code)

If Windows SmartScreen blocks it: More info → Run anyway.

Data folder: %USERPROFILE%\.ws-app\

日本語: 解凍 → start.bat をダブルクリック → ブラウザで http://localhost:8080/
Tiếng Việt: Giải nén → double-click start.bat → mở http://localhost:8080/
EOF

  (cd "$dir" && zip -qr "../$bundle" .)
  rm -rf "$dir"
  echo "$bundle"
}

BUNDLE_LINUX_AMD64="$(pack_unix linux-amd64)"
BUNDLE_LINUX_ARM64="$(pack_unix linux-arm64)"
BUNDLE_DARWIN_AMD64="$(pack_unix darwin-amd64)"
BUNDLE_DARWIN_ARM64="$(pack_unix darwin-arm64)"
BUNDLE_WINDOWS="$(pack_windows)"

sha256sum \
  ws-server-* \
  Tempo-Workstation-*.tar.gz \
  Tempo-Workstation-*.zip \
  > SHA256SUMS.txt
cp \
  ws-server-* \
  Tempo-Workstation-*.tar.gz \
  Tempo-Workstation-*.zip \
  SHA256SUMS.txt \
  "$TARGET/"

NOW="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

# Platform entries: raw binary (assisted update) + shop bundle (downloads page).
platforms_json=""
add_platform() {
  local plat_id="$1"
  local binary="$2"
  local bundle="$3"
  local b_hash b_size g_hash g_size entry
  b_hash="$(sha256sum "$binary" | awk '{print $1}')"
  b_size="$(wc -c < "$binary" | tr -d ' ')"
  g_hash="$(sha256sum "$bundle" | awk '{print $1}')"
  g_size="$(wc -c < "$bundle" | tr -d ' ')"
  entry=$(printf '{"id":"%s","filename":"%s","size":%s,"sha256":"%s","bundle":{"filename":"%s","size":%s,"sha256":"%s"}}' \
    "$plat_id" "$binary" "$b_size" "$b_hash" "$bundle" "$g_size" "$g_hash")
  if [ -n "$platforms_json" ]; then platforms_json="$platforms_json,"; fi
  platforms_json="$platforms_json$entry"
}

add_platform linux-amd64 ws-server-linux-amd64 "$BUNDLE_LINUX_AMD64"
add_platform linux-arm64 ws-server-linux-arm64 "$BUNDLE_LINUX_ARM64"
add_platform darwin-amd64 ws-server-darwin-amd64 "$BUNDLE_DARWIN_AMD64"
add_platform darwin-arm64 ws-server-darwin-arm64 "$BUNDLE_DARWIN_ARM64"
add_platform windows-amd64.exe ws-server-windows-amd64.exe "$BUNDLE_WINDOWS"

NEW_VERSION_JSON=$(printf '{"version":"%s","released_at":"%s","commit":"%s","archived":false,"platforms":[%s]}' \
  "$VERSION" "$NOW" "$COMMIT_SHA" "$platforms_json")


# ── Lịch sử phiên bản đến từ PRODUCTION, không từ checkout (#2814) ───────────
#
# Bản `manifest.json` nằm trong repo là `{"latest": null, "versions": []}` — một
# chỗ giữ chỗ, KHÔNG phải nguồn. Trước bản sửa này script đọc nó, khởi tạo từ
# rỗng, dựng manifest chỉ có bản mới, rồi rsync ĐÈ lên production. Đoạn giữ một
# thế hệ cũ (`prev_latest` + `archived: true`) ngay dưới viết đúng nhưng KHÔNG
# BAO GIỜ chạy được: nó đọc lịch sử từ nơi không có lịch sử.
#
# Đo được ở lượt phát hành v0.7.0 (`654ba1f9b`): production mất sạch entry
# `v0.6.0`. Với fleet cài TAY, không auto-update, manifest là thứ duy nhất trả
# lời "quán đang chạy bản nào" và "muốn lùi thì lùi về đâu".
#
# FAIL-CLOSED. Không lấy được manifest production thì DỪNG, không âm thầm rơi về
# rỗng — vì chính cú rơi âm thầm đó là thứ đang hỏng. Mất một lượt phát hành rẻ
# hơn mất lịch sử: lượt phát hành chạy lại được, lịch sử thì không.
PROD_MANIFEST_URL="${PROD_MANIFEST_URL:-https://tempo-prod.godx.jp/downloads/workstation/manifest.json}"

if [ "${ALLOW_EMPTY_MANIFEST:-false}" = "true" ]; then
  # Chỉ dành cho lần phát hành ĐẦU TIÊN của một môi trường mới, khi chưa có gì
  # để giữ. Phải bật TAY — mặc định không bao giờ đi vào nhánh này.
  echo "==> ALLOW_EMPTY_MANIFEST=true: bỏ qua việc nạp lịch sử từ production"
else
  echo "==> nạp manifest hiện tại từ $PROD_MANIFEST_URL"
  if ! curl -fsS --max-time 30 "$PROD_MANIFEST_URL" -o "$MANIFEST.prod"; then
    echo "::error::không tải được manifest production ($PROD_MANIFEST_URL). DỪNG:"
    echo "::error::phát hành tiếp sẽ dựng lại manifest từ rỗng và XOÁ lịch sử phiên bản (#2814)."
    echo "::error::Nếu đây thật sự là lần phát hành đầu của môi trường mới, chạy lại với ALLOW_EMPTY_MANIFEST=true."
    exit 1
  fi
  if ! python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$MANIFEST.prod"; then
    echo "::error::manifest production tải về nhưng KHÔNG phải JSON hợp lệ. DỪNG (#2814)."
    exit 1
  fi
  mv "$MANIFEST.prod" "$MANIFEST"
  echo "==> lịch sử nạp xong: $(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("latest"), len(d.get("versions",[])), "thế hệ")' "$MANIFEST")"
fi

if [ -f "$MANIFEST" ]; then
  PREV_LATEST="$(python3 - "$MANIFEST" <<'PY'
import json, sys
path = sys.argv[1]
try:
    data = json.load(open(path))
except (FileNotFoundError, json.JSONDecodeError):
    print("")
else:
    print(data.get("latest") or "")
PY
)"
else
  PREV_LATEST=""
fi

# Move the previous latest directory into archive/ (keep one generation on disk).
if [ -n "$PREV_LATEST" ] && [ "$PREV_LATEST" != "$VERSION" ] && [ -d "$ROOT/$PREV_LATEST" ]; then
  mkdir -p "$ROOT/archive/$PREV_LATEST"
  shopt -s dotglob nullglob
  for item in "$ROOT/$PREV_LATEST"/*; do
    base="$(basename "$item")"
    mv "$item" "$ROOT/archive/$PREV_LATEST/$base"
  done
  rmdir "$ROOT/$PREV_LATEST" 2>/dev/null || true
fi

python3 "$SCRIPT_DIR/merge-workstation-manifest.py" \
  "$MANIFEST" "$VERSION" "$NOW" "$NEW_VERSION_JSON" "$PREV_LATEST"

echo "Published workstation $VERSION -> $TARGET"
ls -la "$TARGET"
