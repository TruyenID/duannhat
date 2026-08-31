---
title: Menu campaign window — one answer on every surface
category: guide
tags: [menu, schedule, campaign, recurrence, pos, workstation, issue-1970, issue-1979, issue-1237]
summary: "How a menu schedule says WHICH DAYS it covers — weekly, monthly, or an explicit list of dates (#1979) — inside a calendar window that every reading surface now applies (#1970). Covers the ruling reversal, the NULL semantics, one-kind-per-row, and the four readers that must stay in step."
related: [business-time, tax-types]
---

# Menu schedules — which days, and inside which window (#1970, #1979)

A menu schedule can carry a **calendar window** — `menu_schedules.start_date` /
`end_date`. A menu dated 1–15 Feb is a campaign: outside those dates it is not
on sale.

**NULL means unbounded, not "unknown".** There is no DB default on either
column, and a schedule with both NULL behaves exactly as it always did — always
on, subject only to its weekday mask and time window.

## The ruling, and the ruling it replaced

Ruled 2026-07-30 (**#1237**): the window was a **customer-facing device only**.
The guest scanning the QR in July could not see a campaign menu that ended in
February, while the POS beside them could still pick it and sell from it. The
stated reason was live sales — pre-orders, regulars, fixing someone's mistake.

Ruled 2026-08-06 (**#1970**): **one shop, one moment, one answer.** The window is
applied on the staff surfaces too. The asymmetry cost more than it bought: the
same shop gave two answers to "which menu is on sale", and nobody standing at the
till could tell which one the customer was looking at.

> If staff again need to sell outside the window, that must arrive as an
> **explicit permission**, not as a quiet widening of one query. Widening any
> single surface re-creates exactly the divergence this replaced.

## HQ sets it; the shop may narrow or shift it

`branch_schedule_overrides` carries `start_date` / `end_date` alongside the
`start_time` / `end_time` / `days_of_week` it already had. The read rule is the
same COALESCE as the times: **shop value if present, else HQ's**.

| The shop wants… | Possible? |
|---|---|
| Run HQ's 1–15 Feb campaign only 5–10 Feb | Yes — set both dates |
| Start late, end with HQ | Yes — set `start_date` only |
| Extend past HQ's end date | Yes — set a later `end_date` |
| Remove the window entirely and run it forever | **No** |

The last row is a consequence of the NULL semantics, not an oversight: NULL on
the override column already means "follow HQ", so there is no spare value left to
spell "unbounded". Clearing a window HQ has set is HQ's call.

Dates are **inclusive** bounds, so `start_date == end_date` is a valid one-day
campaign. Only `start > end` is rejected (422).

## The four surfaces that must stay in step

| Surface | Code |
|---|---|
| Guest (QR / kiosk) | `CustomerMenuService` — list, sections, and the "opens next at" banner |
| POS day picker (Cloud) | `MenuService::listActiveBranchMenusForShopByDay` |
| Single live menu (Cloud) | `MenuService::getCurrentMenu` |
| Offline LAN POS | `MenuScheduleReplicaController` feed → workstation SQLite → `handleLocalPosMenuByDay` |

The replica feed is the one that is easy to forget, and forgetting it is worse
than it looks: the LAN till becomes the **looser** of the two, so an expired
campaign stays sellable precisely when the shop is offline and nobody can see it
happening. The feed therefore sends the **already-COALESCEd** effective dates —
the workstation does not re-walk the shop-over-HQ resolution in Go, so there is
no second copy of the rule to drift.

`tests/Feature/Menu/CampaignWindowSurfaceAgreementTest.php` is the ratchet. Each
case lifts the window and re-reads, so a surface that returns nothing for an
unrelated reason fails instead of passing silently.

## Two traps already paid for

**Business time, not the app clock.** Which calendar day it is depends on the
**branch** (`BusinessClock::businessDate($branchId)` on Cloud, `shopToday()` on
the workstation). At 2026-02-15 16:00 UTC a Tokyo shop is already on 16 Feb while
a Hanoi shop is still on the 15th — the campaign is over for one and live for the
other. See [Business time](business-time.md).

**`DATE()` around every comparison.** The `date` cast writes through
`getDateFormat()`, so a row saved via Eloquent lands as `2026-02-10 00:00:00` in
SQLite (tests) while MySQL's `DATE` column truncates to `2026-02-10`. Comparing
those as plain strings makes the two engines disagree **on the boundary day
itself** — the campaign's first day live on MySQL, hidden on SQLite. `DATE(NULL)`
is still NULL, so the unbounded arm is unaffected.

## Deploy order

**Backend before workstation.** A new workstation against an old Cloud simply
receives no date columns and keeps its previous behaviour (degrades gracefully);
the reverse has the workstation reading columns that do not exist yet.


---

# Which days a schedule covers (#1979)

The window above says *between which dates* a schedule may fire. **Which days
inside it** is a separate question, answered by the row's `recurrence_kind`:

| Kind | Column read | Means |
|---|---|---|
| `Weekly` (default) | `days_of_week`, bit0=Sun … bit6=Sat | Every Monday and Friday |
| `Monthly` | `days_of_month`, bit0=1st … bit30=31st | The 1st and 15th of every month |
| `SpecificDates` | rows in `menu_schedule_dates` | Exactly 5 Aug, 12 Aug, 20 Aug — and not again |

`Weekly` is the default, so every row written before #1979 still means precisely
what it meant.

## One kind per row — and that is how you get "both"

A row carrying both a weekday mask and a day-of-month mask would have to answer
whether "Monday **and** the 15th" is the intersection (almost never what anyone
wants) or the union (in which case the weekday box quietly stops meaning
anything). Neither reading is guessable from the UI.

So a row has one kind, and **a menu's schedule rows already OR together** at
every reader. "The 1st and 15th of every month, plus a one-off on 20 August" is
two rows. No new combining rule exists, because none is needed.

## Ruled, so nobody has to re-derive them

- **The 31st does not slide.** In a 30-day month a `Monthly` row listing the 31st
  simply does not fire. Sliding it onto the 30th would put a menu on sale on a
  day nobody chose. A real "last day of month" option is a separate feature.
- **`SpecificDates` does not recur.** Once its dates pass the row is spent. That
  is the whole difference from `Monthly`, and the test suite asserts it directly
  — otherwise the two kinds are indistinguishable on their first occurrence.
- **Switching kind does not erase the other columns.** A row that was `Monthly`
  and becomes `Weekly` keeps its day-of-month mask, inert. Flipping back and
  forth must not destroy what the user typed.
- **A shop may override `days_of_month`**, exactly as it may override the weekday
  mask and the window. It may **not** override `recurrence_kind` — changing the
  kind reinterprets every other column on the row rather than adjusting it — and
  it may not yet override a `SpecificDates` list, which is a set and would need
  its own table and its own feed. Both are deliberate, and both are open to
  revisit if a shop actually asks.

## One definition, four readers

`App\Support\MenuScheduleDateRule` answers "is this row on, on date D, at branch
B" for **all** of the surfaces listed earlier. Before it, each surface carried its
own copy of the day filter — which is exactly how #1237 managed to have the POS
and the guest disagree for four months. Adding two kinds to four hand-rolled
copies would have re-created that within a week.

The workstation is the one reader that cannot call into it, so it carries a
mirror (`scheduleCoversDate` in `internal/handler/local_pos_menus.go`). The feed
sends it the **rule**, not a pre-expanded list of dates: an expansion needs a
horizon, and a till left offline past that horizon would go quietly blank days
after its last sync, with nothing on screen to connect the two.
