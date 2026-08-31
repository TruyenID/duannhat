#!/usr/bin/env python3
"""Probe production and report which frontend-visible fields the backend serves.

Read-only. Sends two GETs and prints a pass/fail line per check; it changes
nothing and needs no credentials.

    python3 scripts/verify-prod-payload.py
    python3 scripts/verify-prod-payload.py --base https://staging.example.jp --branch ningyocho

Why this exists
---------------
customer-web deploys automatically on push; the backend deploys only when a
`v*.*.*` tag is pushed. So the frontend routinely runs ahead of the API, reads a
field that is not there yet, and degrades SILENTLY — no 500, no log, just a wrong
screen. On 2026-07-29 that state had produced five symptoms at once (#1207 #1209
#1176 #1128 + an empty featured carousel), and **three of the five announced
nothing**: a missing tax line still looks like a valid bill, and an empty
carousel looks like a shop that configured none.

So "we deployed" is not the same as "it is fixed". Run this after a backend
deploy — and before one, to see what is currently broken.

Exit code is 1 when any check fails, so CI or a deploy step can gate on it.
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.request

DEFAULT_BASE = "https://tempo-prod.godx.jp"
DEFAULT_BRANCH = "ningyocho"
TIMEOUT = 30


def get(url: str):
    # An explicit UA is required: the edge in front of production answers 403 to
    # urllib's default one, which would read as "field missing" and make every
    # check below lie.
    request = urllib.request.Request(url, headers={"User-Agent": "tempo-payload-probe/1.0", "Accept": "application/json"})
    with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
        return json.loads(response.read().decode())


def rows_of(payload):
    data = payload.get("data", payload)
    return [row for row in data if isinstance(row, dict)] if isinstance(data, list) else []


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default=DEFAULT_BASE)
    parser.add_argument("--branch", default=DEFAULT_BRANCH)
    args = parser.parse_args()

    checks: list[tuple[str, bool, str]] = []

    try:
        branches = rows_of(get(f"{args.base}/api/v1/customer/branches"))
    except Exception as exc:  # noqa: BLE001 - a probe reports, it does not raise
        print(f"FAIL  could not read /customer/branches: {exc}")
        return 1

    total = len(branches)

    # #1207 — without it customer-web falls back to the VIEWER's clock and shows
    # an open Tokyo shop as closed to anyone in a westward timezone.
    with_tz = sum(1 for b in branches if b.get("timezone"))
    checks.append((
        f"#1207 branches carry timezone ({with_tz}/{total})",
        total > 0 and with_tz == total,
        "customers in another timezone see an open shop as closed",
    ))

    # #1209 — a tax type is ONE rate since #1099. The old pair means the
    # frontend reads `.rate`, finds nothing, and drops the tax line entirely.
    typed = [b.get("default_tax_type") for b in branches if isinstance(b.get("default_tax_type"), dict)]
    new_shape = sum(1 for t in typed if "rate" in t)
    checks.append((
        f"#1209 default_tax_type uses the single-rate shape ({new_shape}/{len(typed)})",
        len(typed) > 0 and new_shape == len(typed),
        "the tax line disappears from checkout and the total still looks valid",
    ))

    # #1176 — the brand logo columns the Customer settings screen writes.
    brand = next((b["brand"] for b in branches if isinstance(b.get("brand"), dict)), {})
    for field in ("customer_header_logo_url", "customer_order_logo_url", "customer_order_subtitle"):
        checks.append((
            f"#1176 brand.{field}",
            field in brand,
            "saving the logo appears to work and silently does nothing",
        ))

    try:
        menu = get(f"{args.base}/api/v1/customer/branches/{args.branch}/menu")
        data = menu.get("data", menu)
        categories = data.get("categories") or []
        items = [i for c in categories for i in (c.get("items") or [])]
    except Exception as exc:  # noqa: BLE001
        print(f"FAIL  could not read the menu for {args.branch}: {exc}")
        categories, items = [], []

    # #1187 — customer-web filters on is_featured; absent means the carousel is
    # empty everywhere, and an empty carousel is indistinguishable from a shop
    # that simply featured nothing. This one is why the script exists.
    checks.append((
        "#1187 categories carry is_featured (featured carousel)",
        any("is_featured" in c for c in categories),
        "the featured carousel is empty at every shop and nothing says so",
    ))

    checks.append((
        "#1099 menu items carry tax_rate",
        any("tax_rate" in i for i in items),
        "per-item tax is missing downstream of the branch default",
    ))

    # Present-and-wrong rather than absent: the alcohol concept was deleted on
    # 2026-07-26, so a payload still carrying it is provably pre-that-deploy.
    checks.append((
        "#1099 menu items no longer carry is_alcohol",
        len(items) > 0 and not any("is_alcohol" in i for i in items),
        "the payload predates the alcohol-concept removal",
    ))

    print(f"probe {args.base} (branch={args.branch}, {total} branches, {len(items)} items)\n")
    failed = 0
    for label, ok, consequence in checks:
        print(f"{'PASS' if ok else 'FAIL'}  {label}")
        if not ok:
            failed += 1
            print(f"        → {consequence}")

    print()
    if failed:
        print(f"{failed} check(s) failed — the backend is behind the frontend.")
        print("Deploy the backend, then re-run. If a check still fails after the")
        print("deploy it is DATA, not code — there are no backfill commands (#2188):")
        print("  is_featured empty     -> set it per menu section in HQ (menu-section")
        print("                           CRUD accepts is_featured), or reseed")
        print("  brand has no tax type -> php artisan db:seed --class=TaxTypeSeeder")
        print("  stale catalog snapshot -> php artisan catalog:rebuild-revisions")
        return 1

    print("All checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
