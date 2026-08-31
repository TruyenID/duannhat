#!/usr/bin/env bash
# Shared monorepo release tag helpers — semver ONLY (v0.4.0), not legacy date tags.
set -euo pipefail

# Tag ngày KHÔNG có hậu tố chữ vẫn là semver hợp lệ về CÚ PHÁP.
#
# `v2026.8.5` khớp `^v[0-9]+\.[0-9]+\.[0-9]+$` y như `v0.4.0`, nên bộ lọc cũ để
# lọt nó — và vì `sort -V` xếp `2026 > 0`, tag ngày LUÔN thắng mọi semver thật.
# Đo trên danh sách tag của repo:
#
#   chỉ lọc cú pháp  → previous_semver_tag HEAD = v2026.8.5
#   + loại tag ngày  → previous_semver_tag HEAD = v0.4.0
#
# Chỗ duy nhất từng lọc đúng là `workstation/Makefile` (`grep -Ev '^v20[0-9]{2}\.'`);
# #2660 xoá nó khi chuyển sang file VERSION, nên bộ lọc phải về sống ở đây —
# một chỗ, dùng chung, thay vì chép lại ở mỗi caller.
#
# Mốc `20[0-9]{2}` cố ý hẹp: nó loại đúng họ `vYYYY.M.D` mà repo này đã dùng,
# và không đụng tới semver thật (major 2000+ cho một app cửa hàng thì vấn đề
# nằm ở chỗ khác).
DATE_TAG_RE='^v20[0-9]{2}\.'

# List semver release tags newest-last (vMAJOR.MINOR.PATCH), date tags excluded.
semver_tags() {
  git tag -l 'v*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | grep -Ev "$DATE_TAG_RE" | sort -V
}

# Latest semver tag strictly before REF (tag name or HEAD).
previous_semver_tag() {
  local current="${1:-HEAD}"
  local tag=""
  if [ "$current" = "HEAD" ]; then
    tag="$(semver_tags | tail -1 || true)"
    echo "$tag"
    return 0
  fi
  semver_tags | grep -v "^${current}$" | tail -1 || true
}

# Fail if TAG is not strict semver (used by release workflows).
assert_semver_tag() {
  local tag="$1"
  # Hai điều kiện, không một: đúng cú pháp semver VÀ không phải tag ngày.
  # Thông điệp lỗi cũ đã nói "not legacy date tags like v2026.8.10a" trong khi
  # regex vẫn cho `v2026.8.5` đi qua — lời hứa và phép kiểm lệch nhau.
  if ! grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' <<<"$tag" || grep -Eq "$DATE_TAG_RE" <<<"$tag"; then
    echo "::error::Release tag must be semver vMAJOR.MINOR.PATCH (e.g. v0.4.0), not legacy date tags like v2026.8.10a or v2026.8.5. Got: ${tag}" >&2
    return 1
  fi
}
