#!/usr/bin/env python3
"""Khôi phục các thế hệ đã mất khỏi manifest workstation (#2819).

#2814 vá chỗ RÒ — từ nay mỗi lượt phát hành giữ lại lịch sử. Nhưng những thế hệ
đã rơi ra trước đó không tự quay lại: file binary vẫn nằm trên đĩa production,
chỉ có entry mô tả chúng là mất. Script này chép entry trở lại.

Nguồn sự thật là **hiện trạng đĩa của production**, đưa vào qua `--inventory`:
một file TSV do bước ssh sinh ra bằng `stat` + `sha256sum` trên chính máy đó.
Không suy ra kích thước hay hash từ bất cứ đâu khác — một entry mô tả sai file
đang nằm đó còn tệ hơn không có entry, vì `SHA256SUMS` là thứ người cài đối
chiếu trước khi chạy một nhị phân trên máy quán.

Fail-closed ở hai chỗ, cả hai vì cùng một lý do — thà bỏ một bản còn hơn công
bố một bản sai:

  * thiếu bất kỳ nền tảng nào trong `REQUIRED_PLATFORMS` ⇒ BỎ QUA cả version đó;
  * không biết commit ⇒ BỎ QUA, vì "quán đang chạy commit nào" chính là câu hỏi
    manifest sinh ra để trả lời.

Script KHÔNG đụng `latest`, không đụng entry đã có, không đụng binary.
"""
import argparse
import json
import sys
from pathlib import Path

# Đúng năm nền tảng mà `publish-workstation-downloads.sh` phát hành. Một bản
# thiếu nền tảng nghĩa là thư mục đó chưa bao giờ hoàn tất (hoặc bị dọn dở), và
# công bố nó sẽ mời người dùng bấm vào một liên kết 404.
REQUIRED_PLATFORMS = (
    "linux-amd64",
    "linux-arm64",
    "darwin-amd64",
    "darwin-arm64",
    "windows-amd64.exe",
)

# `ws-server-<id>` ↔ gói cài kèm. Bảng viết tường minh thay vì ghép chuỗi: tên
# gói Windows là `.zip` còn lại là `.tar.gz`, và đuôi `.exe` chỉ có ở binary.
BUNDLE_NAME = {
    "linux-amd64": "Tempo-Workstation-linux-amd64.tar.gz",
    "linux-arm64": "Tempo-Workstation-linux-arm64.tar.gz",
    "darwin-amd64": "Tempo-Workstation-darwin-amd64.tar.gz",
    "darwin-arm64": "Tempo-Workstation-darwin-arm64.tar.gz",
    "windows-amd64.exe": "Tempo-Workstation-windows-amd64.zip",
}
BINARY_NAME = {p: "ws-server-" + p for p in REQUIRED_PLATFORMS}


def parse_inventory(text):
    """TSV `version\tfilename\tsize\tsha256` → {version: {filename: (size, sha)}}.

    Dòng rỗng và dòng thiếu cột bị bỏ qua chứ không làm hỏng cả lượt chạy: đầu
    vào đến từ một vòng lặp shell trên máy khác, và một dòng lạ ở đó không đáng
    để mất toàn bộ phần khôi phục được.
    """
    out = {}
    for line in text.splitlines():
        parts = line.rstrip("\n").split("\t")
        if len(parts) != 4:
            continue
        version, filename, size, sha = (p.strip() for p in parts)
        if not version or not filename or not size.isdigit():
            continue
        out.setdefault(version, {})[filename] = (int(size), sha)
    return out


def build_entry(version, files, commit, released_at):
    """Dựng một entry `versions[]`, hoặc `None` nếu không đủ dữ kiện."""
    platforms = []
    for pid in REQUIRED_PLATFORMS:
        binary, bundle = BINARY_NAME[pid], BUNDLE_NAME[pid]
        if binary not in files or bundle not in files:
            return None
        bsize, bsha = files[binary]
        zsize, zsha = files[bundle]
        platforms.append({
            "id": pid,
            "filename": binary,
            "size": bsize,
            "sha256": bsha,
            "bundle": {"filename": bundle, "size": zsize, "sha256": zsha},
        })
    return {
        "version": version,
        "released_at": released_at,
        "commit": commit,
        # Mọi bản khôi phục đều KHÔNG phải bản hiện hành — `latest` không đổi.
        "archived": True,
        # Dấu vết: entry này dựng lại từ đĩa, không phải do lượt phát hành ghi
        # ra. Ai đọc manifest sau này cần phân biệt được hai thứ đó.
        "restored": True,
        "platforms": platforms,
    }


def version_sort_key(v):
    """Sắp theo số, không theo chuỗi — `v0.10.0` phải đứng TRÊN `v0.9.0`."""
    try:
        return (0, [int(x) for x in v.lstrip("v").split(".")])
    except ValueError:
        # Nhãn không phải semver vẫn phải có chỗ đứng tất định, xếp cuối.
        return (1, [], v)


def restore(manifest, inventory, meta):
    """Trả về manifest mới. KHÔNG đụng `latest` và không sửa entry đã có."""
    known = {v.get("version") for v in manifest.get("versions", [])}
    added, skipped = [], []
    for version, files in inventory.items():
        if version in known:
            continue
        info = meta.get(version) or {}
        commit = (info.get("commit") or "").strip()
        if not commit:
            skipped.append((version, "không biết commit"))
            continue
        entry = build_entry(version, files, commit, info.get("released_at") or "")
        if entry is None:
            skipped.append((version, "thiếu nền tảng hoặc gói cài"))
            continue
        added.append(entry)

    versions = list(manifest.get("versions", [])) + added
    versions.sort(key=lambda v: version_sort_key(v.get("version", "")), reverse=True)
    out = dict(manifest)
    out["versions"] = versions[:20]
    return out, added, skipped


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", required=True, help="manifest ĐANG PHỤC VỤ của production")
    ap.add_argument("--inventory", required=True, help="TSV version/filename/size/sha256 từ server")
    ap.add_argument("--meta", required=True, help='JSON {"v0.7.0": {"commit": "...", "released_at": "..."}}')
    ap.add_argument("--out", required=True)
    args = ap.parse_args(argv)

    manifest = json.loads(Path(args.manifest).read_text())
    inventory = parse_inventory(Path(args.inventory).read_text())
    meta = json.loads(Path(args.meta).read_text())

    out, added, skipped = restore(manifest, inventory, meta)
    Path(args.out).write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n")

    for entry in added:
        print(f"khôi phục: {entry['version']} ({entry['commit'][:9]})")
    for version, why in skipped:
        # In ra chứ không nuốt: một bản bị bỏ mà im lặng thì lượt sau không ai
        # biết là nó từng được xét.
        print(f"BỎ QUA  : {version} — {why}", file=sys.stderr)
    print(f"latest giữ nguyên: {out.get('latest')}, tổng {len(out['versions'])} thế hệ")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
