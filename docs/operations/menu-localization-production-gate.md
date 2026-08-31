---
title: Menu localization production gate
category: guide
tags: [operations, localization, menu, release-gate, issue-959]
summary: "Release and data evidence gating the Jimbocho takeaway catalog going live in Japanese, English and Vietnamese, with named on-call and escalation owners."
related: [translatable-workflow]
---

# Menu localization production gate

Owner: Tempo on-call engineer. Escalation owner: Godx Platform engineering lead.

Scope: issue #959 and coverage epic #965 for the Jimbocho takeaway catalog in Japanese, English, and Vietnamese.

## Release and data evidence

- Production backend migration, idempotent `BetoyaCatalogLocalizationSeeder`, API smoke, and deployment completed successfully in [workflow run 29891353538](https://github.com/godx-jp/godx-tempo/actions/runs/29891353538) at commit `0f19553e4a5fc9e3d3435b858db6abd37f5570ca`.
- The production check must query `/api/v1/customer/branches/jimbocho/menu` with `Accept-Language: ja`, `en`, and `vi`, verify HTTP 200 and matching `Content-Language`, reject literal `Unknown`, reject duplicate identifiers, and reject Japanese characters in the English and Vietnamese payloads.
- Translation row uniqueness remains protected by the `(parent_id, locale)` database constraints. The seeder test suite proves rerun idempotency and transaction rollback.
- Live verification is read-only except for browser-local cart state. It must never submit an order or payment.

## Signals and thresholds

The customer API emits `X-Request-ID` on every response. Admin mutation audit records include the same `request_id`. Missing translations and excluded invalid topping relations emit the structured event `menu.localization.integrity` with `reason_code`, locale, tenant/menu identifiers, bounded paths, and request ID. Identical integrity events are deduplicated for five minutes.

During the first 30 minutes after deployment, the on-call owner checks:

- 5xx rate for the Jimbocho branch/menu endpoint remains below 1% over five minutes;
- p95 response latency remains below 2 seconds over five minutes;
- no repeated `menu.localization.integrity` event remains after the five-minute deduplication window;
- live Playwright desktop/mobile ja/en/vi tests pass without a production order;
- `Unknown`, cross-tenant IDs, mixed-language English/Vietnamese payloads, and duplicate menu item IDs remain zero.

Any failed condition blocks issue closure and triggers rollback.

## Rollback and recovery

1. On-call stops the rollout and records request IDs, locale, branch, menu, response status, and the failing workflow/test URL.
2. Revert the application release to the previous known-good revision — one revision covers the whole tree — through the normal deployment workflow. Do not manually edit production catalog rows.
3. If the failure is data-only, restore from the pre-deploy database backup or run the reviewed transactional repair. Never reverse translation schema while an application revision still reads it.
4. Rerun migration status, translation counts/duplicate checks, the three locale API checks, and the live non-destructive browser suite.
5. The Platform engineering lead approves recovery before reopening traffic or closing #959/#965.

The original implementation deployment is recoverable through the same workflow run and repository commit above; later regression fixes require a separate successful deployment run and fresh live evidence before closure.
