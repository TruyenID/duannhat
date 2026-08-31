# Kiosk Payment Flow — Idempotency, Polling, Auth Lifecycle

> Internal reference for engineers maintaining godx-kiosk payment surface.
> Last updated: 2026-06-01 (PR fix/payment-flow-critical-trio).

## 1. Idempotency-Key lifecycle

Backend dedupes `POST /api/v1/kiosk/payments` on `Idempotency-Key` header.
`OrderPaymentService:59-67` skips dedupe when the key is missing, and the SQL
unique constraint does not bind NULL — so a missing key means a network retry
that creates a second OrderPayment row, throwing off cash reconciliation.

**Where the key is minted:** `payment-method.tsx`'s `usePaymentMethodSubmit`
calls `newAttempt()` on `PaymentFlowProvider` BEFORE `router.push`. The
provider's reducer stores the key; all four payment screens (`card`, `qr`,
`emoney`, `cash`) read `paymentFlowState.idempotencyKey` and forward it as
`payload.idempotency_key` on every `submit()` call (and on retries within the
same screen — same key).

**When the key is rotated:**
- `NEW_ATTEMPT` action — fired by `newAttempt()`.
- `NEXT_PERSON` action — split/custom flow advances to next bill (`split/success.tsx`, `custom/success.tsx` call `nextPerson()`). The reducer clears the old key so the next `/payment/{method}` round starts fresh.

**Historical note:** `1330a85 feat(kiosk): mint fresh idempotency key when entering checkout` minted the key in the old `checkout.tsx` entry. Commit `52faaca feat(kiosk): add payment flow, split/custom payment...` introduced the new `payment-method.tsx` entry without wiring `newAttempt`. PR fix/payment-flow-critical-trio restores the wiring at the new entry-point and deletes the obsolete `scan.tsx` + `checkout.tsx` files.

## 2. Status polling lifecycle

`usePayment().checkStatus()` is a one-shot fetch. The hook also runs a
TanStack Query polling subscription against `/payments/{id}/status` with
`refetchInterval: 3000` that stops on `paid` or `failed`.

**Bounded by backend:** `ExpireStalePendingPayments` Artisan command (scheduled
`everyMinute()` in `routes/console.php`) flips any `pending` payment older than
15 minutes to `failed`. So polling is upper-bounded at ~16 minutes per payment
attempt — not infinite. Throttle is `30/min` per device, well under the 3s
cadence.

**Per-screen cleanup:** Each `/payment/{method}` screen calls `usePayment().reset()` in its unmount effect to cancel the active query subscription early, so a user who backs out mid-payment doesn't continue to poll in the background.

**UX timeout:** `<PaymentTimeoutBanner />` surfaces after 60 seconds in `pending` so customers don't think the kiosk hung. The banner suggests calling staff.

## 3. Auth (401) lifecycle

Device token has no TTL on backend (`AuthenticateDevice.php`); a 401 means the
device row was revoked (`status !== 'Active'`) or the token does not match a
device row at all. Kiosk has no self-revoke endpoint — recovery requires a new
pairing code from admin.

**Why we defer the React-side logout during payment flows:** the customer must
not be kicked to `/login` mid-terminal-capture. AuthProvider's `pendingLogout`
flag holds the logout until `pathname` leaves a payment-flow route
(`isInPaymentFlow` in `payment-flow-routes.ts`). The token itself is cleared
in `api.ts` at first 401 — subsequent requests will still 401 from backend
regardless of client state, so this is safe.

**Idempotent clearDeviceToken:** `api.ts` guards `clearDeviceToken` behind a
module-level flag so a burst of 401s (e.g., a stuck polling loop) only triggers
one Keychain write. The flag resets on `setDeviceToken` (next pairing).
Audit-log fire-and-forget POSTs pass `suppressUnauthorizedHandler: true` to
skip the cascade.

## 4. Test harness notes

- `@sentry/react-native` ships Flow-annotated JS that vitest's rolldown cannot
  parse. Any test transitively importing `src/lib/sentry.ts` must add
  `vi.mock('../lib/audit-log', () => ({ ... }))`. See `src/hooks/use-payment.test.ts:10`
  for the canonical pattern.
- React 19 strict rules (`react-hooks/set-state-in-effect`, `refs`,
  `immutability`) are demoted to warnings in `eslint.config.js` — payment-flow
  effects intentionally trigger them.

## 5. Related files

| Concern | File | Key reference |
|---|---|---|
| Idempotency key state | `src/providers/payment-flow-provider.tsx` | `NEW_ATTEMPT`, `NEXT_PERSON`, `RECORD_PAYMENT` |
| Key mint at entry | `app/payment-method.tsx` | `usePaymentMethodSubmit` |
| Payment hook | `src/hooks/use-payment.ts` | `submit`, `confirm`, `fail`, `checkStatus`, `reset` |
| API client | `src/lib/api.ts` | `apiFetch`, `clearDeviceToken` guard, `suppressUnauthorizedHandler` |
| Defer-logout policy | `src/lib/payment-flow-routes.ts` | `isInPaymentFlow` |
| Auth orchestration | `src/providers/auth-provider.tsx` | `pendingLogout` drain |
| Ghost-payment guard | `src/hooks/use-terminal-cancel.ts` | `execute()` post-cancel status re-check |
