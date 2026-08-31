# Normalized payment state machines and error contract

This is the implementation contract for Plan 047. Provider raw states are evidence stored beside
these normalized states; adapters cannot add a provider-specific normalized state. All transition
methods lock the state row, compare an expected version, append transition audit, and return the
current result on an idempotent replay.

## Common rules

1. `succeeded`, `failed`, `canceled`, and `expired` are terminal attempt states. Only a compensating
   operation such as refund may follow; the original attempt does not move backward.
2. `succeeded`, `failed`, and `canceled` are terminal refund states.
3. A terminal state can receive later provider evidence, but that evidence is recorded as ignored or
   conflict/reconciliation work. It never silently regresses state.
4. `reconciliation_required` means the provider outcome is unknown or local finalization is
   incomplete. It is not equivalent to failed and does not release money/reservation automatically.
5. A transition that creates a ledger or settlement effect uses a unique effect key and executes
   once in the same finalize transaction.
6. Provider calls always use the attempt/refund's immutable connection, environment, provider
   object, and request identity. Retry never resolves a new connection.
7. Every rejected transition returns a stable code and correlation ID and records no money effect.

## Payment attempt states

| State | Meaning | Money/ledger rule | Allowed initiator |
|---|---|---|---|
| `prepared` | Policy, ownership, money, and idempotency validated and immutable request persisted | No provider money and no success ledger | Orchestrator prepare transaction |
| `provider_pending` | Request dispatched/accepted but no further customer action is currently known | No success ledger; reservation remains | Adapter result/retrieval/event |
| `action_required` | Customer/device must complete a typed next action | No success ledger; next action is safe/displayable, never secret | Adapter result/retrieval/event |
| `processing` | Provider reports asynchronous processing | No success ledger; reconciliation timer active | Adapter result/retrieval/event |
| `reconciliation_required` | Remote outcome or local finalization is ambiguous/incomplete | Never assume failure; reservation remains until provider proof/risk timeout rule | Timeout, crash recovery, conflicting evidence, finalize failure |
| `succeeded` | Requested authorize/capture/payment operation completed | Append the operation's ledger/settlement effect exactly once when applicable | Verified adapter result/retrieval/event |
| `failed` | Provider or validation after prepare proves the operation failed and no money moved | No success ledger; reservation released | Verified adapter result/retrieval/event |
| `canceled` | Operation was safely canceled/voided or provider proves cancellation | No new success ledger; authorization release tracked separately | Orchestrator + supported provider result/event |
| `expired` | A locally prepared operation/provider authorization expired with proof it cannot settle | No success ledger; never used for an unknown provider outcome | Expiry reconciler with capability/provider proof |

### Legal attempt transitions

| From | To | Required evidence and effect |
|---|---|---|
| none | `prepared` | Unique `(connection, operation, idempotency_key)` and request fingerprint; policy/owner snapshot committed |
| `prepared` | `provider_pending` | Provider accepted/created identity or explicitly returned pending |
| `prepared` | `action_required` | Provider returned safe next-action type/data and provider identity |
| `prepared` | `processing` | Provider returned processing with retrievable identity |
| `prepared` | `succeeded` | Verified synchronous success; finalize unique ledger effect |
| `prepared` | `failed` | Definitive provider rejection before money movement |
| `prepared` | `canceled` | Command canceled before dispatch, or provider confirms cancel |
| `prepared` | `expired` | Never dispatched and local prepare validity elapsed, or provider proves expiry |
| `prepared` | `reconciliation_required` | Dispatch outcome unknown, response unusable, or process/finalize interruption |
| `provider_pending` | `action_required`, `processing`, `succeeded`, `failed`, `canceled`, `expired` | Verified retrieval/event allows transition; terminal transition applies one effect |
| `provider_pending` | `reconciliation_required` | Poll/event deadline exceeded or conflicting/unusable result |
| `action_required` | `provider_pending`, `processing`, `succeeded`, `failed`, `canceled`, `expired` | Same provider object after customer action/retrieval/event |
| `action_required` | `reconciliation_required` | Action callback/result ambiguous; do not create a new provider object |
| `processing` | `action_required`, `provider_pending`, `succeeded`, `failed`, `canceled`, `expired` | Verified provider lifecycle result |
| `processing` | `reconciliation_required` | Processing exceeds capability/SLO or retrieval fails ambiguously |
| `reconciliation_required` | `provider_pending`, `action_required`, `processing` | Retrieval proves current active state and refreshes reconciliation schedule |
| `reconciliation_required` | `succeeded`, `failed`, `canceled`, `expired` | Retrieval/verified event proves terminal state; success finalizes once |
| any state | same state | Same provider identity and compatible payload is an audited idempotent no-op |

