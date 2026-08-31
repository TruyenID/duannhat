#!/usr/bin/env python3
"""Đối chiếu tập test PHÁT HIỆN với tập test ĐÃ CHẠY (#1971).

Vì sao cần: Pest in ra `passed + failed + skipped` và không bao giờ so con số đó
với số test đáng lẽ phải chạy. Thiếu 22 test thì không có dòng cảnh báo nào và
exit code vẫn 0 — đúng cái đã xảy ra ngày 2026-08-06.

Vì sao so theo LỚP chứ không theo tên test:

    --list-tests-xml  →  id   = P\\Tests\\Feature\\X\\YTest::__pest_evaluable_it_abc_def
    --log-junit       →  classname = Tests.Feature.X.YTest , name = "it abc def"

Hai định dạng KHÁC nhau. Ghép tên test giữa chúng phải đoán lại quy tắc slug của
Pest, và một quy tắc đoán sai sẽ báo lệch giả — tức lại đúng thứ bệnh đang chữa.
Tên LỚP thì chuẩn hoá được tuyệt đối (bỏ tiền tố `P\\`, đổi `\\` thành `.`), nên
phép so theo lớp là ĐÚNG chứ không phải gần đúng.

Lệch một test cũng ra: số lượng theo từng lớp phải khớp. Khi một lớp lệch, in
kèm tên test đã chạy của lớp đó để người đọc biết ngay chỗ nào hụt.
"""
import re
import sys
from collections import Counter


def norm(cls: str) -> str:
    cls = cls.strip()
    if cls.startswith("P\\"):
        cls = cls[2:]
    return cls.replace("\\", ".")


def expected(path: str) -> Counter:
    x = open(path, encoding="utf-8", errors="replace").read()
    out = Counter()
    for m in re.finditer(r'<testMethod\b[^>]*\bid="([^"]*)"', x):
        cls = m.group(1).rsplit("::", 1)[0]
        out[norm(cls)] += 1
    return out


def executed(path: str) -> tuple[Counter, dict]:
    x = open(path, encoding="utf-8", errors="replace").read()
    out, names = Counter(), {}
    for m in re.finditer(r"<testcase\b([^>]*)/?>", x):
        attrs = dict(re.findall(r'(\w+)="([^"]*)"', m.group(1)))
        cls = attrs.get("classname")
        if not cls:
            continue
        out[norm(cls)] += 1
        names.setdefault(norm(cls), []).append(attrs.get("name", "?"))
    return out, names


def main() -> int:
    exp = expected(sys.argv[1])
    got, names = executed(sys.argv[2])

    n_exp, n_got = sum(exp.values()), sum(got.values())
    classes = sorted(set(exp) | set(got))
    problems = [(c, exp.get(c, 0), got.get(c, 0)) for c in classes if exp.get(c, 0) != got.get(c, 0)]

    if not problems:
        print(f"\033[32m✓\033[0m đối chiếu KHỚP: {n_exp} test phát hiện = {n_got} test đã chạy, "
              f"trên {len(classes)} lớp")
        return 0

    print(f"\033[31m✗\033[0m LỆCH: phát hiện {n_exp}, đã chạy {n_got} "
          f"(chênh {n_exp - n_got:+d}) — {len(problems)} lớp không khớp:", file=sys.stderr)
    for cls, e, g in problems:
        verdict = "KHÔNG CHẠY" if g == 0 else ("hụt" if g < e else "thừa")
        print(f"    {cls}: đáng lẽ {e}, đã chạy {g}  [{verdict}]", file=sys.stderr)
        if 0 < g < e:
            for nm in names.get(cls, [])[:5]:
                print(f"        đã chạy: {nm}", file=sys.stderr)
    print("\n  Một test không chạy KHÔNG làm suite đỏ — vì thế mới có phép đối chiếu này.",
          file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
