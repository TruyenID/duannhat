---
title: Agent issue loop (tal)
category: guide
tags: [agent, issue-loop, lease, worktree, tal]
summary: "How several Claude Code sessions share one backlog without collisions — atomic leases on refs/tempo/leases, worktree plus issue-N branch, PR into dev, and hand-off to a review session."
related: [agent-loop-skills]
---

# The automated issue loop (tal) — many sessions, no collisions

> **This document is the MECHANISM.** For what a session must do, in what order,
> and where it trips up — the roles of the two skills, which rules the machine
> enforces and which are only words, the eight pitfalls already paid for, and the
> incident runbook — read [The skills of the issue loop](agent-loop-skills.md).
> The two documents deliberately do not repeat each other.

Several Claude Code sessions run against this repo's GitHub backlog at once. The
hard requirement: **no issue may ever be worked by two sessions at the same time.**

This repo has already paid for not having that mechanism: twice, two sessions built
the same fix in parallel (#1201 cleaning up console.log, #1196 renumbering a
migration), and once one session's uncommitted WIP was swept into another's commit.
Even while this system was being built, four issues still carried
`status:executing` from dead sessions (#1296, #1204, #1098, #968) — proof that
**a label alone is not enough**.

| Component | Where |
|---|---|
| The CLI | `.claude/tools/agent-loop/tal` |
| The CODE role | `.claude/skills/issue-work/SKILL.md` |
| The REVIEW role | `.claude/skills/issue-review/SKILL.md` |
| The hard guard | The `PreToolUse` hook in `.claude/settings.json` → `tal hook-guard` |
| Repo policy | `.claude/agent-loop.json` + `.claude/agent-loop/*.md` |

## The mechanism now lives in a plugin — both run side by side

The **mechanism** (leases, fencing, the queue, GC, the hook) is now the plugin
**`godx-jp/claude-agent-loop`**. TempoFast's **policy** stays here:

- `.claude/agent-loop.json` — the base branch, label names, `severityOrder`,
  `setup` / `setupVerify` / `fullSuite`
- `.claude/agent-loop/work-policy.md` — the mandatory command order, business time,
  money, local config
- `.claude/agent-loop/review-checklist.md` — the review checklist, where every item
  is a place that has burned us
- `.claude/agent-loop/test-policy.md` — how to run narrow tests, and when widening
  is allowed

Installing the plugin:

```
/plugin marketplace add godx-jp/claude-agent-loop
/plugin install agent-loop@godx
/reload-plugins
```

The plugin's skills have their own namespace (`/agent-loop:issue-work`) so they do
**not** collide with the two skills already in `.claude/skills/`. Both copies of
`tal` read the **same** `refNamespace` (`refs/tempo/leases/`, pinned in
`.claude/agent-loop.json`) so they **see each other's leases** — which is
mandatory, because two different namespaces would destroy mutual exclusion
entirely.

**When to delete the in-repo copy:** once the plugin has run smoothly and no
session still uses the `.claude/tools/agent-loop/tal` path. At that point delete
`tal`, the two skills and the hook in `settings.json`, and keep the config and
policy files. Do not delete early — a running session would be cut off mid-task.

## The naming rule: `issue-<number>`, no exceptions

The branch **and** the worktree are always `issue-<the real issue number>`. No
slugs, no `feat/`, no suffixes. If another branch is needed, **open a sub-issue**
and use its number: `tal claim <sub> --split`.

This is not aesthetics. When `dev` breaks at a commit, the only question is "which
PR produced it" — with one name per issue that is a one-step lookup, whereas
free-form names destroy that ability entirely.

Enforced by machine, not by prose: the hook blocks `git checkout -b` /
`switch -c` / `branch <name>` / `worktree add -b` / `push src:refs/heads/<name>`
for any name not matching `^issue-\d+$`, **including when the command is written
as `git -C <path> …`**.

## Why a label is not enough, and what is

A GitHub label has **no compare-and-swap**: two sessions read "nobody has claimed
it" at the same moment, both add `status:executing`, and both believe they won.
Labels also **leak**: when a session dies, the label stays forever.

The only atomic primitive `gh` can reach is **creating a git ref**:

```sh
gh api repos/O/R/git/refs -f ref=refs/tempo/leases/issue-1234 -f sha=<sha>
# the second time: 422 Reference already exists
```

That is a server-side compare-and-swap at GitHub. `refs/tempo/leases/*` lives
outside `refs/heads`, so it never appears in the branch list and never pollutes the
repo.

Four independent layers; if one fails the others still block:

| Layer | What it blocks | Mechanism |
|---|---|---|
| 1. Local lock | Two sessions **on the same machine** | `mkdir` (atomic) in `~/.tal/<repo>/locks/` |
| 2. CAS on a ref | Two sessions **on different machines** | Create the ref → 422 if it already has an owner |
| 3. Git | Two worktrees on one branch | `git worktree add` refuses a branch that is already checked out |
| 4. The hook | Writing into a worktree that is not yours, **and a worktree whose lease has been released** | `PreToolUse` resolves the actual local write destination before comparing `session_id` against that worktree's lease card; a card marked `released` blocks everyone (#1342, #2360) |
| 5. Region lease (#2270) | Two coordinators on **different issues** touching the **same files** | `tal claim <n> --region <path>` (repeatable) records path prefixes in the lease; a later claim whose regions overlap a live lease's regions — component-wise prefix, both directions (`backend/app` blocks `backend/app/Services` but not `backend/app2`) — is refused with the holder named. Advisory-at-claim: `--force` overrides with a warning, no region declared = nothing held, expired lease releases its regions with it. `tal status` shows a regions column. |

## The standards this follows

| Problem | The standard applied |
|---|---|
| Mutual exclusion | A **lease** (Gray & Cheriton) on a real CAS primitive |
| The lease holder dies | An SQS-style **visibility timeout**: a 45-minute TTL plus a heartbeat; reclaimed when it expires |
| Which clock is authoritative | The ledger comment's `updated_at` — the **server clock**, immune to clock skew on the holder's machine |
| The holder hangs and then wakes up | A **fencing token** (Kleppmann): a monotonically increasing `epoch`; `tal assert` before every write; every push uses `--force-with-lease` |
| An issue that never gets finished | **Dead-letter** after 3 rounds → hand it to a human |
| Review content | [Conventional Comments](https://conventionalcomments.org) plus Google eng-practices severities (only `(blocking)` blocks a merge) |
| Commit and PR titles | Conventional Commits |
| Who may merge | **Role separation**: the coding bot ≠ the reviewing bot. The bot MAY merge, but only when the review passes **and** the whole batch's full suite is green — both enforced by machine in `tal merge`/`merge-batch` |

### About fencing — the point where safety is easily assumed

A TTL alone does **not** prevent this: a session hangs for 50 minutes (the machine
sleeps, a stop-the-world GC, a network stall), its lease expires, another session
takes the issue, and then the old session wakes up and `git push`es as though
nothing happened. Kleppmann calls this the hole in every TTL-only design.

So every re-issued lease increments `epoch`, the `.tal-lease.json` card in the
worktree holds the current owner's epoch, and `tal assert` refuses when the epochs
differ. The path to the remote is blocked twice over: `tal assert` (the hook calls
it before every `git push` / `gh pr create`) and `--force-with-lease` at the git
layer.

**Releasing a lease MARKS the card; it does not delete it (#1342).** `tal pr` and
`tal release` keep the worktree for the next fix round, but the hook takes its
evidence from that very card — deleting it leaves the worktree **unguarded**, and
another session's WIP drifts in (this happened in
`.claude/worktrees/issue-1306`: five WIP files that did not belong to the session
that created it). So the card is written with `released: true` plus `released_at`,
and **`epoch` is dropped** — keeping the fencing token of an expired lease only
creates the illusion of still holding write rights. The consequences:

- the hook **blocks everyone**, including the previous owner, with a message
  pointing at exactly `tal claim <N>`;
- `tal assert` reports `LEASE RELEASED` instead of continuing;
- when scanning its own session's worktrees, `lease_file()` **skips** released
  cards, so "this session is holding several worktrees" never recurs.

### Losing the CARD is not losing the LEASE — `tal adopt` (#2238)

The card is a **cache**. Ownership lives in two authoritative places, both on the
server: the CAS ref `refs/tempo/leases/issue-<n>` and the ledger comment
(`issue`, `session`, `epoch`, `heartbeat`). But until #2238 the card was the only
way `renew | assert | pr | release` could answer "which issue am I holding?" —
`lease_file()` looked on disk and nowhere else. Lose the file while the lease is
very much alive and all four commands fell into `Fail(…, 3)`, leaving only two
official exits, **both of which destroy something**:

| The way out | What it costs |
|---|---|
| `tal claim <n>` again | **bumps `epoch`** — precisely what the fencing token exists to prevent; anything the older process still has in flight is invalidated |
| `tal unlock <key> --force` | **throws away a live lease**, opening the issue to another session |

This is not hypothetical: during #2197 a `git worktree prune` dropped the
worktree registration mid-run, the card went with it, and the card had to be
retyped **by hand** from the ledger read off GitHub.

```sh
tal adopt [<issue>]     # rebuild .tal-lease.json from the ledger, SAME epoch
```

It rebuilds nothing else and grants nothing. Two conditions keep it from being a
back door — it only **restores** what the ref and the ledger already recognise as
yours:

1. the ledger's `lease.session` must equal this session's id;
2. the CAS ref `refs/tempo/leases/issue-<n>` must still exist.

Fail either one and it refuses, loudly, with exit **5** for the wrong-owner case
— someone else's live lease is a job for a human, not for a tool. A ledger that
says the lease is yours while the ref is gone means the lease was already
reclaimed; `adopt` refuses there too and sends you to `claim`, where the epoch
*should* increase.

Two design points worth keeping:

- **`adopt` never writes to the ledger.** `ledger_write()` always refreshes
  `heartbeat`, and TTL is measured from that comment's `updated_at` — so even a
  harmless history line would silently *extend* the lease. Recovery must not hand
  out time nobody asked for. (An expired lease is still restored, with a warning:
  the card being gone is exactly why you could not `tal renew` in time. `assert`
  will still refuse until you renew, and if `gc` already reissued the lease the
  epoch mismatch fences you out — as it should.)
- **The issue number is inferred, never invented.** Explicit argument first, then
  the `issue-<n>` worktree directory you are standing in, and finally — the case
  that actually happened, where the directory is gone — a scan of the live lease
  refs for one whose ledger names this session. All three are only *hints* about
  which ledger to read; the two conditions above still decide.

`lease_file()` deliberately did **not** get a ledger fallback instead. It is on
the `hook-guard` path, which runs on *every* `PreToolUse`: a GitHub round-trip
there would add latency to every tool call and fail open on a network blip, and a
guard that silently writes lease cards into arbitrary directories is worse than
the bug. Recovery should be a thing you ask for.

For Bash, the hook protects **destinations, not command names or CWD**. Read-only
Git commands and writes to scratch space do not touch an issue worktree, while a
literal Python/Node/Ruby write into a locked worktree does. Unknown dynamic shell
syntax deliberately remains fail-open; `tal assert`, the pre-push gate, and
`--force-with-lease` remain the later fencing layers.

## The state machine

```
     a human adds agent:ready
              │
              ▼
        (the queue)  ◄────────────────────────────┐
              │ tal claim  → lease + worktree     │ lease expires → tal gc reclaims it
              ▼                                   │
      status:executing ─────────────────────────-─┘
              │ tal pr  → a PR into dev, then the DOCS GATE, then releases
              ▼
  status:reviewing + agent:awaiting-review
              │ tal review-claim (MUST be a different session)
              ▼
      ┌───────┴────────┐
      ▼                ▼
agent:changes-      agent:review-passed
 requested            │
 (PRIORITY #1)        ▼
      │        tal merge-batch: merges THE WHOLE BATCH onto dev in a temp tree
      │          → the full suite ONCE → green means merge the batch
      └──► back to claim    │
           (at most 3 rounds)▼
                     tal merge → status:shipped + CLOSE the issue
                     tal gc    → delete the branch and the worktree
```

**Merging into `dev` CLOSES the issue (#1462).** The `Closes #N` that `tal pr`
inserts into the PR body **never fires** in this workflow: GitHub only auto-closes
when a PR merges into the **default branch**, and this repo's default is `main`
while every PR here targets `dev`. Before #1462 `tal` had no closing command
either, so issues sat at `OPEN + status:shipped` indefinitely — 22 of them had to
be cleaned up by hand on 2026-08-01.

There is one exception, and it is real: `tal merge` adds `status:shipped` to
**every** merged PR, including a PR that only finished one phase of an epic. So
`closable()` **refuses to close** an issue that still carries any other `status:*`
label — `#962` (Modular Monolith) also carries `status:planning`, and `#1392` also
carries `status:blocked`. Closing those would hide unfinished work.

**A second exception, learned the hard way (#1571): an issue that was CLOSED and
then deliberately REOPENED.** A `status:*` label does not catch this one — a
reopened issue typically carries `agent:ready`, not a `status:`. The pattern is
routine and it bit four times in one session: a PR finishes *part* of an issue,
`Closes #N` closes it, someone reopens it with a comment listing what remains —
and `gc` closes it again within five minutes. The work disappears from
`tal queue` silently.

So `closable()` also asks `reopened_after()`: if the issue's timeline has a
`reopened` event newer than the PR's `mergedAt`, it refuses. If the timeline
cannot be read, it refuses too — closing wrongly loses work, skipping wrongly
costs one stale label.

### Cleanup after a merge is gated on a MEASUREMENT, not on an exit code (#2988)

`tal merge` does three irreversible things right after `gh pr merge` returns:
remove the worktree, **delete the remote branch**, and write `shipped` to the
ledger. Deleting the branch is the expensive one — `gh pr reopen` needs a live
head branch, so once it is gone the PR cannot be brought back and the work
survives only in the local worktree, which `tal gc` is allowed to sweep.

Exit `0` does not mean the commit reached the base. `Base branch was modified`
is the shape that actually occurs: GitHub accepts the call, the base moved
underneath, nothing lands. On 2026-08-16 issue #2974 lost both its PRs and its
branch while `origin/dev` still carried the unfixed file — measured by content,
not by the PR's state:

```sh
git show origin/dev:web/pos/src/i18n/en.json | grep -c '{{sku}}'   # 1 = not fixed
git branch -r --contains <sha>                                     # only the topic branch
```

So `assert_merge_landed(pr, head_sha)` runs between the merge and the cleanup:
it fetches the base and requires `git merge-base --is-ancestor <head> origin/<base>`.
Not an ancestor ⇒ `Fail`, and worktree, branch and ledger are all left alone.

Three details are load-bearing:

| Detail | Why |
|---|---|
| The head SHA is read **before** the merge | Afterwards the branch may already be gone, and there is nothing left to ask |
| The SHA is read with `gh_json_strict`, and a failure aborts **before** `gh pr merge` runs | `gh_json`'s own docstring forbids this: *"a path that uses the result to DECIDE A DESTRUCTIVE ACTION must go through `gh_json_strict`/`gh_json_required`"*. With `gh_json`, a 502 returns `""`, the ancestry check then has nothing to compare and waves it through — the API failing silently becomes "measured, nothing there". Failing before the merge is also cleaner than failing after: nothing was merged, so there is nothing to undo. Same direction as `promotion_only_files` (#2909), which refuses when it cannot prove |
| The helper still passes on an empty SHA | Defence-in-depth for other callers, not the merge path — `cmd_merge` has already refused. A measuring function that explodes on missing input gets wrapped in `try/except` by the next caller, and a guard inside a `try/except` is a guard that is off |
| The check is tied to `--merge` | A merge commit keeps the head as an ancestor. Under `--squash` the content lands as a *different* SHA, so the guard would reject every legitimate merge. `test_merge_uses_merge_commit_so_ancestry_holds` fails if the merge method changes without revisiting the guard |

**The call site is pinned too, not just the helper.** Extracting the check made
it testable, but it also made the wiring a place nobody watched: deleting
`assert_merge_landed(...)` from `cmd_merge` left all the helper tests green.
`test_cmd_merge_does_not_delete_the_branch_when_nothing_landed` drives the real
command and asserts the three irreversible effects did **not** happen.

Same lesson as `docs/guide/cong-xanh-do-vi-khong-chay.md`, one layer up: a
status that says "done" is not evidence that anything landed — measure the
content.

### `gc --include-abandoned` asks the same question, one layer up (#2993)

`gc` treats "PR closed and not merged" as "abandoned work" and, with
`--include-abandoned`, deletes both the remote branch **and** the worktree. That
premise was measured false three times in two hours on 2026-08-16: #2977, #2980
and #2989 were all `mergedAt=null` while carrying finished, tested work that had
not reached `dev`. #2974 survived only because its local worktree was still
there — the very thing this path is allowed to remove.

So the abandoned branch now measures first, reusing `worktree_unmerged_content()`
— chosen over a fresh measurement because that helper already went through two
corrections (#2300 A12 → #2674) and understands squash merges. Two guards
answering the same question with two different measurements drift.

**Ask about the thing that is about to be lost.** The first version of this fix
measured the local *worktree*, while the command deletes a *remote ref*. They are
different objects — a clean worktree next to a ref that still carries commits is
routine (a reset, or a push followed by a cleanup) — and on a machine with no
worktree the guard measured nothing at all and deleted. That is not a rare case:
`gc` is itself what removes worktrees, so every run after the first one lands
there. The helper now takes a `rev`, and this path asks about `origin/<branch>`
after fetching it; the worktree is only the fallback when the remote ref is
already gone.

**And the tests drive the command, not the source text.** The first attempt
asserted that `worktree_unmerged_content(` appeared in `cmd_gc`. Disabling the
guard with `if False and …` left that string in place and all 138 tests green —
the guard was completely off and nothing noticed. The tests now run `cmd_gc` and
assert `DELETE refs/heads/` was never issued, in both shapes: with a worktree and
without one.

Still carrying content ⇒ refuse, and say what would be lost. `--include-abandoned`
keeps its meaning ("I know this work is abandoned") for a branch that holds
nothing the base lacks; deleting a branch that *does* hold something must never
be a side effect of a cleanup pass.

The matching half of that fix: `gc` now asks `closable()` **before** touching any
label. It used to label unconditionally and only consult `closable()` for the
close, so it could print *"KHÔNG đóng #962: còn mang status:planning"* immediately
after adding `status:shipped` to that very issue — leaving it carrying both, and
`tal queue` filters on labels, so it dropped out of the queue.

The `agent:*` labels are their own namespace — **`status:approved` is deliberately
not reused**, because in this repo `status:approved` means "the plan is approved to
be built", not "the PR passed review".

**Counting review rounds: TWO counters, do not merge them (#1342).** Each
verdict's marker records `round=<which revision was reviewed>` and
`changes=<how many times it was sent back>`, plus the `sha=` of the exact revision
that was read. Previously both used one variable that only incremented on
`changes`, so three verdicts on three **different** revisions of PR #1317 all
recorded `round=1` — nobody reading the markers could tell that a later revision
had passed, and the repo owner was given a wrong report because of it. `changes`
remains the dead-letter threshold (3 rounds); `round` is purely for reading.

`sha` is also the guard against repeat reviews: `tal review-queue` **skips** a PR
whose current `head_sha` already has a verdict — if nothing is new, no review round
is spent.

## `tal pr` runs the docs gate BEFORE it releases the lease (#1639)

Step 6 of the `issue-work` skill prescribes this order:

```sh
tal pr --title … --body-file …
tal docs-check <PR>          # docs are part of "done"
```

That order was unrunnable. `tal pr` released the lease the moment the PR opened,
so by the time `docs-check` reported a gap the worktree was already write-blocked
by the hook:

```
[tal] Worktree này (issue #1637) đã NHẢ LEASE lúc … — Ghi vào đây bây giờ là ghi ra ngoài mọi rào.
```

Re-claiming was not possible either — the issue had lost `agent:ready`, so the
only way to fix docs in the same turn was `tal claim --force`: stepping around a
gate that was actively blocking. That is forbidden. The real-world outcome was
that **every** docs-check finding had to travel through a full review round, even
when it was one paragraph added to CLAUDE.md (#1637 / PR #1638).

Now `tal pr` opens/updates the PR, then evaluates the gate, and only then
releases. A gap holds the lease, so the fix happens in the same turn:

```
PR #1638 ĐÃ MỞ nhưng lease VẪN GIỮ — 1 khoảng trống tài liệu:
  ✘ docs/guide/tax-types.md
      vì: quy ước thuế đổi mà không ai ghi lại
      kích hoạt bởi: backend/app/Services/Customer/TaxResolver.php

Sửa tài liệu rồi commit và chạy lại `tal pr` (PR cập nhật tại chỗ).
Nếu thay đổi này thật sự không cần doc: `tal pr --docs-ok` …
```

**Only `docsRules` hold the lease — not the two generic checks.** "Changed N code
files and touched no .md" fires on nearly every PR; making that a gate teaches
people to route around gates. The ten rules in `.claude/agent-loop.json` are
targeted (tax, cashier shift, offline evidence, printing…), and a gap in one of
them is a convention that changed with nothing recording it.

**`--docs-ok` is an assertion, not a mute.** It releases the lease *and* posts a
comment on the PR naming every rule it skipped, so the reviewer sees the claim and
can reject it. A skip flag that stays silent is just the gate switched off.

`cmd_docs_check` and the gate share one function (`docs_gate`). Two independent
counters would drift, and then "docs-check is green" says nothing — green under
which measurement?

**Large PRs are paginated and the gate fails closed (#2379).** Both callers get
the file list from `GET /pulls/{n}/files` with `--paginate`; `tal` then compares
the number of filenames received with the PR's independent `changed_files`
count. An API error, a missing page, or GitHub's file-list ceiling therefore
cannot become an empty result that silently disables every `docsRules` check.
`tal docs-check` exits non-zero. `tal pr` keeps the lease and asks the coding
session to retry; the only escape is the existing explicit `--docs-ok`, which
posts a `docs-unchecked` comment on the PR saying that the gate did not run.

## Running it

```sh
.claude/tools/agent-loop/tal doctor --fix     # once: create the labels, enable delete_branch_on_merge
```

A coding session (open as many as you like; they avoid each other):

```
/loop /issue-work
```

A review session (a different session):

```
/loop /issue-review
```

Open the gate for the bot by adding `agent:ready` to the issues you want it to
work. Without that label the bot skips the issue — so that no session cheerfully
starts on #962 (the move to a Modular Monolith).

## Tests: narrow while coding, complete at the gate

- The CODE role runs only the **relevant** tests (`--filter=`, or by directory or
  package). A full suite here is pointless: the coding loop repeats many times, and
  each full suite eats the whole lease.
- The full suite runs **once for THE WHOLE BATCH**, at `tal merge-batch`: it merges
  every review-passed PR onto `dev` in a temporary tree (detached, creating no
  branch), runs `fullSuite` exactly once, and merges the batch if it is green. It
  does not run a full suite per PR — that is both slow and expensive, and it
  answers the wrong question: what matters is whether **the batch together** is
  green, not whether each PR is green on its own.
- The REVIEW role runs only **narrow tests**, enough to check what it suspects.

### Undoing a mutation: `cp`, never `git checkout --` (#2700)

The reverse-direction ritual — remove the fix, watch the test go RED, put it back
— is mandatory in both roles. The obvious way to put it back is the wrong one.

`git checkout -- <path>` restores from the **index**. An issue worktree carries
uncommitted work from the moment the work starts until `tal pr`, so that command
discards *everything* uncommitted in the file, not just the line you injected.

It cost this project twice in one session. The cheap one lost a fix its author
could retype. The expensive one lost **a subagent's uncommitted implementation**
— a struct field, a loop split and a call site in `sync_service.go` — leaving the
build with nine `e.alertPush undefined` errors and no copy on disk; the subagent
had to rebuild it from its own transcript. Nobody had that code in their head.

```sh
cp <file> /tmp/x.good          # BEFORE injecting
…inject, run the test, see RED…
cp /tmp/x.good <file>          # restore
diff -q <file> /tmp/x.good     # verify
```

Measured, not reasoned: with one uncommitted line present, `git checkout --`
removes it; the `cp` round-trip keeps it and still clears the injected line.

The coordinator has a second defence, and it is an ordering rule rather than
advice: **commit first, spot-check second.** A subagent hands back a tree full of
uncommitted work, so committing before verification turns the worst outcome from
"the code evaporated" into "a commit needs amending".

### Adding a case to `tal_test.py` — two traps, both already paid for

The loop's own suite is self-discovering: `discover_tests()` reads `globals()` for
every module-level `test_*`, so a new case runs without being registered anywhere
(#2202 — the old hand-written tuple silently skipped three brand-new cases while
printing "all pass"). Two consequences bite anyone editing that file with a script:

- **Insert before the LAST `if __name__ == "__main__":`, not the first.** That
  string also appears *inside* the #2202 case, which builds a child copy of the
  file. A `replace()` on the first occurrence lands in the middle of a string
  literal and the file stops parsing.
- **Read `Path(__file__)`, never `HERE / "tal_test.py"`.** The #2202 case runs a
  copy of the suite from a temp directory under a different name; there, `HERE`
  points at the temp dir and that filename does not exist. A source-reading case
  written against `HERE` kills the CHILD suite with `FileNotFoundError` before its
  canary runs — so #2202 fails for the wrong reason and the new case measures
  nothing where it matters.

**A duplicated case name is invisible to `MIN_TESTS`** (#2682). `def` shadows `def`,
so 18 `test_2300_*` functions sat in the file twice and the first 501-line copy
never ran; the unique-name count `MIN_TESTS` guards stayed at 103 either way.
`test_no_test_name_is_defined_twice` closes that by reading the SOURCE — `globals()`
is precisely where the duplicate disappears.

### The temp tree must be SET UP before the gate runs (#1329)

The temp tree is a brand-new worktree, so **everything in `.gitignore` is
missing**: `backend/vendor`, `node_modules`, `.env`.
The first version of merge-batch ran `fullSuite` directly in that tree ⇒
`Could not open input file: vendor/bin/pest` ⇒ the gate was red on **100% of runs,
regardless of PR content**, and no PR could pass from the commit that enabled
bot-merge onwards.

So `.claude/agent-loop.json` splits into three keys, and that split is the core of
the fix:

| Key | When it runs | What a failure reports |
|---|---|---|
| `setup` | After merging the batch, before the gate | **THE GATE IS BROKEN** (exit 3) — no test has run yet |
| `setupVerify` | Immediately after `setup` | **THE GATE IS BROKEN** (exit 3) — usually `fullSuite` gained a command that `setup` has not followed |
| `fullSuite` | Once for the whole batch | **The full suite is RED** (exit 2) — the tests really failed |

Folding the install steps into the `fullSuite` chain makes both failure classes
print identically — which is exactly why #1329 survived for days: whoever read the
log saw "full suite RED" and went looking for a broken test.

Installing **after the merge** is deliberate — the batch may bump
`composer.lock`, so the gate must install exactly what the batch declares, which
means **not** reusing the main tree's `vendor/`. It does not hurt:
`composer install --prefer-dist` with a warm cache takes about 8 seconds, against
the tens of minutes the suite takes.

Adding a web app to `fullSuite` means adding the matching install step to `setup`
(`pnpm install` **inside that app** — each app carries its own lockfile, and
`pnpm install` at the root does not install them).

To exercise the gate itself without merging anything:
`tal merge-batch --gate-only`.

### Compiling Go: two gates, and why both exist (#2339)

`gate_go_modules()` runs on the **merged tree** before a batch lands, and
`.github/workflows/workstation-go.yml` runs on every PR that touches
`workstation/**`. They overlap on purpose: the workflow judges the PR's own head,
the local gate judges the tree **after** the merge — and the difference between
those two is exactly what ws#239 cost us (clean merge, green CI, still broken,
because the conflict was in a function signature rather than in the text).

**Both were dead for a day.** After the monorepo merge (#2306),
`changed_go_submodules()` asked for *changed paths that are a directory containing
`go.mod`* — the shape of a gitlink. An in-tree edit to `workstation/` produces
**file** paths (`workstation/internal/service/sync_pull.go`), never the directory
itself, so the list was always empty and the gate returned immediately. The
workflow, meanwhile, sat in `.github/workflows-parked/`, which GitHub does not
read. Nothing was decided; the check simply stopped matching reality, and no test
noticed because its fixture fed the old gitlink shape too.

Since #2339 `changed_go_modules()` walks each changed file up to the nearest
ancestor holding `go.mod` (innermost module wins). The first run of the restored
gate found `internal/service/discount_weights_golden_test.go` unformatted on
`dev` — drift from exactly the window nobody was watching.

`fullSuite` still carries no Go command, and that is deliberate: the local gate
already runs on every merge, so a third place saying the same thing is a third
place to drift.

Why this matters more than a missing lint: `workstation/` holds the Go half of
every money/tax/printing parity pair, and the backend's parity tests read the
**JSON golden files** — they never build Go. Without these two gates, Go that does
not compile reaches `dev` and the Cloud side cannot tell.

**A conflicting PR is dropped from the batch, not allowed to kill it.** A conflict
is one branch's own business (usually it is behind base); letting it block five
other PRs is the wrong shape. `merge_blockers` no
longer **trusts** GitHub's `mergeable` field — it is computed **asynchronously** and
is therefore wrong in **both directions**: at query time it is usually still
`UNKNOWN` (letting a genuinely conflicting PR into the batch), and a stale
`CONFLICTING` flag **wrongly blocks** a PR that is already clean (measured on
#1330: the flag said CONFLICTING while `git merge --no-commit` into `origin/dev`
merged cleanly). Since #1342 it is only a **warning**; the real merge inside
`merge-batch` is the answer, and it drops only that one PR, with the git output
attached.

## Submodules: the machinery is all still here, and none of it does anything

This repo is a monorepo (#2306): there is no `.gitmodules` and `git submodule
status` prints nothing. The submodule machinery was **not** removed along with the
submodules. Still present and still shipping:

| Where | What it still contains |
|---|---|
| `.claude/tools/agent-loop/tal` | 120 lines containing the word `submodule` — `tal submodule`, `submodule-pr`, `submodule-check`, the pointer gate inside `tal pr`, `realign_pointers`, `stub_missing_embed_dirs` |
| `.githooks/pre-commit` | the dangling-gitlink check (#1147) |
| `.githooks/pre-push` | the "every submodule level with its remote" check |

Every one of them keys on something that no longer occurs — a gitlink in the diff,
or an entry in `.gitmodules` — so every one is a no-op. `tal pr` never blocks on a
pointer; `merge-batch` never runs `git submodule update --init` (it is guarded by
`if (tmp / ".gitmodules").exists()`); `realign_pointers` never has a pointer to
move; `pre-push` reads an empty submodule list and exits 0 before printing
anything.

**Do not go looking for a submodule to apply any of this to.** Every app is in the
tree (`web/*`, `app/*`, `workstation/`): you edit it and commit it in the same
commit as the backend change that belongs with it.

One habit from that era stays, because its reason is still live: `tal` scrubs
`GIT_DIR` / `GIT_WORK_TREE` / `GIT_INDEX_FILE` out of **every** git command it
runs. Git exports those to hooks and `GIT_DIR` beats even `-C`, so a `tal` invoked
from inside a hook would otherwise read a different repo than the one named on its
own command line.

## `tal pr-merge <PR>` — the whole chain in one command (#1524)

Merging spans seven steps. Done by hand it can be correct, but it has to be
correct **every time** — measured over one session it was more than ten runs, with
mistakes in deriving the issue number and in waiting for CI.

| Stage | What it does |
|---|---|
| ① | derive the issue from the branch, check `agent:review-passed` — **stop** if absent |
| ②–⑤ | child PRs in submodules: find them, wait for their CI, merge them, realign pointers. **Never fires here** — there are no submodules |
| ⑥ | wait for the umbrella's CI |
| ⑦ | `tal merge` — separation of duty, closes the issue |

**It never sets `agent:review-passed` itself.** That label is the evidence that
a second pair of eyes read the change; a command that stamps it turns the label
into paperwork and kills the separation-of-duty gate with it. `--no-wait` skips
the waiting stages, `--timeout` changes the deadline.

## Garbage collection

`tal gc` runs at the start of every turn in both roles:

- it reclaims leases that have been silent past their TTL (dead-lettering after
  three recurrences);
- it deletes the **remote branch of every merged PR**, whether the PR came from a
  bot or a human;
- it deletes the worktree and local branch once the PR is merged, adds
  `status:shipped` and **closes the issue** (the default since #1462; `--no-close`
  only labels). It does not close an issue that still carries another `status:*`
  label — see `closable()`;
- it does **not** delete a worktree with uncommitted changes — it reports and skips
  it;
- a PR that was closed without merging is only **reported**; deleting would lose
  code, so it needs `--include-abandoned`.

### It asks about CONTENT, not about SHA ancestry (#2674)

A clean tree is not enough to release a worktree: a commit that was committed but
never pushed leaves `git status --porcelain` empty, and the `branch -D` inside
`remove_worktree` is the last copy of it (#2300 A12). The guard was right; its
measurement was not. It asked `git log origin/<base>..HEAD`, i.e. **ancestry by
SHA** — and this repo **squash-merges**, so the branch commit is rewritten into a
new commit with a different SHA and can never be an ancestor of the base. The
loop containing the guard only walks **merged** PRs, so it hit 100% of the
worktrees it saw: it never released a single one. Six accumulated that way, each
carrying a full `setup` (vendor / node_modules / .env).

`cmd_gc` **fetches `origin/<base>` first** (#2782). Without that, the local
`refs/remotes/origin/dev` is as stale as the last fetch — a PR that just merged
on the remote looks like unpushed work, and the skip message reads as if commits
were lost.

`worktree_unmerged_content()` asks about content instead, in two tiers:

1. `git cherry origin/<base> HEAD` — a `-` line means that patch is already
   upstream, whatever its SHA. This covers the one-commit squash;
2. any `+` left ⇒ ask **whether HEAD carries content origin does not**
   (`git diff --numstat origin/<base> HEAD`). A symmetric `git diff --quiet A B`
   keeps the worktree forever when base moved on: the worktree is *behind*,
   origin is newer, the diff still fires, and nothing unique would be lost
   (#2782 / #2689). `added > 0` ⇒ keep. But `added == 0` alone is **not**
   release (#2782 round 2): a delete-only commit — `git rm`, dead-line removal,
   exactly what #2188 campaigns produce — also scores `added == 0`, and the
   two-point diff cannot tell "HEAD deleted lines base still has" (work that
   `branch -D` would destroy) from "base moved on, worktree is merely behind"
   (#2689 — must release). The tie-break asks the **merge-base**:
   `git diff --numstat <merge-base> HEAD -- <file>` measures what HEAD itself
   did since branching. HEAD deleted nothing ⇒ the two-point deletions are just
   base being newer ⇒ release; HEAD did delete ⇒ keep (fail-closed — a deletion
   that was squash-merged *and* whose file base then edited again stays kept
   too; keeping costs disk, releasing costs the last copy of a code-removing
   commit). Binary / unreadable ⇒ keep (fail-closed). This still covers the
   several-commits-squashed-into-one case, where no patch-id matches but the
   base already holds the result.

`remove_worktree` looks up paths via `git worktree list`, not only
`.claude/worktrees/issue-N` (#2710). A worktree sitting at
`~/Herd/dxs-product-issue-2696` used to be reported as deleted while
`git worktree list` still showed it. Failed `git branch -D` / leftover paths
print a warning and return false — gc must not claim success for work it did
not do.

Asking `git worktree list` has a sharp edge (#2782 round 2): when branch
`issue-N` is checked out in the **main worktree** — the PreToolUse hook forces
every branch to be named that way, and working directly in the umbrella happens
— the main tree itself appears in the list. `git worktree remove --force`
refuses it ("is a main working tree"), but the orphan-fallback (#2177,
rename + rmtree) does not ask again: unguarded, it renamed the entire repo to
`<name>.orphan-<ts>` and deleted it — every local branch and the worktree
registry with it. `remove_worktree` therefore refuses the main worktree
outright: warning to stderr, return false, before any action.

Both tiers are load-bearing and each has its own case in `tal_test.py`: tier 1 is
the only thing that saves a worktree whose base **moved on afterwards** *and*
whose patch-id still matches, tier 2 the only thing that saves a multi-commit
PR (including that PR *then* the base moving on). Every failure path — cherry
fails, `git show` fails, `origin/<base>` does not resolve — errs toward
**keeping**: keeping wrongly costs disk, deleting wrongly costs code.

`reap_leases` on an **issue-N** key looks at whether a PR is already open for that branch before it resets (#2788). An open PR → `state=review`, stamp `agent:awaiting-review`, write `pr` into the ledger. No PR → re-attach `agent:ready` (the old `set_state_labels(…, set())` stripped working labels and left the issue in **neither** queue). If the PR list cannot be measured, it does not stamp `queued`. `tal gc` and `tal status` also scan for already-stranded pairs (open `issue-N` PR, issue missing review/ready labels) and restamp them — that is how #2785/#2780 get picked up without rewriting their ledger history by hand.

`delete_branch_on_merge` is enabled on the repo, so branches from PRs merged
through the UI disappear on their own; `tal gc` is the net that catches the rest.

## Pushing to `dev` — the obstacle you will meet

The loop never pushes to `dev` itself (the bot only opens PRs), but when **you**
merge or push `dev` you will meet this one. Recorded here because diagnosing it the
first time cost real time.

**`dev` moves ahead of you.** With many sessions working, `origin/dev` advances
constantly (two other PRs merged while this tooling was being committed). `git
push` is rejected as non-fast-forward → `git fetch && git rebase origin/dev`; do
not `--force`.

## An empty queue is a MEASUREMENT, not a default (#2151)

`tal queue`, `tal review-queue` and `tal merge-queue` can now **exit non-zero** with
`KHÔNG ĐO ĐƯỢC` ("could not measure") instead of reporting an empty backlog. Treat
that exit code as "ask again later", never as "no work".

Why it exists: `gh_json()` returns its `default` for **any** non-zero exit from `gh`
— rate limit, network, expired token, `gh` missing — not just the 404/422 its
docstring mentions. The three work-discovery queries used `default=[]`, so a failed
call and a genuinely empty backlog were the same value, printed with the same
reassuring sentence and exit 0.

That lie points the wrong way. This guide's sibling skill tells a session that an
empty queue means "say so and end the turn" — so a session following the rules
correctly would report "no work left" while the backlog was full. A background
`/loop` would idle silently through every rate-limit window.

Measured on 2026-08-07: with the GraphQL quota exhausted, `tal queue` printed
`hàng đợi rỗng` and exited 0 while `#2130`, `#2133` and `#2127` were all open with
`agent:ready` (re-measured over REST, which still had quota).

Only those three call sites changed, via a new `gh_json_required()`. The other 33
`gh_json()` callers are untouched on purpose — several rely on the swallow (asking
"does this PR exist yet?" is a legitimate 404), so widening the contract is a
separate change that has to read each one.

**When you hit it:** `gh api rate_limit --jq .resources` and wait for the reset.
REST and GraphQL have separate budgets, so `gh api repos/...` often still works when
`gh pr list` does not.

## An issue whose fix is already in an open PR leaves the queue (#2769)

`tal claim` takes a lease on **one** issue, but a **cluster PR** (#2178 — the
sibling skill actively encourages grouping same-file issues) closes several. The
N−1 others keep `agent:ready` and reappear as `eligible` the moment the PR opens.
Nothing is wrong with any label; the label for those issues is simply **never
written**, so waiting for it is waiting for something that does not come.

Measured 2026-08-13: PR #2767 closed #2745 + #2754 + #2739; only #2745 moved to
`agent:awaiting-review`, and the next `tal queue` offered the other two. The
session that filed this had just written that PR and remembered — a different
session would have rebuilt both, and neither of the two standard checks would
have stopped it:

- `git log --grep="#2754"` finds nothing: the commit is on an unmerged branch;
- `gh pr list --search "issue-2754"` finds nothing: a cluster PR carries the
  **head** issue's branch name (`issue-2745`).

So `cmd_queue` reads the PR **bodies** instead. `tal pr` already writes
`Closes #N` for the whole group, so the ground truth existed — nobody was reading
it. `issues_claimed_by_open_prs()` maps issue → PR, and the skip reason names the
PR number so the reader can go look rather than just being told "someone did it".

Three decisions worth keeping:

- **`Closes`/`Fixes`/`Resolves` only — never `Refs`.** `with_issue_ref()` accepts
  `Refs #N` on purpose, for a PR that does part of the work (#1338/#1312). Part
  of the work means the issue still has work, so it stays in the queue. Pulling
  it out on `Refs` would bury live work.
- **`--state open` only.** A PR closed without merging drops out of the map and
  its issue returns to the queue by itself. Widening to `--state all` would bury
  that issue permanently and silently — strictly worse than the duplicate work
  this change prevents.
- **`gh_json_required`, not `gh_json`.** A swallowed failure yields an empty map,
  which restores the exact bug being fixed, with no error. Same shape as
  #2151/#2152 above.

**`changes-requested` is exempt, and leaving that out digs a deeper hole than the
one being filled.** A rework round *always* has an open PR — `tal pr` writes
`Closes #N`, and rework pushes to the same branch so the PR never closes. Without
the exemption, an issue review just sent back is skipped with the words *"the fix
already exists, don't redo it"*, at the moment review said the fix is **not** good
enough. Worse, it is self-sustaining: nobody picks the rework up → the PR stays
open → the issue stays buried. Same family as the historical bug recorded in
`gate_open()`'s docstring, and `changes-requested` is the queue's **highest**
priority (`rank`), so burying it inverts exactly the ordering the queue exists to
keep. Caught in review of #2769 with a live example on the repo at the time
(#2745 back for rework with PR #2767 open).

The exemption removes **one** skip reason and nothing else — a live lease still
wins, so two sessions can never be invited onto the same rework.

Coverage note: the query reads the first `--limit 100` open PRs. Past that it
fails **open** — an unrecognised issue stays *in* the queue, so the worst case is
duplicated work, never buried work. Paginate if the repo ever gets there; don't
just raise the number.

Labels stay as they are. Reconciling against measured state each run beats a
one-shot label write nobody revisits — the failure mode this repo keeps hitting
(#2673, and #2763 sat CLOSED carrying `status:reviewing` + `agent:awaiting-review`
on the same day).

## An empty review queue names WHO is holding what (#2172)

`eligible: []` used to mean two entirely different things — "no PR waits for
review" and "PRs wait, but other sessions hold their leases" — and the sibling
skill's rule ("empty queue → say so, end the turn") made a rule-following session
report *no work left* while three PRs were being reviewed at that very moment.

`tal review-queue` now returns `claimed: [{pr, issue, by, host, mine, expires_in,
orphan}]` next to `eligible`, and the text output distinguishes three states:

- **`hàng đợi rỗng THẬT`** — measured, nothing waits. Ending the turn is correct.
- **`đang bị session khác giữ`** — work exists, just not yours; each line names the
  holder and how long its lease has left.
- **`orphan: true` / `by: "?"`** — the ref carries no owner payload. Suspected
  #1616 orphan; `tal gc` adjudicates after its grace period. **Never** delete the
  ref by hand — deleting a live one puts two sessions on the same PR.

### The third bucket: a PR that never entered the loop (#2673)

Two buckets still were not enough. `queue` filters on `agent:ready`;
`review-queue` filters on `agent:awaiting-review` / `status:reviewing`. Both
filter a **label**, so a PR carrying neither falls out of both — and a PR opened
by hand, or by a session that died between `tal claim` and `tal pr`, carries
neither. Measured: **PR #2662 sat open, every check green, `CLEAN`, with no
ledger and no label from 17:23:03Z to 22:39:53Z (5h17)** while nine consecutive
calls printed `hàng đợi rỗng`. It left that state only because a session went
and claimed the issue — not because any queue surfaced it.

`review-queue` now returns a third list, `orphans: [{pr, issue, title, author,
updated}]`, and `hàng đợi rỗng THẬT` is printed only when **all three** are
empty. Pick one up with `tal review-claim <PR>` — it works straight from the PR
number and does not ask for the label. (`tal adopt` cannot: it only rebuilds the
card for a lease *you already hold*, and an orphan has no lease.)

**A human's PR is not an orphan (#2760).** The bucket tests for *absence of a
ledger* and concludes *abandoned agent work* — but people never have a ledger
either, because they never run `tal claim`. Measured here: of the last 12 PRs,
`ecsol` (the shared agent account) opened 8 and **two humans** opened the rest,
all using the same `issue-<N>` branch convention; PR #2759 on `issue-2757` sat in
`orphans` because of it. Since the bucket reads as *"work going spare, pick it
up"*, that invited a session to post an automated verdict on someone's PR — or
`tal claim` and open a competing branch over work they were mid-way through.

So the split is by **author**, read from `agentLogins` in `agent-loop.json`
(never hard-coded — another repo uses other names; empty ⇒ no filtering, the
pre-#2760 behaviour):

- **`orphans`** — agent-authored, no ledger. Genuine orphan, needs a human.
- **`humans`** — anyone else. Printed with *"do not claim, do not auto-verdict"*
  and present in the JSON, but it does **not** hold back `hàng đợi rỗng THẬT`:
  a person's PR is not the loop's work, so reporting the loop idle while one is
  open is correct.

The decision lives in `pr_is_agent_authored()` — a pure function, so the test can
prove both directions. A missing author (`None`) counts as **human**: guessing
"agent" is the harmful direction, because that is the one that files an unknown
PR under "come and take it".

**Both ends normalize the same way, and that took a second pass (#2762).** The
config is read through `normalize_agent_logins()` (`strip().lower()`, dropping
empty and `null` entries) because the comparison strips too — for one release it
did not, so a stray space in `agent-loop.json` (`[" ecsol "]`) sent **every**
agent PR to `humans`: nobody collects that bucket and nothing complains. Same
class as the role slugs of #2451/#2456, where a wrong string resolves to zero
recipients and stays quiet forever. The value that actually got parsed is printed
by `tal config`, so a typo is visible without reading `tal`'s source — a key that
is read but never shown is the #2348 failure all over again.

What deliberately does **not** land in this bucket:

- a PR missing the label but **carrying a ledger** — that is a revision round in
  flight, or a label removed by hand. Folding it in would make the bucket shout
  on nearly every PR, and a gate that cries wolf gets switched off, taking the
  part that was right with it;
- anything whose branch is not `issue-<number>` (dependabot, human branches);
- a PR whose `ledger_read` **threw**. "Could not measure" is not "has no ledger"
  (#2300) — one API hiccup would otherwise manufacture a false alarm.

The identity travels **inside the lease ref itself**: `review-claim` wraps the sha
in an annotated tag object whose message is a JSON payload (`session`, `host`,
`pid`, `expires_at`) and points `refs/tempo/leases/pr-<N>` at the tag. This is
deliberate — a review lease writes nothing into the ledger (#1406), so before this
the ref was a mute sha and `tal status` printed `session ?` for live and orphaned
leases alike, which is exactly the ambiguity that pushes people toward deleting
refs by hand. The payload is auxiliary: if creating the tag object fails, the ref
falls back to a bare sha (CAS on the ref is the mutual exclusion, never the
payload), and `status` then shows the `KHÔNG ĐỌC ĐƯỢC CHỦ` flag instead of a fake
owner. `tal status` reads the review-lease owner and expiry from that payload —
not from the issue's ledger, whose `lease` is the *coder's* — so `?` now only ever
means "genuinely unreadable".

## A verdict after the merge is refused (#2153)

`review-claim` and `merge-batch` do not exclude each other, and the race is real:
a review lease was claimed at 14:17:49Z, `merge-batch` merged the PR at 14:18:38Z,
and the verdict `changes` — correct, `(blocking)` — landed at 14:20:11Z, 93
seconds too late. It gated nothing: it stamped `agent:changes-requested` +
`status:blocked` onto an issue whose PR was already merged and closed (#2110 stuck
exactly like this — unfixable "per review" because the PR was gone), while the
real blocking finding sat on `dev` owned by nobody.

Three gates now close that window, from cheapest to last-resort:

1. **`review-claim` refuses a PR that is not OPEN** — nothing to review, no review
   round wasted. **Exception (#2289):** `CLOSED` **without** merge is not lumped with
   `MERGED` — if the linked issue is not already `status:shipped` / closed, `tal`
   resets it to `agent:ready` instead of leaving it on `agent:awaiting-review` forever.
2. **`merge-batch` drops from the lot any PR whose review lease is alive** — the
   verdict gets to land first; a lease past its TTL does not hold the lot hostage
   (`tal gc` reaps it).
3. **`review-verdict` re-reads the PR state immediately before writing.** `MERGED` ⇒
   it refuses, releases the review lease, and tells you to open a **new issue** if
   the blocking finding still stands — the finding is on `dev` now. `CLOSED` without
   merge follows the same reset path as (1) when the issue is still open and not
   shipped. If the state cannot be read at all it also refuses (per #2151, an
   unmeasured state is not "OPEN"), but keeps the lease so the verdict can be retried.

On refusal the ledger usually stays as merge left it (`shipped`, issue closed). The
#2289 reset path is the deliberate exception for abandoned PRs — it **does** write
`queued` + `agent:ready`, but **never** when the issue already carries `status:shipped`,
ledger `shipped`, or GitHub `state: closed`.

## Merge demands the GitHub verdict itself, matching HEAD (#2261)

Labels survive a new push, and the ledger can be edited — neither is proof that
*this revision* was reviewed. `merge` / `pr-merge` / `merge-batch` therefore
require a fourth piece of evidence on top of the #2156 trio (`review-passed`,
clean merge, green CI): a `tal:review verdict=pass` comment **on GitHub** whose
`sha=` prefix matches the PR HEAD. No matching comment ⇒ the merge refuses;
`--force` demands `--note` and posts the bypass onto the PR.

Two details that took a round each to get right:

- The evidence is captured **once, at the gate** (`merge_blockers` returns it)
  and reused for the ledger line. Any commit landing on the PR branch between
  gate and ledger changes HEAD, and a second lookup would then record
  `verdict=KHÔNG_CÓ` for a merge that passed the gate. The sha recorded is the
  one the reviewer actually read.
- Unlocking a **review** lease (`tal unlock pr-<N>`) requires `--note` — always,
  not just with `--force` — and the unlock is commented onto the PR. A review
  lease names the session whose verdict is still owed; discarding it silently
  reopens the #2153 race.

## Merge refuses a PR whose base is `main`, and `--force` does not open it (#2573)

Every gate above asks *"is this PR good enough?"*. This one asks a different
question first — **"where does it land?"** — and it runs before all of them.

Merging a `dev → main` PR runs `deploy-xserver.yml`: `migrate --force`, two
`db:seed`, and the Platform provisioner, against the **production database**,
unattended. Almost nobody chooses that: when a `dev → main` PR merges, GitHub
**silently retargets every open PR aimed at `dev` to `main`**
(`automatic_base_change_succeeded` in the timeline), keeping the title, body and
labels intact. On 2026-08-12 four promotions dragged **nine** PRs across, and one
of them was merged into `main` at 11:32 JST — mid-lunch — reaching production
only because `backend-tests` was cancelled before `workflow_run` chained into the
deploy.

The caller reads `mergeStateStatus: CLEAN` and merges. Nobody re-reads
`baseRefName`, because it was right when the PR was opened.

```
$ tal merge 2513
PR #2513 nhắm `main`, KHÔNG phải `dev` — merge nó là phát hành lên production…
      gh pr edit 2513 -R godx-jp/godx-tempo --base dev      # bị kéo nhầm
      tal merge 2513 --promote                              # cố ý phát hành
`--force` KHÔNG mở rào này.
```

Three deliberate edges:

- **`--force` does not open it.** `--force` is what every session types daily to
  skip the review gate; letting it bypass would kill this guard on day one.
  `--promote` is a statement of *intent*, not a way of saying "whatever".
- **`--promote` does not open a foreign base.** A PR aimed at `release/x` is
  still refused — that caller is mistaken, not promoting.
- **Base unreadable ⇒ allowed through.** This guards one specific accident, not
  a flaky network. Fail-closed here would kill every merge whenever GitHub is
  slow, and the guard would be removed wholesale.

## `tal merge` refuses a red *or unfinished* check — and this is NOT a gate (#2639, #2669)

**Read this heading literally.** What follows covers exactly one path — the one
that goes through `tal`. `gh pr merge` and the green button on github.com walk
straight past it, today and after you finish reading. Do not cite this section
as "CI is gated"; a gate that only guards one door is the paper gate this whole
section exists to describe.

There is no mechanical gate at all, and that is a **plan limit**, not a
misconfiguration:

```
$ gh api repos/godx-jp/godx-tempo/branches/dev/protection
403: Upgrade to GitHub Pro or make this repository public to enable this feature.
```

A private repo on the current plan cannot declare required status checks. Every
"don't merge when red" rule in this repo is therefore discipline, enforced by
nothing — and on 2026-08-12 it failed: **#2630 merged into `dev` at 15:05:50Z
with `arch-gate` already red on that very PR.** CI runs on the *merge* commit
(base + branch), so from `5b71ddd9a` onward every PR into `dev` went red with
it. #2636 touched zero PHP and went red anyway; its author spent three rounds
proving the breakage was not theirs. The window measured 45 minutes.

What `tal` does now, on its one path:

- **A `fail`/`cancel` check on the PR refuses the merge, and `--force` does not
  open it.** Same shape as #2573 above and for the same reason: `--force` is the
  daily "skip the review gate" flag, so a guard it opens is dead on day one. It
  also applies on the `merge-batch` path — a red PR is dropped from the lot.
- **The refusal names the failing check.** `CI ĐỎ: arch-gate`, not a dump of all
  eleven checks with one `=fail` buried in it.
- **If the same check is also red on `dev`, the refusal says who broke it** —
  commit, PR, author — walking back at most 12 commits on `dev` to the *oldest*
  red one in the contiguous run, and it posts one comment (idempotent per
  commit+check) on that PR. That comment is the notification: GitHub mails the
  author. It is the only channel reachable without new infrastructure or
  secrets, and it fires only when someone hits the refusal, not the moment `dev`
  turns red. Diagnosis is best-effort and swallows its own errors — a diagnostic
  that can kill `tal merge` gets removed wholesale.
- **The one way through is `tal merge <pr> --ci-red --note "<why>"`**, and the
  note is posted onto the PR. Use it when you have *verified* the red comes from
  outside this PR. `--ci-red` is a statement of intent; `--force` is not.

### "Not yet known" is not "green" (#2669)

#2639 only catches CI saying *red*. It stayed correctly silent for the other
shade — CI **not having spoken yet** — and that shade was measured twice in one
session, both merged with checks still running:

| PR | merged | still running at merge |
|---|---|---|
| #2653 | 2026-08-12T16:31:58Z | `arch-gate`, `build · vet · gofmt · test` |
| #2663 | 2026-08-12T17:55:03Z | `arch-gate` — which went green at 17:55:42Z, **39s after the merge** |

The cost is identical to #2639's, because the mechanism is: CI runs on the
*merge* commit, so a PR that turns red only after landing takes every later PR
down with it, and their authors hunt a ghost.

So `tal merge` now requires checks to be **complete**, not merely not-failing:

- **Any check not in `{pass, fail, cancel, skipping}` refuses the merge**, on the
  `merge` path and the `merge-batch` path alike (a PR with running checks is
  dropped from the lot, exactly as a red one is). `--force` does not open it.
- **`skipping` is a conclusion, not a wait.** This repo skips
  `pest`/`timezone-matrix`/`flake-hunt` on nearly every PR (a `paths`/`if` filter
  that never matches stays `SKIPPED` forever). Counting it as unfinished would
  block almost everything, and a guard that blocks everything gets deleted.
  `wait_checks()` learned the same lesson earlier and for the same reason.
- **An unknown bucket counts as unfinished.** The list declares what *is* done,
  so a vocabulary change in `gh` or a new GitHub state fails closed. Blocking
  wrongly is visible in one command; passing wrongly is invisible until `dev`
  is red.
- **The refusal names the check and how long it has been running** — `CI CHƯA
  XONG: build · vet · gofmt · test — đang chạy 37s · arch-gate — đang chạy 37s`.
  The age is the actionable part: `arch-gate` normally finishes in about a
  minute, so "41m" means something is stuck, not that you should wait.
- **It refuses; it does not wait.** Retyping one command is cheaper than a sleep
  loop inside `tal` (timeout, Ctrl-C mid-wait, the batch gate held open while it
  sleeps). If you *want* to stand and wait, `tal pr-merge <pr>` already has that
  loop (`wait_checks`, `--timeout`) — there was no reason to build a second one.
- **Same escape hatch, deliberately: `--ci-red --note "<why>"`.** Two flags for
  two shades of the same assertion ("CI has not spoken in my favour and I am
  merging anyway, and here is why") would only create a second thing to forget
  to wire into `merge-batch`. The comment posted on the PR says *MERGE KHI CI
  CHƯA XONG* rather than *CI ĐỎ*, so the ledger keeps the two apart.

Nothing above changes `--require-ci` (#1454). That flag answers "must this PR
*have* green CI at all" — still no, because CI here is the full pest suite. The
new guard answers a different question: the checks are already running, so they
*will* speak; merging now does not save the wait, it just moves the answer onto
a merge commit where someone else's PR pays for it.

Still open, and it needs the project owner: the only real fix is direction 1 —
GitHub Pro, or making the repo public. Both cost something. Until then the web
button remains wide open — for red checks and for running ones alike. Neither
this section nor #2639's is a gate; they cover the `tal` path and nothing else.

## #2300 — hardening tổng lực: các hợp đồng hành vi mới

Một đợt audit đối kháng toàn bộ `tal` (54 finding từ 4 auditor độc lập) sửa trong
MỘT PR. Những hợp đồng ĐỔI mà người vận hành cần biết:

- **Ledger THẮNG — nhãn là cache một chiều.** Gỡ `agent:dead-letter` bằng gh
  label sẽ bị đồng bộ dán lại; can thiệp người đi qua lệnh tal. Đường chính danh
  mở khoá dead-letter là **`tal requeue <N> --note "<vì sao lần này sẽ khác>"`**:
  reset `review_rounds`/`reaps` (chuỗi thất bại làm lại), GIỮ `attempts` +
  history (sử liệu), gắn lại `agent:ready`. `tal unlock` KHÔNG reset counter —
  nó là lệnh gỡ khoá, không phải ân xá — và sẽ cảnh báo nếu counter sắp tái
  dead-letter.
- **Verdict phải là của người GIỮ lease review, trên ĐÚNG bản đã đọc.**
  `review-claim` ghim `headRefOid` vào payload của ref; `review-verdict` đòi
  chính session giữ ref đó và so sha ghim với head sống — coder push bản mới
  giữa chừng thì verdict Fail kèm nhả lease, phải claim lại đọc diff mới.
  `pass` reset `review_rounds` về 0: nó đếm CHUỖI thất bại liên tiếp, không
  phải tổng đời issue.
- **"Đã code" = đã BÀN GIAO PR (`tal pr`), không phải đã từng claim.** Claim
  để-đọc rồi release no-op không còn làm mất quyền review (#2091). Attribution
  ghi ở trường `coders` của ledger (không bị cắt như history) — rào tách vai
  đọc từ đó, và giờ áp cho TỪNG PR trong `merge-batch` (coder chạy batch sẽ
  thấy PR của mình bị bỏ khỏi lô kèm comment).
- **`tal merge` merge TRƯỚC, dọn SAU.** Merge fail ⇒ worktree/branch/nhãn còn
  nguyên (thứ tự cũ xoá worktree trước và đã cắn thật). Không dùng
  `--delete-branch` nữa — branch remote xoá qua API sau khi merge OK. Merge lẻ
  bị CHẶN khi cổng merge-batch đang chạy (suite của lô phải kết luận trên base
  không đổi).
- **Claim GIỮ nhãn review đang gắn** (`agent:review-passed`,
  `agent:awaiting-review`, `agent:changes-requested`…). Chỗ duy nhất tháo
  chúng là `tal pr` khi push bản mới. Reap lease review hỏi trạng thái PR
  trước khi re-stamp nhãn (PR đã merge thì thôi).
- **Kỷ luật lỗi API**: "không đo được" RAISE thay vì trả rỗng ở mọi phép đo
  dẫn tới quyết định phá huỷ (ledger, refs, nhãn, pr_issue, wait_checks…);
  404 THẬT mới được coi là "không còn" (`Gone`). `--paginate` nhiều trang được
  ghép đúng — issue >100 comment không còn bị đọc thành "sổ trắng".
- **`--force-region` tách khỏi `--force`**: vượt rào vùng file là quyết định
  riêng, phải nói to.
- **Push lease theo GIÁ TRỊ**: mọi force-push của tal dùng
  `--force-with-lease=<branch>:<sha-vừa-đọc>` — `git fetch --prune` ở claim
  không còn làm lease thoái hoá thành `--force`.
- **Verdict MỚI NHẤT trên một sha là câu trả lời (#2299)**: `pass` rồi `changes`
  trên cùng sha ⇒ hết bằng chứng. Kèm theo: `merge-batch` nói rõ lý do mỗi PR bị
  bỏ khỏi lô thay vì im lặng. (Ngoại lệ "verdict sống qua chuỗi realign-only" chỉ
  kích hoạt khi có commit căn-pointer submodule — không repo nào ở đây sinh ra
  loại commit đó nữa.)

## Incidents

```sh
.claude/tools/agent-loop/tal status               # who holds what, and for how long
.claude/tools/agent-loop/tal gc --dry-run         # what would be cleaned up
.claude/tools/agent-loop/tal adopt 1234           # lost the CARD, still hold the lease
.claude/tools/agent-loop/tal unlock issue-1234 --force --note "the reason"
```

If the symptom is `không thấy .tal-lease.json` while `tal status` still shows the
lease as yours, you want `tal adopt`, not `claim` and not `unlock` — see
[Losing the CARD is not losing the LEASE](#losing-the-card-is-not-losing-the-lease--tal-adopt-2238).

`unlock --force` is manual intervention — use it only when you are **certain** the
lease holder is dead. Normally let `tal gc` reclaim it on the TTL; stealing a live
session's lease is precisely the disaster this whole system exists to prevent.

Environment variables: `TAL_TTL` (default 2700s), `TAL_MAX_ATTEMPTS` (3), `TAL_BASE`
(`dev`).

## Acceptance-tested (2026-07-30)

Run for real against `godx-jp/godx-tempo` with the scratch issue #1298 / PR #1299
(since closed and cleaned up):

| Test | Result |
|---|---|
| Session B claims an issue session A holds | Blocked, exit 75 (the local lock) |
| The same, with the local lock removed to simulate another machine | Blocked, exit 75 (CAS ref 422) |
| Session B writes into A's worktree | The hook denies it |
| The lease holder writes into its own worktree | Allowed |
| `git push origin dev` from an issue worktree | The hook denies it |
| Writing outside the worktree | The hook does not intervene |
| A mismatched epoch (the lease was reissued) | `assert` fails, exit 4 (FENCED) |
| The session that coded a PR reviewing its own PR | Blocked, exit 5 |
| A third session cutting into a review in progress | Blocked, exit 75 |
| `agent:changes-requested` versus an ordinary issue | Goes to the head of the queue |
| A fix round | Reuses the existing worktree, `attempts=2`, `epoch=2` |
| `gc` against three real leftover branches from merged PRs | Deletes exactly three, leaving the open PR's branch and another session's branch untouched |
| Five wrong branch names (`feat/x`, `fix-1234`, `my-branch`, `git -C sub -b feature/x`, `push HEAD:refs/heads/hotfix`) | The hook denies all five |
| Correct names (`issue-N`, `git -C sub -B issue-N`, `git branch -D`) | All three allowed |

## Worktree mồ côi — vì sao gc thà bỏ dở còn hơn dọn nửa chừng (#2177)

Trạng thái nguy hiểm nhất của một worktree không phải "chưa dọn" mà là **nửa
chết**: đăng ký git đã bị prune nhưng thư mục còn nguyên (vendor hardlink,
permission…). `cd` vào đó vẫn thành công, và mọi lệnh git từ trong đó **im lặng
giải về repo cha** — không một cảnh báo nào. Phiên 2026-08-07 dính bốn lần, một
lần đã commit nhầm lên nhánh của session khác.

Hai rào từ #2177:

1. **`remove_worktree` không bao giờ để lại nửa-chết.** Xoá thất bại ⇒ RENAME
   thư mục sang `<tên>.orphan-<timestamp>` TRƯỚC, rồi mới prune. Rename cũng
   thất bại ⇒ không prune, không xoá branch, báo lỗi — worktree còn nguyên vẹn
   là trạng thái an toàn hơn worktree nửa-chết.
2. **`assert_worktree_attached`** — so `git rev-parse --show-toplevel` với chính
   thư mục đang đứng; lệch ⇒ từ chối chạy với thông điệp "WORKTREE MỒ CÔI".
   Gắn ở `ensure_worktree` (chặn tái dùng lúc claim) và `do_assert` (cổng của
   mọi thao tác ghi, đặt SAU kiểm lease để không che thông điệp lease).
3. **Vỏ orphan phải hội tụ (#2364).** Composer trên macOS có thể đặt ACL
   `deny delete` lên `vendor/`; `rmtree(ignore_errors=True)` khi đó bỏ lại một
   thư mục rỗng mà vẫn trông như thành công. `gc` gỡ ACL bằng `chmod -RN`, kiểm
   đường dẫn đã thật sự biến mất rồi mới báo thành công, và quét lại mọi
   `issue-*.orphan-*` từ các lượt cũ ở lần chạy sau.

Kỷ luật cho session (không máy nào cưỡng chế hộ): trước mỗi cụm lệnh ghi trong
một worktree, `git rev-parse --abbrev-ref HEAD` — nhánh không khớp `issue-<số>`
đang cầm thì DỪNG, đừng tin đường dẫn.
