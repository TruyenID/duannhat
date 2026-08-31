#!/usr/bin/env python3
"""#3065 — KÊU khi `VERSION` trên `main` bỏ xa bản quán đang tải được.

## Vì sao cần rào này khi đã có #2783

Hai rào, hai câu hỏi ngược nhau, và cái thứ hai chưa ai hỏi:

    #2783  refuse-same-workstation-version   "số này ĐÃ phát hành chưa?"
    #3065  (file này)                        "số này ĐÃ TỚI QUÁN chưa?"

#2783 chạy TRONG lượt phát hành. Nó không thể nói gì khi lượt phát hành
**không bao giờ chạy** — mà đó chính là cách sự cố xảy ra: 6 lượt
`workstation-release` đỏ liên tiếp (hạn mức artifact storage), trang tải kẹt ở
v0.8.4 trong khi `main` đã 0.8.13. **12 ngày**, không một tín hiệu nào.

Mọi rào đang có đều đo QUÁ TRÌNH — "lượt chạy này xanh không". Rào này đo
KẾT QUẢ — "quán đang chạy bản nào". Ca trên cho thấy hai thứ đó rời nhau được
hàng tuần: mọi lượt chạy có thể xanh mà bản vẫn không tới nơi (rsync hỏng,
workflow không kích), và ngược lại.

## Vì sao có cửa sổ ân hạn thay vì so bằng

Bump `VERSION` rồi merge thì phải mất vài phút build + rsync. So bằng tuyệt đối
sẽ đỏ ở MỌI lần merge, và một rào kêu oan không bị tranh luận — nó bị TẮT.
Nên câu hỏi thật là "lệch **bao LÂU rồi**", không phải "có lệch không".

Mốc đếm là lúc `VERSION` được bump trên `main` (`git log -1 --format=%cI --
VERSION`), không phải lúc chạy rào — nếu đếm từ lúc chạy thì cửa sổ tự làm mới
mỗi lượt và rào không bao giờ kêu.

## Mã thoát

    0  khớp, hoặc lệch nhưng còn trong cửa sổ ân hạn
    1  LỆCH quá cửa sổ — quán không nhận được bản đã merge
    2  lỗi tham số
    3  không đọc được manifest — KHÔNG so được (khác hẳn 1)

Mã 3 tách riêng theo đúng án lệ #2922: gộp "không so được" vào "có lệch" thì
một trục trặc mạng sẽ đọc thành sự cố phát hành, và người ta học cách phớt lờ
cả hai. Với rào này thì **cả hai đều phải làm workflow đỏ** — nó là monitor,
mất khả năng đo cũng là một tin. Chỉ có điều tin đó phải nói đúng tên mình.
"""
from __future__ import annotations

import argparse
import contextlib
import io
import json
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

# Dùng lại nguyên bài học của #2783: Cloudflare đứng trước tempo-prod.godx.jp
# trả 403 cho UA mặc định của urllib. UA xưng danh chính rào này, KHÔNG giả
# trình duyệt.
GATE_USER_AGENT = (
    "tempo-workstation-drift-monitor/1 "
    "(+https://github.com/godx-jp/godx-tempo/issues/3065)"
)

DEFAULT_MANIFEST = "https://tempo-prod.godx.jp/downloads/workstation/manifest.json"
DEFAULT_GRACE_HOURS = 6
# Khâu 3 chờ một CON NGƯỜI đi cài, không chờ một job — nên dài hơn hẳn.
DEFAULT_INSTALL_GRACE_HOURS = 72


def build_request(url: str) -> urllib.request.Request:
    return urllib.request.Request(url, headers={"User-Agent": GATE_USER_AGENT})


def fetch_manifest(url: str, opener=urllib.request.urlopen, timeout: int = 20) -> dict:
    """`opener` tiêm được để self-test soi ĐÚNG thứ gửi lên đường dây."""
    with opener(build_request(url), timeout=timeout) as resp:
        return json.loads(resp.read().decode())


def fetch_latest(url: str, opener=urllib.request.urlopen, timeout: int = 20) -> str | None:
    return fetch_manifest(url, opener, timeout).get("latest")


def released_at_of(manifest: dict, version: str | None) -> str | None:
    """Mốc phát hành của một phiên bản trong manifest."""
    for entry in manifest.get("versions") or []:
        if normalize(entry.get("version")) == normalize(version):
            return entry.get("released_at")
    return manifest.get("updated_at")