Forbidden examples include terminal → active, failed/canceled/expired → succeeded, changing operation
or provider identity after prepare, and succeeding without verifiable provider evidence. Conflicting
terminal evidence moves an operator/reconciliation record to conflict; it does not overwrite the
attempt.

## Refund states

Each refund is append-only and owns its amount, provider request key, and provider refund identity.
The original captured attempt remains succeeded. Refund availability is calculated under lock as:

```text
refundable = captured minor amount
           - sum(refunds in submitted, pending, reconciliation_required, succeeded)
```

Failed/canceled refunds release their reservation. A provider-specific rule may keep a failed refund
reserved only when the raw outcome is ambiguous, in which case normalized state must be
`reconciliation_required`, not `failed`.

| From | To | Required evidence and effect |
|---|---|---|
| none | `prepared` | Amount positive and within locked refundable balance; stable key/fingerprint persisted |
| `prepared` | `submitted` | Request dispatched with original connection/environment and stable key |
| `prepared` | `failed` | Definitive local validation/provider rejection before acceptance |
| `prepared` | `canceled` | Canceled before dispatch |
| `prepared` | `reconciliation_required` | Dispatch result unknown |
| `submitted` | `pending` | Provider accepted and returned a retrievable refund identity |
| `submitted` | `succeeded` | Verified immediate success; append one negative/reversal ledger effect |
| `submitted` | `failed`, `canceled` | Provider proves terminal result; release reservation |
| `submitted` | `reconciliation_required` | Timeout/crash/unusable response after possible acceptance |
| `pending` | `succeeded` | Retrieval/event proves success; append reversal exactly once |
| `pending` | `failed`, `canceled` | Provider proves result; release reservation |
| `pending` | `reconciliation_required` | Poll deadline, missing event, or contradictory evidence |
| `reconciliation_required` | `submitted`, `pending` | Retrieval proves accepted active operation; never submit a fresh identity |
| `reconciliation_required` | `succeeded`, `failed`, `canceled` | Retrieval/verified event proves terminal outcome |
| any state | same state | Compatible replay is an audited no-op |

Cancel is offered only when the provider capability matrix says the exact refund operation supports
it. A partial refund never flips the original attempt away from succeeded and never consumes more
than its own minor amount.

## Provider event inbox states

Unverified requests are rejected and do not enter this state machine. Inbox identity is unique by
`(connection_id, environment, provider_event_id)`; raw-body hash detects an impossible same-ID,
different-payload conflict.

| From | To | Required evidence and effect |
|---|---|---|
| none | `received_verified` | Raw request verified against resolved connection secret/version; redacted payload/hash persisted |
| `received_verified` | `queued` | Durable job/outbox identity persisted; HTTP may acknowledge only after this commit |
| `queued` | `processing` | Worker claims with lease/attempt counter; overlapping worker cannot claim |
| `processing` | `succeeded` | Normalized command applied or intentionally ignored as stale/duplicate, outcome stored |
| `processing` | `retryable` | Typed transient error, bounded next-at/backoff and last error stored |
| `processing` | `dead_letter` | Non-retryable error or retry/age budget exhausted; alert/operator visibility created |
| `retryable` | `queued` | Due retry requeues using the same inbox/event identity |
| `retryable` | `dead_letter` | Budget exhausted before next processing claim |
| `dead_letter` | `queued` | Authorized operator retry with reason creates audit and same identity |
| `dead_letter` | `operator_resolved` | Authorized resolution records reason/evidence; no hidden ledger edit |
| any non-processing state | same state | Duplicate delivery increments delivery metadata and returns 2xx without another normalized effect |

`succeeded` and `operator_resolved` are terminal inbox states. An out-of-order event can complete
successfully with outcome `ignored_stale`; event processing success does not imply the referenced
payment changed state.

## Error response envelope

```json
{
  "message": "Localized safe message",
  "code": "PAYMENT_POLICY_STALE",
  "correlation_id": "01J...",
  "retryable": false,
  "action": "refresh_payment_options",
  "details": {}
}
```

`code`, `retryable`, and `action` drive clients. `message` is localized display text. `details` is an
allowlisted non-secret object; provider raw payload/error/credential is never returned. HTTP status
does not replace the code. Unknown internal errors return `PAYMENT_INTERNAL_ERROR`, a correlation ID,
and no exception/provider message.

## Stable typed error registry

