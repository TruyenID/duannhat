#!/usr/bin/env python3
"""Gộp một bản phát hành mới vào manifest workstation (#2814).

Tách khỏi `publish-workstation-downloads.sh` để logic này REVIEW ĐƯỢC và TEST
ĐƯỢC như mã thường — cùng lý lẽ với việc repo đã tách PHP inline khỏi YAML
deploy (#2463).

Hợp đồng: `manifest_path` phải là manifest ĐANG PHỤC VỤ của production, không
phải bản trong checkout. Bản trong repo là `{"latest": null, "versions": []}` —
một chỗ giữ chỗ. Trước #2814 script đọc chính nó, nên đoạn giữ thế hệ cũ bên
dưới không bao giờ có gì để giữ, và mỗi lượt phát hành xoá sạch lịch sử.

Nhận `argv[1:6]`: manifest_path, latest, updated_at, new_version_json, prev_latest
"""
import json
import sys
from pathlib import Path

manifest_path, latest, updated_at, new_version_raw, prev_latest = sys.argv[1:6]
new_version = json.loads(new_version_raw)

data = {"latest": latest, "updated_at": updated_at, "versions": []}
path = Path(manifest_path)
if path.is_file():
    try:
        data = json.loads(path.read_text())
    except json.JSONDecodeError:
        pass

# Giữ mọi thế hệ cũ, đánh dấu thế hệ liền trước là `archived` TẠI CHỖ.
#
# Bản trước append thêm một BẢN SAO của `prev_latest` sau khi đã giữ nó ở dòng
# lọc, nên manifest liệt kê cùng một phiên bản HAI lần — một bản không
# `archived`, một bản có. Client đọc manifest vớ phải bản nào là chuyện may rủi.
# Lỗi đó không lộ ra trên production vì nhánh này chưa bao giờ chạy được: script
# đọc manifest từ checkout rỗng nên `versions` luôn rỗng (#2814).
versions = []
for v in data.get("versions", []):
    if v.get("version") == latest:
        # Cùng số phiên bản với bản đang ghi ⇒ bản mới thay nó, không giữ hai.
        continue
    v = dict(v)
    if prev_latest and v.get("version") == prev_latest and prev_latest != latest:
        v["archived"] = True
    versions.append(v)

versions.insert(0, new_version)
data["latest"] = latest
data["updated_at"] = updated_at
data["versions"] = versions[:20]
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
print(f"manifest updated: latest={latest}, versions={len(data['versions'])}")