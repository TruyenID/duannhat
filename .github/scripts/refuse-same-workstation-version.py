#!/usr/bin/env python3
"""#2783 — từ chối phát hành lại cùng số VERSION.

Ba build khác nhau đã ship dưới v0.5.0 trong một ngày vì workflow kích trên
thay đổi mã, không trên bump phiên bản, rồi script publish xoá entry trùng số.

Hàm thuần `should_refuse` test được không cần mạng. CLI fetch manifest production
và exit 1 khi latest trùng VERSION, trừ --force.
"""
from __future__ import annotations

import argparse
import contextlib
import io
import json
import sys
import urllib.error
import urllib.request


# Cloudflare đứng trước tempo-prod.godx.jp trả 403 cho UA mặc định của urllib.
# Đo 2026-08-13, cùng máy cùng IP: curl (UA mặc định) → 200 ·
# curl -A 'Python-urllib/3.11' → 403 · urlopen không header → HTTPError 403.
# Rào fail-closed nên thiếu header này = MỌI bản phát hành đỏ, kể cả đã bump số.
# UA xưng danh chính cái rào này — KHÔNG giả trình duyệt.
GATE_USER_AGENT = (
    "tempo-workstation-release-gate/1 "
    "(+https://github.com/godx-jp/godx-tempo/issues/2783)"
)


def build_request(url: str) -> urllib.request.Request:
    """Request có User-Agent — xem GATE_USER_AGENT vì sao bắt buộc."""
    return urllib.request.Request(url, headers={"User-Agent": GATE_USER_AGENT})


def fetch_latest(url: str, opener=urllib.request.urlopen, timeout: int = 20) -> str | None:
    """Đọc `latest` từ manifest production.

    `opener` tiêm được để self-test soi ĐÚNG thứ gửi lên đường dây — gọi thẳng
    urlopen(url) ở đây là mất header, và đó chính là lỗi đã chặn cả release.
    """
    with opener(build_request(url), timeout=timeout) as resp:
        data = json.loads(resp.read().decode())
    return data.get("latest")


def normalize(v: str | None) -> str:
    v = (v or "").strip()
    if v.lower().startswith("v") and len(v) > 1 and v[1].isdigit():
        return v[1:]
    return v


def should_refuse(version: str, latest: str | None, force: bool) -> bool:
    """True ⇒ bước publish phải ĐỎ.

    force=True (workflow_dispatch) cho phép phát hành lại cùng số khi build hỏng.
    latest rỗng = lần đầu, không từ chối.
    """
    if force:
        return False
    if not normalize(latest):
        return False
    return normalize(version) == normalize(latest)


