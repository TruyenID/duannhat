---
title: POS card terminal — Verifone P400 via VescaJS (workstation bridge)
category: guide
tags: [payment, card, terminal, verifone, p400, vesca, sbps, workstation, pos]
summary: >
  Letting the POS (pos-web) use the Verifone P400 card reader (SBPS 対面) that is
  already integrated in the kiosk through VescaJS FullFeatured-WS. pos-web is an
  HTTPS browser app, so it CANNOT open a ws:// connection to the P400
  (mixed content) the way the native kiosk can. Solution A: workstation-app
  (Go+Wails) re-hosts the kiosk's own VescaJS bridge inside its webview and acts
  as a LAN bridge for pos-web. The backend is unchanged — still card
  pending→confirm.
related:
  - guide/payment-topology-and-tender-model.md
  - guide/cash-changer-glory-adapter.md
status: shipped (WS bridge + pos-web UI shipped — terminal_bridge.go, local_pos_card_terminal.go, the payment-dialog "Card swipe (P400)" button; still missing a hardware smoke test on real equipment, see #1051)
---

# POS card terminal — Verifone P400 via VescaJS

> The "SB payment" device at the counter is a **Verifone P400** driven through
> **VescaJS FullFeatured-WS** (not Stripe Terminal). The kiosk **is already
> integrated**; this document brings the same capability to the **POS (pos-web)**
> by making the **workstation** the bridge.

---

## 0. Settled facts (read first)

1. **The P400 is a client-driven LAN terminal**, NOT a server gateway. The client
   opens `ws://<p400-ip>:<port>` using the **VescaJS FullFeatured-WS v0.5** SDK
   (a Base64-framed JSON protocol with a state machine, polling, ACK/NAK and
   retry — precisely `app/kiosk/assets/vesca-bridge.html`). → Do **NOT** add an
   `SbpsPaymentGateway` to `gateway_drivers`.
2. **The backend is already done**: generic `card` **pending → confirm**. The
   kiosk proves it: `POST /kiosk/payments` (pending) → drive the P400 →
   `POST /kiosk/payments/{id}/confirm` (idempotent — the terminal already took the
   money). The workstation has `handleLocalConfirmPayment` too.
3. **The kiosk (a native app)** opens `ws://` to the P400 directly inside its
   WebView. **pos-web (an HTTPS browser app) CANNOT open `ws://`** (mixed
   content), so it needs a bridge.
4. **Solution A (chosen): the workstation is the bridge.** workstation-app is
   **Go+Wails (it has a webview)**, so it re-hosts `vesca-bridge.html` inside that
   webview (an HTTP/app:// context, which may open `ws://` to the P400) and
   pos-web calls the workstation over the LAN.

---

## 1. Architecture (HTTP relay, no Wails events needed)

workstation-app does **not** currently use Wails Go↔frontend events. Rather than
add that plumbing, this uses an **HTTP relay**: the workstation's webview is an
ordinary HTTP client talking to the workstation's own LAN server
(localhost:8080).

```text
pos-web (HTTPS browser)
   │  POST /api/v1/pos/terminal/charge {order_id}          (posAuth)
   ▼
┌──────────────────────── workstation-app (Go :8080) ────────────────────────┐
│  TerminalBridge (service): session + command queue + result                │
│    charge → create session {vescaRequest} → return session_id               │
│    ◄── frontend polls for a command ──   POST /api/terminal/next  (localOnly)│
│    ── frontend reports the result ──►    POST /api/terminal/result (localOnly)│
│    result OK → confirm the card payment (pending→confirm, sync UP)          │
│                                                                            │
│  pos-web polls:  GET /api/v1/pos/terminal/charge/{session}  (posAuth)       │
└───────────────────────────────┬────────────────────────────────────────────┘
                                │ (the workstation's own webview)
                                ▼
   workstation Wails frontend — a hidden <TerminalBridge> component
      • polls POST /api/terminal/next {driving} → receives {session, vescaRequest}
      • runs vesca-bridge.html (VescaJS) → ws://<p400>:<port>
      • POST /api/terminal/result {session, result | error}
                                │ ws://
                                ▼
                         Verifone P400 terminal
```

**Why an HTTP relay instead of Wails events:** it adds no Wails runtime dependency
to the Go code; the frontend is just an HTTP client (localOnly). It is testable
over plain HTTP.

**Serialization:** one P400 : one workstation : N POS terminals — the same shape
as the 釣銭機, one transaction at a time (a mutex inside TerminalBridge;
`ErrMachineBusy` for a second charge).

---

## 2. Reused from the kiosk (do not rewrite)

| Component | Source (kiosk) | Use in the workstation |
|---|---|---|
| VescaJS SDK + bridge | `app/kiosk/assets/vesca-bridge.html` | Copy into `workstation/frontend` assets, load in a hidden iframe |
| Request/response types | `app/kiosk/src/types/terminal.ts` | Port to the workstation frontend types plus Go |
| RN↔bridge message protocol | The kiosk design spec | Swap RN postMessage for iframe postMessage (same REQUEST/RESULT/ERROR/STATUS_EVENT shape) |
| The pending→confirm flow | `app/kiosk/src/hooks/use-payment.ts` | Workstation Go: create pending + confirm (the handler already exists) |

The real VescaJS request:
`AuthorizeSales {SequenceNumber, CurrentService: Credit|ElectronicMoney|QRCode, Amount}`.
Results: `OutputCompleteEvent` (success) / `ErrorEvent` (failure) /
`ResponseCode: S507…` (status).

---

## 3. The Go core — TerminalBridge (the new part, testable straight away)

Location: `internal/service/terminal_bridge.go`. It depends on neither Wails nor
hardware — the "emitter" (the frontend) only interacts through the command queue
and the result report.

```go
// Charge (async): create a session, queue one command for the frontend to pick up, return session_id.
func (b *TerminalBridge) Charge(orderID string, amount int, svc VescaService) (string, error)
// NextCommand: the frontend polls — take the waiting command (session + AuthorizeSales JSON).
func (b *TerminalBridge) NextCommand() (TerminalCommand, bool)
// Complete/Fail: the frontend reports the P400's result.
func (b *TerminalBridge) Complete(sessionID string, terminalData map[string]any) error
func (b *TerminalBridge) Fail(sessionID, reason string) error
// Snapshot: pos-web polls the state.
func (b *TerminalBridge) Snapshot(sessionID string) (TerminalSnapshot, bool)
// Cancel: pos-web cancels (queues a Cancel command for the frontend).
func (b *TerminalBridge) Cancel(sessionID string) error
```

- **Recording the payment**: on a successful `Complete`, the
  `CardPaymentRecorder` port (implemented in the handler, reusing create-pending
  plus `handleLocalConfirmPayment`) takes the card from `pending → confirm`, with
  metadata `capture_source: p400_vesca`, `terminal_response` =
  OutputCompleteEvent, and idempotency keyed on the terminal transaction id.
- **Session state machine**: `queued → processing → done{success|failed|canceled}`.
- Serialized with a mutex (there is one machine).

---

## 4. LAN endpoints

| Method | Path | Ring | Caller |
|---|---|---|---|
| POST | `/api/v1/pos/terminal/charge` `{order_id}` → `{session_id}` | posAuth | pos-web |
| GET | `/api/v1/pos/terminal/charge/{session}` → snapshot | posAuth | pos-web |
| POST | `/api/v1/pos/terminal/charge/{session}/cancel` | posAuth | pos-web |
| GET | `/api/v1/pos/terminal/current` → active snapshot or 204 | posAuth | pos-web |
| POST | `/api/v1/pos/terminal/abandon` → force-settle as `unknown` | posAuth | pos-web |
| POST | `/api/terminal/next` `{driving}` → `{session, request}` or 204 | **localOnly** | workstation frontend |
| POST | `/api/terminal/result` `{session, result?, error?}` | **localOnly** | workstation frontend |

`/api/terminal/next` is a **POST** because it mutates: it hands the pending
command out and moves the session to `processing`, so whoever calls it owns
driving the P400. As a GET it was a trap — Go's ServeMux routes HEAD to a GET
pattern too, so one probe, prefetch or diagnostic `curl` consumed a charge and
left the machine wedged with the terminal never rung. The body carries
`{"driving": "<session id>"}` — the session that caller is actually running, or
`""` when idle. That is the liveness signal the bridge expires against: a webview
that reloaded mid-transaction keeps polling but drives nothing, and must stop
vouching for a session it can no longer report on.

A session is settled by the bridge when nobody is driving it (60s unclaimed →
`canceled`; 90s of driver silence → `unknown`) or when a charge passes a 15-minute
ceiling → `unknown`. `unknown` is a distinct terminal status, never `declined`:
the card may already have been captured, so staff must read the P400 before
charging again. A result arriving after that still records the payment normally.

The amount is the order total (server-authoritative). 503 while no P400 is
configured.

---

## 5. Configuration

**The primary source is the Cloud peripheral device registry** (「機器連携」,
device linking), NOT `.env`. An admin registers a `type: payment_terminal` device
in Cloud with `metadata.host` (plus `metadata.port`, default 8888); it syncs DOWN
to each POS machine through `GET /api/v1/workstation/peripheral-devices` into the
`peripheral_devices` table (with its `metadata`). The workstation reads host and
port from there (`s.cardTerminalConfig()`: the most recent active
`payment_terminal` row). Swapping the P400 means editing Cloud, not touching the
POS machines.

`WS_APP_CARD_TERMINAL_HOST` plus `WS_APP_CARD_TERMINAL_PORT` are **only a dev
fallback** (for when no registry row exists yet). If both are empty, the
`/pos/terminal/*` endpoints return 503. `CurrentService` defaults to `Credit`;
E-Money and QR come later.

---

## 6. Testing

- **The Go core** (no hardware needed): a fake recorder plus a fake frontend
  (calling NextCommand→Complete): charge→queued→processing→done records the
  payment; busy returns 409; cancel; fail (records no payment). Serialization
  across N POS terminals.
- The frontend bridge component and vesca-bridge.html: a manual smoke test with a
  real P400.

---

## 7. Work in order

1. ✅ (this step) **The Go TerminalBridge core plus tests** — session/queue/complete/fail plus the recorder port.
2. The LAN endpoints (pos-web plus the localOnly frontend), wired into Server, plus the P400 configuration.
3. `CardPaymentRecorder` in the handler — create pending plus confirm (reusing `handleLocalConfirmPayment`).
4. **Workstation frontend**: copy `vesca-bridge.html` plus a hidden component that polls `/api/terminal/next` → runs VescaJS → `/api/terminal/result`.
5. **pos-web**: a "Card swipe (P400)" button → charge plus status polling plus the processing/approved/declined screens.

---

## 8. Open questions

1. Is the workstation frontend **always running** (the webview open)? If staff
   close the UI, the bridge dies. The bridge component must always be mounted
   (even with a hidden UI).
2. **PrintRetry / a stuck terminal** (LP0): the kiosk handles this
   (`terminal-connection-fix-plan`). The workstation needs to mirror that
   recovery.
3. E-Money and QR through the P400: after Credit.
4. If a shop **does not run a workstation** (cloud-only POS), the P400 is **not
   yet feasible** for the POS (a pos-web browser cannot open `ws://`) — a
   workstation is required, just like for the 釣銭機.
5. P400 card refunds and voids: through VescaJS (an inverse SubtractValue?) or
   through the SBPS server? — to be confirmed with SBPS and the contract.

---

## Reference files

| Topic | Path |
|---|---|
| VescaJS bridge (SDK) | `app/kiosk/assets/vesca-bridge.html` |
| VescaJS types | `app/kiosk/src/types/terminal.ts` |
| Kiosk terminal provider | `app/kiosk/src/providers/terminal-provider.tsx` |
| Kiosk pending→confirm | `app/kiosk/src/hooks/use-payment.ts` |
| App→P400 data flow | `app/kiosk/docs/data-flow-app-to-p400.md` |
| Workstation card confirm | `workstation/internal/handler/local_kiosk.go` (`handleLocalConfirmPayment`) |
| Payment topology | `docs/guide/payment-topology-and-tender-model.md` |
