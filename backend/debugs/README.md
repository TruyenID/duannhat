# Debugs

Debug session records. Each session investigates and fixes one bug using the scientific method. Independent from plans — bugs can come from anywhere (production, QA, local).

## Status lifecycle

| Status | Meaning |
|--------|---------|
| `investigating` | Symptoms gathered, hypotheses being tested |
| `fixing` | Root cause confirmed, fix being implemented |
| `verifying` | Fix done, regression test being written |
| `resolved` | Verified locally, awaiting push/PR |
| `closed` | PR merged or fix accepted |
| `abandoned` | Couldn't reproduce / wontfix / dropped |

## Index

| # | Bug | Status | Severity | Source | Branch |
|---|-----|--------|----------|--------|--------|
| 001 | [Category click opens edit drawer instead of detail view](debug-001/README.md) | closed | medium | user-report | feature/plan-002-hq-category-screen |
| 002 | [Add MenuSection entity with N:N menu relationship](debug-002/README.md) | resolved | medium | local | — |