| Code | HTTP | Retryable | Client/operator action | Trigger |
|---|---:|---:|---|---|
| `PAYMENT_AUTHENTICATION_REQUIRED` | 401 | no | `authenticate` | Missing/invalid user or device identity |
| `PAYMENT_FORBIDDEN` | 403 | no | `request_permission` | Actor lacks action permission in tenant |
| `PAYMENT_RESOURCE_NOT_FOUND` | 404 | no | `refresh` | Scoped order/connection/option/attempt not visible |
| `PAYMENT_OWNERSHIP_UNRESOLVED` | 409 | no | `contact_support` | Missing/ambiguous/invalid Identity management projection |
| `PAYMENT_CONNECTION_REQUIRED` | 422 | no | `configure_gateway` | Correct owner has no eligible ready connection |
| `PAYMENT_CONNECTION_UNAVAILABLE` | 503 | yes | `retry_or_choose_other` | Eligible connection temporarily degraded/unavailable |
| `PAYMENT_CONNECTION_RESTRICTED` | 422 | no | `complete_provider_action` | Provider/account restriction requires operator action |
| `PAYMENT_ENVIRONMENT_MISMATCH` | 409 | no | `contact_support` | Test/live identity or object crossed environment |
| `PAYMENT_OPTION_DISABLED` | 422 | no | `refresh_payment_options` | Option inactive or denied by effective policy |
| `PAYMENT_POLICY_CANNOT_WIDEN` | 409 | no | `refresh_payment_options` | Shop/device attempted to override upstream deny |
| `PAYMENT_POLICY_STALE` | 409 | no | `refresh_payment_options` | New operation submitted with unsafe stale revision |
| `PAYMENT_CURRENCY_UNSUPPORTED` | 422 | no | `choose_other_method` | Capability does not support order currency |
| `PAYMENT_CHANNEL_UNSUPPORTED` | 422 | no | `choose_other_method` | Capability does not support POS/Kiosk/Web channel/device |
| `PAYMENT_OPERATION_UNSUPPORTED` | 422 | no | `choose_other_method` | Provider option lacks authorize/capture/cancel/refund operation |
| `PAYMENT_INVALID_AMOUNT` | 422 | no | `correct_amount` | Non-positive, malformed, currency precision, or limit violation |
| `PAYMENT_AMOUNT_EXCEEDS_BALANCE` | 409 | no | `refresh_order` | Charge plus live reservations exceeds order remaining amount |
| `PAYMENT_REFUND_EXCEEDS_CAPTURED` | 409 | no | `refresh_payment` | Refund plus reserved refunds exceeds captured remaining amount |
| `PAYMENT_INVALID_STATE_TRANSITION` | 409 | no | `refresh_payment` | Command conflicts with current normalized state |
| `PAYMENT_IDEMPOTENCY_PAYLOAD_MISMATCH` | 409 | no | `use_new_command_id` | Same key reused with different canonical request fingerprint |
| `PAYMENT_PROVIDER_ACTION_REQUIRED` | 409 | no | `complete_provider_action` | Safe next action must be completed before success |
| `PAYMENT_PROVIDER_DECLINED` | 422 | conditional | `choose_other_method` | Definitive provider decline; retryability comes from mapped decline category |
| `PAYMENT_PROVIDER_TIMEOUT` | 202 | yes | `wait_for_reconciliation` | Provider outcome unknown; attempt/refund is reconciling |
| `PAYMENT_PROVIDER_UNAVAILABLE` | 503 | yes | `retry_later` | Provider proves request was not accepted or read-only operation transiently fails |
| `PAYMENT_RECONCILIATION_REQUIRED` | 202 | yes | `wait_for_reconciliation` | Ambiguous outcome or local finalize interruption |
| `PAYMENT_WEBHOOK_VERIFICATION_FAILED` | 400 | no | `none` | Signature/auth/timestamp/account verification fails |
| `PAYMENT_WEBHOOK_EVENT_CONFLICT` | 409 | no | `operator_review` | Same provider event ID has a different payload hash |
| `PAYMENT_SENSITIVE_DATA_REJECTED` | 422 | no | `remove_sensitive_data` | PAN/CVV-like raw data entered Tempo boundary |
| `PAYMENT_SECRET_RESOLUTION_FAILED` | 503 | no | `rotate_or_restore_secret` | Authorized connection secret missing/revoked/unreadable |
| `PAYMENT_LEDGER_CONFLICT` | 409 | no | `operator_reconcile` | Provider success conflicts with immutable ledger identity/amount |
| `PAYMENT_INTERNAL_ERROR` | 500 | yes | `contact_support` | Redacted unexpected error; never exposes provider/secret detail |

Provider adapters map raw codes into this registry and store the raw code separately. They may add
non-breaking `details` fields but cannot invent public error codes without updating this contract,
translations, OpenAPI schemas, client exhaustiveness tests, and observability dashboards.

## Transition test minimum

For every table row above, tests cover happy transition, exact persisted timestamps/version/audit,
same-command replay, and forbidden transition. Terminal-state tests inject late/out-of-order events.
Success tests assert one ledger/outbox/settlement effect. Unknown-outcome tests assert no blind new
provider identity. Error-contract tests snapshot HTTP/code/retry/action and run secret-redaction
checks on response, log, trace, job, and dead-letter representations.
