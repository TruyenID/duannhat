# T1.10 payment database constraint contract

## Responsibility split

Database constraints own identity/existence invariants that remain valid without application code.
Cross-row business consistency remains in the canonical writer/resolver because the current Omnify
association format does not express composite cross-table tenant/environment checks, and refund
capacity changes with concurrent operation state.

| Invariant | Database guarantee tested in T1.10 | Required canonical-service guard |
|---|---|---|
| Provider and option identity | Provider code is global; option code is unique per provider | Resolver rejects inactive/version-ineligible catalog rows |
| Merchant identity | `(provider, environment, merchant_account_id)` is unique | Connection writer verifies organization/brand/owner branch against Identity projection |
| Connection capability | One `(connection, option)` row | Writer verifies option provider equals connection provider |
| Attempt idempotency | One `(connection, operation, idempotency_key)` plus global provider request key | Same-key fingerprint mismatch is a typed conflict |
| Provider payment identity | One `(connection, environment, provider_object_id)`; null remains allowed | Snapshot provider/environment must equal the selected connection |
| Refund identity | One `(connection, idempotency_key)`, provider request key, and `(connection, environment, provider_refund_id)` | Fingerprint, attempt/connection/environment/currency equality are checked before dispatch |
| Provider event identity | One `(connection, environment, provider_event_id)` | Signature/account/provider/environment are verified before insert |
| Tenant existence/history | Required foreign keys exist; referenced financial scope is restrictive | Every related row must belong to the same organization/brand/branch |
| Refund capacity | Positive per-operation amount is represented in minor units | Under an attempt lock, accepted reservations plus the new amount must not exceed captured amount |
| Legacy method scope | One `(organization, scope_key, code)` where scope is DB-generated as `global` or branch UUID | Requests and generated caller payloads cannot supply it |

## Refund sum lock contract

T2.16 must lock the succeeded capture attempt first, then read all refund operations in money-moving
or succeeded states (`prepared`, `submitted`, `pending`, `reconciliation_required`, `succeeded`) in
the same transaction. It may reserve only:

```text
new amount <= captured amount - sum(existing accepted reservations)
```

`failed` and `canceled` operations do not consume capacity. A retry reuses its existing refund row
and never reserves again. Provider network calls occur after the reservation transaction commits.
Concurrent tests E5/E6 remain required when that writer is implemented; a database uniqueness key
alone cannot enforce an aggregate sum.

The repository test configuration sets `DB_FOREIGN_KEYS=false` for SQLite. T1.10 therefore verifies
the declared tenant/financial foreign-key metadata and `RESTRICT` actions directly instead of using
an insert/delete assertion that would produce a false result in this environment. Uniqueness tests
remain behavioral direct-insert tests.

## Environment contract

The database deliberately namespaces provider payment, refund, and event identities by connection
and environment, which permits the same sandbox/live provider ID without collision. The canonical
writer must still reject a row whose environment snapshot differs from its connection; allowing a
second namespace is not permission to cross environments.

## PaymentMethod nullable-scope remediation

`payment_methods.scope_key` removes SQL's multiple-NULL loophole from organization-global methods.
The Omnify schema owns the hidden, non-fillable stored generated column and deterministic unique
index. Omnify 5.9.8 emits `virtualAs` on SQLite and `storedAs` on other supported drivers for
`COALESCE(branch_id, 'global')`, so the database derives both legacy and future row values without
an application backfill or model event. Driver detection is resolved from the same default or named
Laravel Schema builder used by the migration.

The following unique-index creation is the duplicate preflight: conflicting legacy rows make the
migration fail without deleting or merging them. Generated ALTER guards preserve completed column,
key, and index operations, so operators resolve only the reported data conflict and retry the same
migration without manual schema repair. Generated PHP requests/models and TypeScript caller payloads
cannot write `scope_key`; serialization hides it.
