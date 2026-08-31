---
title: The issue-loop skills — roles, boundaries, pitfalls, and which agent runs them
category: guide
tags: [agent-loop, skill, review, worktree, claude, codex]
summary: Documentation for issue-work / issue-review — what each role does, which boundaries the machine enforces and which are only words, the catalogue of pitfalls already paid for, the runbook for incidents that have actually happened, and how the same two skills run under both Claude Code and Codex.
related: [agent-issue-loop, local-config]
---

# The issue-loop skills

`docs/guide/agent-issue-loop.md` describes the **mechanism**: the CAS lease on a
git ref, the state machine, and the batched merge gate. This document describes
**what a session actually reads and follows** — the two skills in
`.claude/skills/` (`issue-submodule` was removed with the monorepo merge, #2348).

The two documents deliberately do not repeat each other. To learn *why the lease is
a git ref rather than a label*, read the other one; to learn *what a session must
do, in what order, and where it trips*, read this one.

| Skill | Role | One invocation = |
|---|---|---|
| `issue-work` | CODE | **one** issue: pick → claim → fix → narrow tests → open a PR → hand over |
| `issue-review` | REVIEW | **one** PR: choose → read from the source → narrow tests → conclude → (merge the batch) |

---

## 1. The boundaries between the two roles

The most important boundary is **role separation**: the session that writes the
code may not review it. That is the reason the whole loop exists — an agent grading
itself produces a "pass" that carries no information.

But a boundary is only worth as much as the place it is enforced:

| Rule | Enforced where | How strongly |
|---|---|---|
| One session per issue | **CAS on a git ref** (`refs/tempo/leases/*`) | Absolute — the second attempt gets a 422 |
| No writing into the worktree of an issue you do not hold | The **`PreToolUse` hook** | Absolute for file-writing tools |
| Branches/worktrees may only be named `issue-<number>` | The **`PreToolUse` hook**, including `git -C <path>` | Absolute |
| The reviewer must be a different session from the coder | `tal review-claim` | **Leaky** — `review-verdict` does not check (#1397) |
| Do not merge your own PR | *(nowhere)* | **Only words in the skill** |
| Only narrow tests while coding/reviewing | *(nowhere)* | Only words |

**Read this table by its third column.** The "only words" rows are where the rule
gets broken — and it really does get broken: in a single session, one session
merged its own PR **five times**, even though that same session wrote the rule
forbidding it. Not out of dishonesty; every instance had a reasonable justification
at the time. That is exactly what a guardrail must prevent and a sentence cannot.

> A rule placed where only goodwill reads it will be broken. A rule placed where the
> machine must pass through will not. When adding a new rule to a skill, the first
> question is **"where is it enforced"**, not "how do I word it".

---

## 2. `issue-work` — the CODE role

### The mandatory order

```
tal gc → tal queue → tal claim <N> → (check whether it is already done) → fix → NARROW tests → tal pr
```

The first three steps must not be reordered and must not be skipped:

- **`gc` before `queue`**: without cleanup the queue still holds dead sessions'
  leases, and you are looking at a wrong queue.
- **`queue` before `claim`**: the queue is already sorted correctly
  (`agent:changes-requested` → `critical` → `security` → `high` → `bug` → lowest
  number first). Picking by instinct skips work that is waiting on a review fix.
- **Check before fixing**: see the "read it back" section of
  `docs/guide/agent-issue-loop.md`.

### The three most expensive rules, with their reasons

**Do not claim an issue that already has an open PR.** Somebody is already on it.
Claiming is not merely useless — it **overwrites the authorship in the ledger**, and
from then on `tal review-claim` treats you as the coder of that PR and **refuses to
let you review it**, even though you wrote none of it. This happened on
#1353/#1363.

**NARROW tests, never the full suite.** The backend has thousands of tests; one run
consumes the whole lease. Run exactly the scope you touched:

```sh
cd backend && php -d memory_limit=-1 vendor/bin/pest --compact --filter=TheRelevantTest
cd web/admin && pnpm typecheck          # NOT `pnpm typecheck` at the root — that scans all three web apps
go test ./internal/<package>/...        # NOT ./...
```

The full suite is the **merge gate**, run once for the whole batch at
`tal merge-batch`. The coding loop repeats many times so it must be fast; the gate
is passed once, which is why it can afford to be complete.

**Widen the test scope only when you yourself see signs of spread** — a migration
change, a shared model, an Omnify regen — and when you do, say in the PR why the
widening was necessary.

### `Closes` or `Refs` — a decision with consequences

The PR body decides whether the issue closes:

- **`Closes #N`** — this PR completes the whole issue.
- **`Refs #N`** — this PR does **part** of it; the issue stays open with the
  remainder.

If you choose `Refs`, you **must write out what remains, right there in the PR
body**. A `Refs` with no "what is left" is an issue hanging indefinitely with nobody
knowing what is missing.

`tal pr` inserts `Closes` automatically **only when** the PR body mentions the issue
with no keyword at all — so writing `Refs #N` is enough to keep the issue open,
with no extra step.

---

## 3. `issue-review` — the REVIEW role

### Discard what is not worth reviewing

Before reading a single line of diff:

- A PR whose current `head_sha` **already has a verdict** in the ledger ⇒ nothing is
  new, skip it. This is the guard against repeat reviews; without it, three sessions
  grade the same revision three times.
- A `draft` PR ⇒ skip.
- A PR from **your own session** ⇒ change roles or skip.

An empty `eligible` list is **not** automatically "no work" (#2172):
`tal review-queue` also prints `claimed` — PRs that wait for review but are held
by another session, each with the holder's session id and remaining lease time.
`rỗng THẬT` = end the turn; `đang bị giữ` = work exists, just not yours — do not
wait for it and do not touch the refs (`by: "?"` means the owner is unreadable;
`tal gc` adjudicates orphans, never a manual delete).

### A red test ≠ a wrong PR

Three signs that the red is caused by **how it was run**, not by the PR:

| Sign | Almost certainly |
|---|---|
| Red **instantly** (< 1s) with **0 assertions** | The app never booted: missing vendor, missing `.env`, wrong cwd |
| **Every** test red with the same message | The environment |
| An error naming a path **outside** the tree under test | Autoload/symlink pointing at the wrong tree |

And two rules that go with it:

**Read the harness's own "Run:" line before running it.** Already paid for:
`bash .githooks/pre-push_test.sh` was invoked **without its `$1` argument** (the
hook path) → the `HOOK` variable resolved to `/`, two cases went red, and it was
nearly reported as a fault in the PR. Invoked correctly as
`bash .githooks/pre-push_test.sh .githooks/pre-push`, it was **5/5 green**.

**Always measure the baseline first.** Run the same tests on `origin/dev` **without
the PR merged**. `27 passed` → `28 passed` is evidence; `28 passed` on its own says
nothing.

### To check a PR, check INSIDE a tree with the PR merged

A `grep` in the main worktree is reading `dev`, **not the PR**. Already paid for: a
PR was concluded to have "left dead code behind" when it had removed all of it —
only the wrong tree was read.

```sh
git worktree add -f --detach /tmp/rev origin/dev
git -C /tmp/rev merge --no-edit <the-PR-head-sha>
grep -rn "…" /tmp/rev/…              # READ HERE
```

That temp tree is reusable across PRs — `git -C /tmp/rev reset --hard origin/dev`
between rounds, so `composer install` / `pnpm install` is only needed **once**. For
the backend, do not symlink `vendor` from the main tree: autoload will resolve the
base path to that other tree and every test dies with
`Call to a member function connection() on null`.

### Conclude with Conventional Comments

`label (decoration): subject`, where only `(blocking)` blocks a merge.

| Label | Use when |
|---|---|
| `issue (blocking)` | A real defect that must be fixed before merging |
| `issue (non-blocking)` | A real problem, but merging first is acceptable |
| `question` | Not enough evidence to call it a defect |
| `praise` | Something done well — say it honestly, not as a formality |

If you cannot construct a concrete failure scenario (input/state → wrong result), it
is **not** `blocking`: drop it to `question` or `suggestion`. And a clean review is a
valid outcome — say plainly "no blocking findings"; do not invent problems to look
busy.

---

## 4. The pitfall catalogue — eight already paid for

All of them come from **one session** reviewing 13 PRs. None is a one-off
carelessness; each is a hole the skills did not cover.

### 1. `cd` is not a guardrail

If `cd X && command` has its `cd` **fail**, **the command still runs**, in the old
directory.

```sh
git -C "$WT" merge origin/dev          # RIGHT — the path travels with the command
cd "$WT" && git merge origin/dev       # WRONG — a failed cd merges somewhere else
```

The real consequence: a `git merge` ran by mistake into the **shared worktree
holding another session's uncommitted WIP**, and `git merge --abort` was needed to
rescue it. It happened **twice** in one session — a worktree just deleted by
`tal gc`, and a stale worktree registration, both make `cd` fail.

### 2. The number `0` is an assertion

`gh pr list … -q length` returning `0` **once** is not enough to say "there are no
PRs left". A report of "0 open PRs across all 8 repos" was made while **13** were
open. Before reporting a zero, ask again by another route (list instead of counting,
or `--state all` and filter).

### 3. Running a harness without its arguments → a false red

See section 3 above.

### 4. Grepping the wrong tree

See section 3 above.

### 5. Claiming an issue that already has an open PR

See section 2 above.

### 5. Typing a sha by hand

**Take a sha from git, never type or shorten one.** `SHA=$(git rev-parse …)`, then
print it back before you act on it. What it cost: a hand-written sha passed to
`git update-index --cacheinfo`, a command that writes straight into the index and
**does not verify the object exists** — so it failed on the next person's clone,
not on the machine that typed it.

### 6. Merging your own PR

Five times in one session. The rule lives only in the skill, so nothing stopped it.
If circumstances genuinely force it (a patch for the very tool that is broken):
**comment on the PR stating plainly that the rule was broken, why, and exactly what
deserves a second look** — so that a later session can still review after the fact.
Do not hide it.

### 7. Editing a tracked file in the shared worktree to unstick yourself

If you must: make the smallest possible change, **restore it immediately** with
`git checkout -- <path>`, and confirm `git status` is clean before continuing.
Another session is working in that same tree.

---

## 5. Runbook — incidents that have happened, and how to get out

### A locked-out session: every Bash command is denied

**Symptom:** `pwd`, `cd ..`, and even the `tal claim` that the deny message
suggested are all blocked, accompanied by
`[tal] This worktree (issue #N) has RELEASED ITS LEASE…`.

**Cause:** the shell is sitting inside a worktree whose lease was released, and the
lease guard is judging the **CWD** rather than the content of the command.

**Fix:** the guard has been corrected (#1382) to apply only to commands that
**actually write**. If it still catches you, it is almost certainly that
**`tal` in the main worktree is out of date** — the hook runs the main tree's copy,
not the branch's:

```sh
tal doctor        # includes a check for "is tal in the main worktree the latest"
git -C <repo> checkout origin/dev -- .claude/tools/agent-loop/tal
```

> This is the worst class of failure in an automated tool: **the fix exists, it is
> merged, and it still has no effect.** `tal doctor` now reports it.

### `tal assert` reports a LOST LEASE mid-task

**STOP.** Do not push, do not open a PR. If the lease has been granted to somebody
else, your commits are garbage — do not turn a conflict into data corruption.

If `tal status` reports `MISMATCH: the ref exists but the ledger records no holder`
and you are **certain** no other session is running (`tal status` lists no leases):

```sh
tal unlock issue-<N> --force --note "<why you are certain>"
tal claim <N>
```

**Dead-letter**: the legitimate human unlock is `tal requeue <N> --note "…"`
(#2300) — it resets `review_rounds`/`reaps` and re-gates the issue; peeling the
label by hand gets resynced from the ledger, and `unlock` keeps the counters so
the next claim re-dead-letters.

For a **review** lease (`tal unlock pr-<N>`), **in addition to `--force`** (which
every unlock already requires) the `--note` is mandatory (#2261/#2299). The unlock is commented onto the PR: a review lease
names the session whose verdict is still owed, and discarding it silently
reopens the verdict-after-merge race (#2153).

### `không thấy .tal-lease.json` while the lease is still ALIVE (#2238)

**Symptom:** `renew | assert | pr | release` all exit **3** with *"không thấy
`.tal-lease.json`"*, yet `tal status` still lists the issue as held by your
session and the TTL has not run out. The card on disk is only a cache; a
`git worktree prune`, a moved directory or a half-finished `gc` can take it while
the ref and the ledger are untouched.

Do **not** reach for either of the two commands that first come to mind — `claim`
bumps the fencing epoch, `unlock --force` throws away a live lease:

```sh
tal adopt              # infers the issue; or: tal adopt <N>
tal assert             # green again, SAME epoch
```

`adopt` rebuilds the card from the ledger and nothing else. It refuses (exit 5) if
the ledger names a different session, and refuses if the CAS ref is already gone —
in that second case the lease really was reclaimed, and `tal claim <N>` (epoch +1)
is the correct answer. Mechanism and the reasons behind both refusals:
[agent-issue-loop.md](agent-issue-loop.md#losing-the-card-is-not-losing-the-lease--tal-adopt-2238).

### A healthy issue labelled `agent:dead-letter`

Fixed (#1342): the dead-letter threshold now counts **failed review rounds**
(`review_rounds`), not claim attempts. If you meet an older issue still carrying the
label:

```sh
gh issue edit <N> --remove-label "agent:dead-letter" --remove-label "status:blocked" --add-label "agent:ready"
```

### A PR closed by `gh` without merging

**Symptom:** `gh pr merge --delete-branch` reports
`Head branch is out of date`, the merge **fails**, but the branch **is deleted
anyway** ⇒ the PR closes.

**Fix:** the commits are still in the local worktree. Merge `dev` into the branch,
push again, and open a new PR.

**Avoidance:** the repo has `delete_branch_on_merge` enabled, so `--delete-branch` is
redundant. Drop it and this trap does not exist.

### The merge gate reports `full suite RED` while vendor is healthy

Look at the `exit code`: **3 = THE GATE IS BROKEN** (environment), **2 = genuinely
red tests**, **4 = another session holds the gate**. A red run with an error like
`Class "Pest\Panic" not found` while `vendor/` is healthy afterwards means two
batches ran on top of each other — fixed (#1355) with a gate lock plus a per-session
temp tree.

---

## 6. When the tools lie

A tool can exit 0 having done nothing. Four bugs of exactly that kind have been fixed
(#1342), but the habits are still needed:

1. **Read it back from the source** after every state change — the issue labels after
   a `verdict`, the PR body after `tal pr`.
2. **A deviation from what you expected is a finding**, not an annoyance. Open an
   issue with reproduction commands.
3. **A bug in a file somebody holds a lease on** is not yours to fix — raise it in a
   comment on their issue. Every `tal` fix goes through **one** path: four parallel
   PRs into the same 1,600-line file are four conflicts, and that has actually
   happened.

---

## 7. Never

- Never edit anything before `tal claim` has succeeded.
- Never push when `tal assert` fails.
- Never commit somebody else's WIP — stage **explicit paths**, never
  `git add <dir>`, never `git add -A` in a shared worktree.
- Never use `--no-verify`, `--force` or `--skip-*` to get past a gate that is
  blocking. The gate is blocking because there is something real to deal with.
- Never rename a branch away from `issue-<number>`. If another branch is needed,
  **open a sub-issue** and use its number.
- Never type a sha by hand.
- Never rewrite a file that an open PR already touches.

---

## 8. Running many agents without burning the budget (#2178)

Measured 2026-08-07: one coordinating session drove ~20 subagents for **~3.3M
tokens on the subagent side alone**. Where it went, in order: (1) rebuilding the
environment per worktree — vendor, node_modules, times ~10 worktrees; (2) the
always-loaded context every agent pays on every
turn, 20–30k tokens per agent. Those two dwarf everything else, and both are
process choices, not physics. The rules below are what that session proved out.

### Claim by file-region cluster, not by issue

Several issues touching the same region ⇒ **one worktree** (branch named after
the lead issue, still `issue-<number>`), **one gate run**, **one PR** closing
all of them — the body lists `Closes #A`, `Closes #B`, … explicitly, one per
line. One environment build instead of N is the single largest saving available.

The constraint that keeps it honest: **work on the same file must be
SEQUENTIAL** inside the cluster. Two agents editing one golden file in parallel
is a conflict you pay for later with interest. Real example: #2062 + #2064 +
#2065 shipped as one worktree, one gate run, one PR — same print-template
region, three issues, sequential edits.

### The agent report contract

An agent's final report is **a table plus numbers plus `file:line`** —
narrative is forbidden. The prose interpretation, when needed, is the
coordinator's job. Mandatory fields, no exceptions:

| Field | What it must contain |
|---|---|
| What changed | files touched, `path:line`, one row per change |
| The measurement | the command run and the number it printed — not "verified" |
| Real pass/fail | actual counts (`255 ok / 0 FAIL`), never "tests pass" |
| Mutation check | what was broken on purpose, and that the guard went red |

A 2–4k-token narrative report from each of 20 agents is 40–80k tokens of
retelling the coordinator then has to re-verify anyway.

### Re-verification = spot-check, not re-investigation

Re-verifying agent claims is **deliberate and stays** — the 2026-08-07 session
rejected 4 agent claims on re-measurement and exposed 1 guardrail that was green
while guarding nothing. Cutting re-verification buys errors with the saved
tokens. But its shape is bounded: **spot-check the heaviest claim with 1–2
measurements** (a grep, a targeted test run). Rebuilding the whole
investigation from scratch is the anti-pattern this section exists to stop —
the negative-result rule in `CLAUDE.md` ("ĐÃ KIỂM, KHÔNG CÓ LỖI" + how) is the
cheap, durable half of the same mechanism.

### The numbers to reason with

- ~20 agents ≈ **3.3M tokens** (subagent side, one session, measured).
- The two dominant sources: **repeated environment builds** per worktree, and
  the **always-loaded files at 20–30k tokens per agent per turn** — which is
  why fat sections get moved out of `CLAUDE.md` into skills (#2179), and why
  clustering claims (above) beats any micro-optimization inside a single agent.


---

## 9. One skill set, two agents: Claude Code and Codex (#2369)

The loop was written against Claude Code and quietly assumed it. Codex can run
the same loop, and does so from **the same files** — `.codex/skills/issue-work`
and `.codex/skills/issue-review` are symlinks into `.claude/skills/`. Do not fork
a Codex copy: two copies drift, and nothing tells you which one a given run read.

`.agents/skills/` is the other directory Codex searches, but `/.agents/` is in
`.gitignore` — anything placed there is invisible to everyone else. `.codex/` is
tracked, which is why it is the committed home.

### The lease identity trap — measured, not theorised

A lease is only exclusive if two concurrent agents present two different
identities. Codex launched *inside* a Claude session **inherits**
`CLAUDE_CODE_SESSION_ID` from the parent process, so both would have claimed the
same identity and the lease would have silently stopped excluding anything —
`tal claim` on an issue the parent already held would have looked like a
successful re-claim.

The first fix ranked the variables — Codex before Claude, "innermost agent
wins". **That is the wrong shape of fix**, and review caught it: a Claude session
launched *inside* Codex sees both variables too, and a static ranking would force
it to present the parent's Codex identity. Every static order is right in one
nesting direction and wrong in the other.

So `tal` does not ask who is innermost. It **composes**: whatever agent variables
are present all go into the identity, sorted by variable name.

| variables present | identity |
|---|---|
| `TAL_SESSION` | its value — an explicit override wins outright |
| exactly one agent variable | that value, verbatim and readable |
| more than one | `sha256(sorted "var=value")[:12] + "-nested"` |
| none | `shell` + hash of hostname + parent pid — **not stable across runs** |

A nested process carries a *different set* of variables than its parent, so
parent and child differ without anyone having to know which contains which. The
hash comes **first** in the composed form because every display and match site in
`tal` truncates to `[:8]`; a fixed prefix would eat exactly the characters that
distinguish (that is the #2300 lesson, paid for once already).

`tal doctor` prints which source is in use, warns when it fell through to the
hash, and — separately — warns whenever `TAL_SESSION` is set at all, because a
forgotten `export` inherited by two shells silently collapses two sessions into
one identity.

`tal_test.py::test_session_id_distinguishes_nested_agents` pins **both** nesting
directions, stability across calls, order-independence, and 8-character
distinctness. Verified by restoring the ranked version: the test goes red on the
reverse-nesting case with `cả hai đều 'codex-outer'`.

### What Codex cannot do

Fan-out (§8, and "Điều phối NHIỀU agent" in `issue-work`) needs a subagent tool
Codex does not have. Under Codex, work the clusters **sequentially** — one
`codex exec` per cluster. Every other rule in both skills applies unchanged.

Model tiers (`sonnet`/`opus`/`fable`) are Claude Code names. The *rule* behind
them survives: a diff touching a `riskDomains` path gets your strongest available
configuration, and if you cannot reach one, the verdict must say so.

### Running a turn headlessly

```sh
codex exec --sandbox workspace-write \
  -c 'sandbox_workspace_write.network_access=true' \
  -c 'sandbox_workspace_write.writable_roots=["<repo>/.git"]' \
  'Chạy skill issue-work: nhặt issue đầu hàng đợi và làm tới khi mở được PR'
```

Both `-c` flags are required (#2400): without `network_access` the sandbox cannot
resolve `github.com`, so `tal queue` dies immediately; without `.git` in
`writable_roots` the sandbox refuses `refs/heads/issue-<n>.lock`, so `tal claim`
cannot take the lease. Measured across three real runs.

`codex exec` runs exactly one turn and exits — that is its contract, so the loop
is your `while`/cron around it, never something the skill does to itself.
