# T1.9 Omnify code-generation review

## Reproducible generator

- The repository now pins `@omnifyjp/omnify` 5.9.8. T1.9 originally established the reproducible
  baseline on 5.9.4; T1.10a advanced it through the generated-column fixes released in 5.9.5–5.9.8.
- Omnify issue [omnify-jp/omnify-go#121](https://github.com/omnify-jp/omnify-go/issues/121)
  identified unsafe Laravel ALTER rollback ordering.
- The fix was merged in
  [omnify-jp/omnify-go#122](https://github.com/omnify-jp/omnify-go/pull/122) and released as
  `v5.9.4`. The full upstream Go suite passed before release.
- `pnpm exec omnify version` reports `omnify v5.9.8` and
  `pnpm exec omnify generate --check --verbose` reports every generated file current.

## Generated database artifacts

Generation produced twelve additive create/translation migrations and one nullable
`order_payments` ALTER migration. All thirteen pass `php -l`. A fresh SQLite migration and a
targeted rollback of the Plan 047 ALTER both complete successfully.

The generated `payment_methods` ALTER was intentionally not adopted: production migration
`2026_06_20_173703_add_type_to_payment_methods.php` already owns that column and index. Running a
second ALTER would fail on upgraded databases. The Omnify schema and lock still own the desired
state, while fresh installations receive the column through the existing migration.

The `OrderPayment` schema does not declare a duplicate single-column attempt index because its
association already generates the required foreign-key index. The retained compound indexes have
explicit MySQL-safe names. Omnify 5.9.4 generates rollback in dependency order: standalone compound
indexes first, then each foreign key, association index, and column.

A full historical rollback proceeds through the new Plan 047 migration and then fails at the old
`2000_03_10_000000_alter_floating_sections_table.php` rollback. That migration predates the upstream
ordering fix and is outside Plan 047; the failure is recorded rather than modifying historical
generated output in this task.

## Models, resources, and API safety

- Generated PaymentMethod model/request/resource bases now own `type`; the temporary manual model
  fillable and resource serialization overrides were removed.
- New gateway models, factories, enums, requests, policies, resources, locales, provider
  registration, lock data, and schema index were reviewed as generator-owned output.
- `PaymentGatewayConnectionResource` omits `secret_ref` and `webhook_secret_ref`. Opaque secret
  references remain server-side persistence details.
- Omnify 5.9.8 refreshes existing generated bases as a deterministic whole. Editable stubs that
  were absent from the checkout were created by the generator; generated bases were not hand-edited.
- No file under a `Services/` path was created or changed. `service.enable: false` remains effective.
- Generated TypeScript changes inside initialized application submodules were reviewed and left for
  their owning application tasks; no parent submodule pointer or unrelated dirty state is included.

## Verification evidence

| Check | Result |
|---|---|
| `pnpm exec omnify validate` | 185 schemas valid; only the pre-existing unknown `default` connection warning |
| `pnpm exec omnify generate --check --verbose` | Passed on Omnify 5.9.8 |
| `git diff --check` | Passed |
| PHP lint of the 13 Plan 047 migrations | Passed |
| Fresh SQLite migration | Passed |
| Targeted rollback of `2000_03_14_000001_alter_order_payments_table.php` | Passed |
| Payment architecture + PaymentMethod focused suites | Passed, 62 assertions |
| Full backend suite | 79 passed / 21 failed / 16,718 assertions; failures classified below |

The full-suite failures are not introduced by T1.9. Thirteen tests explicitly use the Redis cache
store, but this machine has no PHP Redis extension. The other eight failures (one incomplete
`MenuSection` fixture, three legacy token-envelope expectations, two shop-menu authorization
fixtures, one web redirect expectation, and one Workstation shop-auth fixture) reproduce on a
detached clean T1.8 worktree at commit `4d0cc303`. They remain baseline evidence and are not silently
changed as part of schema generation.