def install_target(manifest: dict, now: datetime, grace_hours: float) -> str | None:
    """Bản MỚI NHẤT đã phát hành đủ lâu để đáng hỏi "quán cài chưa".

    Không phải `latest`. Đây là chỗ bản đầu của tôi SAI, và nó sai theo đúng cái
    bẫy mà file này đã tự viết ra ở khâu 1: hỏi "bản MỚI NHẤT đã đủ 72h chưa" thì
    **mỗi lần publish lại làm mới cửa sổ**. Đo thật lúc 2026-08-17 08:20 JST:
    v0.8.13 ra 35 phút trước ⇒ chưa đủ 72h ⇒ job khâu 3 SKIP — trong khi máy quán
    đang chạy v0.6.0 và đã trễ nhiều ngày. Publish đều đặn thì khâu 3 không bao
    giờ được hỏi lấy một lần.

    Câu hỏi đúng: "có bản nào phát hành quá 72h mà máy vẫn chưa có không". Mốc
    đó KHÔNG reset được bằng cách phát hành thêm.

    None ⇒ chưa bản nào đủ cũ (kho mới toanh), lúc đó im là đúng.
    """
    best: tuple[datetime, str] | None = None
    cutoff = now - timedelta(hours=grace_hours)

    for entry in manifest.get("versions") or []:
        ts = parse_ts(entry.get("released_at"))
        v = entry.get("version")
        if ts is None or not v:
            continue
        if ts <= cutoff and (best is None or ts > best[0]):
            best = (ts, v)

    if best is not None:
        return best[1]

    # Không entry nào có mốc đọc được. Lùi về `updated_at` của cả manifest —
    # KHÔNG trả None, vì "không đo được mốc" không phải "không có gì để cài".
    updated = parse_ts(manifest.get("updated_at"))
    if updated is not None and updated <= cutoff:
        return manifest.get("latest")
    return None


def normalize(v: str | None) -> str:
    v = (v or "").strip()
    if v.lower().startswith("v") and len(v) > 1 and v[1].isdigit():
        return v[1:]
    return v


def parse_ts(s: str | None) -> datetime | None:
    if not s:
        return None
    try:
        d = datetime.fromisoformat(s.strip().replace("Z", "+00:00"))
    except ValueError:
        return None
    return d if d.tzinfo else d.replace(tzinfo=timezone.utc)


