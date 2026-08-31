#!/usr/bin/env python3
"""So manifest VỪA GHI với manifest ĐANG PHỤC VỤ (#2827).

Tách ra file thay vì nhúng vào YAML — cùng luật repo đã chốt cho deploy
("đừng thêm script inline mới vào workflow, viết một lệnh rồi gọi nó"). Không
chỉ vì gọn: một khối python thụt lề sai bên trong `run: |` làm HỎNG CẢ FILE
workflow, và GitHub báo lỗi đó bằng một lượt chạy đỏ không có log — đúng cái
vừa xảy ra với `workstation-manifest-restore`.

Đối chiếu `latest` và ĐÚNG THỨ TỰ `versions[]`: thứ tự là thứ client đọc để
biết đâu là thế hệ liền trước, nên hai danh sách cùng phần tử mà khác thứ tự
vẫn là sai.
"""
import argparse
import json
import sys
from pathlib import Path


def versions_of(doc):
    return [v.get("version") for v in doc.get("versions", [])]


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--expected", required=True, help="manifest vừa ghi")
    ap.add_argument("--actual", required=True, help="manifest tải lại từ production")
    args = ap.parse_args(argv)

    expected = json.loads(Path(args.expected).read_text())
    actual = json.loads(Path(args.actual).read_text())

    want, got = versions_of(expected), versions_of(actual)
    print("mong đợi   :", expected.get("latest"), want)
    print("đang phục vụ:", actual.get("latest"), got)

    if want != got or expected.get("latest") != actual.get("latest"):
        print("::error::manifest đang phục vụ KHÔNG khớp bản vừa ghi.", file=sys.stderr)
        return 1
    print("khớp.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