def _self_test() -> int:
    fails = 0

    def check(cond: bool, label: str) -> None:
        nonlocal fails
        if cond:
            print(f"  ok   {label}")
        else:
            print(f"  FAIL {label}")
            fails += 1

    check(should_refuse("v0.5.0", "v0.5.0", False) is True,
          "VERSION trùng latest ⇒ từ chối")
    check(should_refuse("0.5.0", "v0.5.0", False) is True,
          "thiếu/thừa prefix v vẫn trùng")
    check(should_refuse("v0.6.0", "v0.5.0", False) is False,
          "VERSION đã bump ⇒ cho qua")
    check(should_refuse("v0.5.0", "v0.5.0", True) is False,
          "force=true ⇒ cho phép phát hành lại cùng số")
    check(should_refuse("v0.5.0", None, False) is False,
          "chưa có latest (lần đầu) ⇒ cho qua")
    check(should_refuse("v0.5.0", "", False) is False,
          "latest rỗng ⇒ cho qua")
    # chiều ngược: gỡ so sánh, bài "trùng ⇒ từ chối" phải đỏ — ghim bằng chính
    # hàm, không bằng đọc file.
    check(should_refuse("v0.5.0", "v0.5.0", False) != should_refuse("v0.6.0", "v0.5.0", False),
          "hai chiều khác nhau — rào không xanh vô điều kiện")

    # Request THẬT SỰ gửi đi: soi bằng opener giả, không đọc file nguồn.
    sent: list[object] = []

    class _FakeResp:
        def read(self) -> bytes:
            return b'{"latest": "v0.5.0"}'

        def __enter__(self) -> "_FakeResp":
            return self

        def __exit__(self, *exc: object) -> bool:
            return False

    def _fake_opener(req: object, timeout: int | None = None) -> _FakeResp:
        sent.append(req)
        return _FakeResp()

    got = fetch_latest("https://example.invalid/manifest.json", opener=_fake_opener)
    check(got == "v0.5.0", "fetch_latest đọc được latest từ manifest")

    req = sent[0] if sent else None
    headers = {}
    if isinstance(req, urllib.request.Request):
        headers = {k.lower(): (v or "") for k, v in req.header_items()}
    ua = headers.get("user-agent", "").strip()
    check(bool(ua),
          "request mang User-Agent — thiếu là Cloudflare 403, rào đỏ MỌI bản phát hành")
    check("tempo" in ua.lower() and "mozilla" not in ua.lower(),
          "User-Agent xưng danh rào tempo, không giả trình duyệt")

    # Không đọc được manifest ⇒ ĐỎ. Rào phát hành fail-open tệ hơn chính lỗi nó
    # canh. Nuốt stdout để ::error:: giả không thành annotation đỏ trong Actions.
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        rc = main(["--version", "v9.9.9",
                   "--manifest-url", "file:///tempo/manifest-khong-ton-tai.json"])
    # #2922 — khẳng định KHÁC 0, không ghim con số. Ý định của bài này là
    # "bước publish không phát hành mù", và mọi mã khác 0 đều thoả (set -e).
    # Ghim đúng số 1 làm nó đỏ khi mã được TÁCH ra để `detect-changes` phân biệt
    # "không so được" với "trùng số" — tức nó canh chi tiết cài đặt, không canh
    # bất biến. Ca ngay dưới ghim con số 3 riêng, đúng chỗ con số đó có nghĩa.
    check(rc != 0 and "::error::" in buf.getvalue(),
          "không đọc được manifest ⇒ fail-closed (khác 0), không phát hành mù")
    # #2922 — MÃ THOÁT phải phân biệt được "trùng số" với "không so được".
    #
    # Cùng câu hỏi, hai vị trí, fail NGƯỢC CHIỀU: publish fail-closed, còn
    # `detect-changes` fail-OPEN (chỉ bỏ qua khi mã = 1). Gộp hai ca vào cùng
    # mã 1 như bản cũ thì một trục trặc mạng sẽ âm thầm nuốt một bản phát hành
    # thật — và không gì đỏ.
    with contextlib.redirect_stdout(io.StringIO()):
        same = main(["--version", "9.9.9",
                     "--manifest-url", "file:///dev/null/manifest.json"])
    check(same == 3, "không đọc được manifest ⇒ mã 3 (không so được), KHÔNG phải 1")

    return fails


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--version", help="số sắp phát hành (vd v0.6.0)")
    p.add_argument("--manifest-url",
                   default="https://tempo-prod.godx.jp/downloads/workstation/manifest.json")
    p.add_argument("--force", action="store_true")
    p.add_argument("--self-test", action="store_true")
    args = p.parse_args(argv)

    if args.self_test:
        fails = _self_test()
        if fails:
            print(f"{fails} FAIL")
            return 1
        print("tất cả pass")
        return 0

    if not args.version:
        print("::error::--version is required (unless --self-test)", file=sys.stderr)
        return 2

    if args.force:
        # force vẫn cho qua (workflow_dispatch-only), nhưng KHÔNG im lặng: log
        # phải ghi lại chính xác entry manifest nào sắp bị ghi đè. Lỗi mạng ở
        # nhánh này không được chặn — force là lối thoát khi production hỏng.
        try:
            latest = fetch_latest(args.manifest_url)
        except Exception as e:  # noqa: BLE001 — lối thoát không được chết vì mạng
            print(f"::warning::force=true — không đọc được manifest production ({e}); "
                  "phát hành mà không so được số.")
        else:
            if should_refuse(args.version, latest, False):
                print(f"::warning::force=true — VERSION {args.version} TRÙNG latest production "
                      f"({latest}); entry cũ trong manifest SẼ BỊ GHI ĐÈ. Ghi đè có chủ ý, "
                      "không phải bump. (#2783)")
            else:
                print(f"force=true — VERSION={args.version}  manifest.latest={latest}")
        print("force=true — cho phép phát hành lại cùng số.")
        return 0

    latest = None
    try:
        latest = fetch_latest(args.manifest_url)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as e:
        # #2922 — mã thoát 3, KHÔNG phải 1: "không so được" ≠ "trùng số".
        #
        # Cùng một câu hỏi được hỏi ở HAI vị trí cần fail NGƯỢC CHIỀU nhau:
        #
        #   publish        — fail CLOSED: không so được thì không phát hành mù.
        #   detect-changes — fail OPEN:   không so được thì cứ build; rào publish
        #                    vẫn đứng đó. Bỏ qua ở đây vì một trục trặc mạng là
        #                    ÂM THẦM NUỐT một bản phát hành thật.
        #
        # Cả hai mã đều khác 0 nên bước publish (set -e) hành xử y như cũ.
        print(f"::error::không đọc được manifest production ({e}) — không phát hành khi không so được")
        return 3

    print(f"VERSION={args.version}  manifest.latest={latest}")
    if should_refuse(args.version, latest, False):
        print(f"::error::VERSION {args.version} trùng latest đang phát hành ({latest}). "
              "Bump file VERSION rồi merge lại, hoặc workflow_dispatch với force=true "
              "khi thật sự cần phát hành lại đúng commit đó. (#2783)")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