def verdict(
    version: str,
    latest: str | None,
    bumped_at: datetime | None,
    now: datetime,
    grace_hours: float = DEFAULT_GRACE_HOURS,
) -> tuple[str, str]:
    """('ok'|'grace'|'drift'|'unknown', câu giải thích cho người đọc).

    Hàm THUẦN — không mạng, không đồng hồ ngầm. `now` là tham số vì một rào về
    thời gian mà tự đọc đồng hồ thì không test được cửa sổ của chính nó.
    """
    v, l = normalize(version), normalize(latest)

    if not l:
        # Trang tải chưa từng phát hành gì. Đây KHÔNG phải "khớp" — nó là chưa
        # đo được. Trả 'ok' ở đây là im lặng đúng lúc đáng kêu nhất.
        return "unknown", "manifest không có `latest` — chưa phát hành lần nào, hoặc file hỏng"

    if v == l:
        return "ok", f"khớp: main={v}, trang tải={l}"

    if bumped_at is None:
        # Không biết bump lúc nào ⇒ không tính được đã lệch bao lâu. Coi là lệch
        # (fail-closed): lệch có thật, chỉ thiếu con số thời gian.
        return "drift", f"main={v} ≠ trang tải={l}, và không đọc được mốc bump để tính thời lượng"

    age = now - bumped_at
    if age < timedelta(hours=grace_hours):
        mins = int(age.total_seconds() // 60)
        return "grace", f"main={v} ≠ trang tải={l}, mới bump {mins} phút trước — còn trong cửa sổ {grace_hours}h"

    hours = age.total_seconds() / 3600
    days = hours / 24
    how = f"{days:.1f} ngày" if hours >= 48 else f"{hours:.1f} giờ"
    return "drift", f"main={v} ≠ trang tải={l} suốt {how} — bản đã merge KHÔNG tới quán"


def _self_test() -> int:
    fails = 0

    def check(cond: bool, label: str) -> None:
        nonlocal fails
        if cond:
            print(f"  ok   {label}")
        else:
            print(f"  FAIL {label}")
            fails += 1

    now = datetime(2026, 8, 17, 12, 0, tzinfo=timezone.utc)
    long_ago = now - timedelta(days=12)
    just_now = now - timedelta(minutes=20)

    check(verdict("0.8.13", "v0.8.13", long_ago, now)[0] == "ok",
          "khớp (thừa/thiếu prefix v vẫn khớp) ⇒ ok")
    check(verdict("0.8.13", "v0.8.4", long_ago, now)[0] == "drift",
          "lệch 12 ngày ⇒ drift — ĐÚNG ca đã xảy ra")
    check(verdict("0.8.13", "v0.8.4", just_now, now)[0] == "grace",
          "vừa bump 20 phút ⇒ grace, KHÔNG kêu oan giữa lúc build")
    check(verdict("0.8.13", "v0.8.4", None, now)[0] == "drift",
          "không biết mốc bump ⇒ fail-closed, không nuốt")
    check(verdict("0.8.13", None, long_ago, now)[0] == "unknown",
          "manifest thiếu `latest` ⇒ unknown, KHÔNG phải ok")
    check(verdict("0.8.13", "", long_ago, now)[0] == "unknown",
          "latest rỗng ⇒ unknown")

    # Ranh giới cửa sổ, hai phía — một rào về thời gian mà không ghim biên thì
    # sửa lệch dấu `<`/`<=` không có gì đỏ.
    edge = now - timedelta(hours=DEFAULT_GRACE_HOURS)
    check(verdict("2", "1", edge + timedelta(minutes=1), now)[0] == "grace",
          "trong cửa sổ 1 phút ⇒ vẫn grace")
    check(verdict("2", "1", edge - timedelta(minutes=1), now)[0] == "drift",
          "quá cửa sổ 1 phút ⇒ drift")

    # Chiều ngược: rào phải biết IM. Nếu mọi đầu vào đều ra 'drift' thì nó vô
    # dụng y như luôn 'ok' — chỉ khác là nó bị tắt sau vài ngày.
    kinds = {verdict(*a)[0] for a in [
        ("1", "1", long_ago, now), ("2", "1", long_ago, now),
        ("2", "1", just_now, now), ("2", None, long_ago, now),
    ]}
    check(kinds == {"ok", "drift", "grace", "unknown"},
          "bốn đầu vào cho bốn kết luận khác nhau — rào không kẹt một phía")

    def _mf(*pairs):
        return {"latest": pairs[0][0], "updated_at": None,
                "versions": [{"version": v, "released_at": t.isoformat()} for v, t in pairs]}

    # ĐÚNG CA ĐÃ SAI: bản mới nhất vừa ra, nhưng bản trước đã cũ ⇒ VẪN phải hỏi.
    check(install_target(_mf(("v0.8.13", now - timedelta(minutes=35)),
                             ("v0.8.4", now - timedelta(days=5))), now, 72) == "v0.8.4",
          "publish bản mới KHÔNG reset cửa sổ — vẫn đòi bản cũ đã quá hạn")
    check(install_target(_mf(("v0.8.13", now - timedelta(days=9)),
                             ("v0.8.4", now - timedelta(days=15))), now, 72) == "v0.8.13",
          "mọi bản đều quá hạn ⇒ lấy bản MỚI NHẤT trong số đó")
    check(install_target(_mf(("v1.0.0", now - timedelta(hours=2))), now, 72) is None,
          "kho chỉ có bản vừa ra ⇒ None, im là đúng")
    check(install_target(_mf(("v1.0.0", now - timedelta(hours=72))), now, 72) == "v1.0.0",
          "đúng biên 72h ⇒ tính (<=, không phải <)")
    check(install_target({"latest": "v2", "updated_at": (now - timedelta(days=9)).isoformat(),
                          "versions": []}, now, 72) == "v2",
          "entry không có mốc ⇒ lùi về updated_at, KHÔNG trả None")
    check(install_target({"latest": "v2", "versions": []}, now, 72) is None,
          "không mốc nào đọc được ⇒ None")

    _m = {"latest": "v0.8.13", "updated_at": "2026-01-01T00:00:00Z", "versions": [
        {"version": "v0.8.13", "released_at": "2026-08-16T22:42:07Z"},
        {"version": "v0.8.4", "released_at": "2026-08-16T02:50:38Z"}]}
    check(released_at_of(_m, "0.8.13") == "2026-08-16T22:42:07Z",
          "released_at_of lấy đúng entry, chuẩn hoá prefix v")
    check(released_at_of(_m, "v9.9.9") == "2026-01-01T00:00:00Z",
          "không thấy entry ⇒ lùi về updated_at của manifest, không trả None")

    check(parse_ts("2026-08-16T22:42:07Z") == datetime(2026, 8, 16, 22, 42, 7, tzinfo=timezone.utc),
          "parse mốc ISO có hậu tố Z")
    check(parse_ts("rác") is None and parse_ts(None) is None,
          "mốc hỏng ⇒ None, không ném")

    # Request THẬT SỰ gửi đi — soi bằng opener giả, không đọc file nguồn.
    sent: list[object] = []

    class _FakeResp:
        def read(self) -> bytes:
            return b'{"latest": "v0.8.13"}'

        def __enter__(self) -> "_FakeResp":
            return self

        def __exit__(self, *exc: object) -> bool:
            return False

    def _fake_opener(req: object, timeout: int | None = None) -> _FakeResp:
        sent.append(req)
        return _FakeResp()

    check(fetch_latest("https://example.invalid/m.json", opener=_fake_opener) == "v0.8.13",
          "fetch_latest đọc được latest")
    req = sent[0] if sent else None
    ua = ""
    if isinstance(req, urllib.request.Request):
        ua = dict((k.lower(), v or "") for k, v in req.header_items()).get("user-agent", "")
    check(bool(ua) and "tempo" in ua.lower() and "mozilla" not in ua.lower(),
          "request mang UA xưng danh — thiếu là Cloudflare 403 và rào mù vĩnh viễn")

    # Không đọc được manifest ⇒ mã 3, KHÔNG phải 1 (#2922).
    with contextlib.redirect_stdout(io.StringIO()):
        rc = main(["--version", "9.9.9", "--manifest-url", "file:///dev/null/khong-co.json"])
    check(rc == 3, "không đọc được manifest ⇒ mã 3 (không so được), KHÔNG phải 1")

    return fails


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--version", help="VERSION trên main (vd 0.8.13)")
    p.add_argument("--bumped-at", help="ISO8601 — lúc VERSION được bump trên main")
    p.add_argument("--manifest-url", default=DEFAULT_MANIFEST)
    p.add_argument("--grace-hours", type=float, default=DEFAULT_GRACE_HOURS)
    p.add_argument("--install-grace-hours", type=float, default=DEFAULT_INSTALL_GRACE_HOURS,
                   help="Bản phát hành lâu hơn ngần này mới đáng hỏi quán đã cài chưa")
    p.add_argument("--self-test", action="store_true")
    args = p.parse_args(argv)

    if args.self_test:
        fails = _self_test()
        print(f"{fails} FAIL" if fails else "tất cả pass")
        return 1 if fails else 0

    if not args.version:
        print("::error::--version bắt buộc (trừ khi --self-test)", file=sys.stderr)
        return 2

    try:
        manifest = fetch_manifest(args.manifest_url)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as e:
        print(f"::error::không đọc được manifest production ({e}) — KHÔNG so được")
        _emit(kind="unreadable", detail=f"không đọc được {args.manifest_url}: {e}",
              version=args.version, latest="", install_target="", install_due="false")
        return 3

    latest = manifest.get("latest")
    now = datetime.now(timezone.utc)
    target = install_target(manifest, now, args.install_grace_hours)

    kind, detail = verdict(args.version, latest, parse_ts(args.bumped_at), now, args.grace_hours)
    print(f"{kind}: {detail}")
    print(f"bản máy quán ĐÁNG LẼ phải có: {target or '(chưa bản nào đủ '+str(args.install_grace_hours)+'h)'}"
          f"  ·  bản mới nhất trên trang tải: {latest}")
    _emit(kind=kind, detail=detail, version=args.version, latest=latest or "",
          install_target=target or "", install_due="true" if target else "false")

    if kind in ("drift", "unknown"):
        print(f"::error::{detail}")
        return 1
    return 0


def _emit(**kv: str) -> None:
    """Ghi ra GITHUB_OUTPUT để bước sau dựng nội dung issue."""
    import os

    path = os.environ.get("GITHUB_OUTPUT")
    if not path:
        return
    with open(path, "a", encoding="utf-8") as fh:
        for k, v in kv.items():
            fh.write(f"{k}={str(v).replace(chr(10), ' ')}\n")


if __name__ == "__main__":
    sys.exit(main())
