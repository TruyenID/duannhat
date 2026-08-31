---
title: Payments — where to start
category: guide
tags: [payments, index, gateway, tender, shift, settlement, hardware]
summary: Entry point for the money cluster — a "want to know X → read Y" table over the payment, tender, shift, settlement, gateway-certification and payment-hardware docs.
related:
  - guide/payment-topology-and-tender-model.md
  - guide/tender-configuration.md
  - guide/cashier-shift-recovery.md
  - guide/gateway-settlement.md
---

# Payments — where to start

Thirteen documents describe how money moves through TempoFast, and none of them
was the door. This page is the door: find your question on the left, open the one
document on the right. Read this page first and you will not have to open four
docs to discover which one you needed.

**The one distinction that explains the whole cluster:** a **gateway** is an
external processor that moves money (Stripe, PayPay) and can be reconciled
against a payout; a **tender** is what the shop tells the cashier to press
(cash, card terminal, PayPay, 電子マネー) and is what the 精算 report groups by.
One tender may or may not have a gateway behind it — cash has none, a card
terminal settles outside our ledger, Stripe has one. Start with
[payment topology](payment-topology-and-tender-model.md) if that sentence is new
to you.

## Taking money

| Want to know | Read |
|---|---|
| How gateway vs tender split works, POS cloud-only vs workstation-LAN topologies, where 釣銭機 fits | [payment-topology-and-tender-model.md](payment-topology-and-tender-model.md) |
| How the tender list is built: org vocabulary → per-terminal `accepts` → per-branch activation, and `order_payments.tender_key` attribution | [tender-configuration.md](tender-configuration.md) |
| Why a payment method is specific to ONE login terminal, and how that set reaches the device over Cloud or LAN | [device-and-payment-management.md](device-and-payment-management.md) |
| Whether a takeaway order must be paid before the kitchen starts (brand default + per-shop override), checkout email + phone validation | [takeaway-payment-policy.md](takeaway-payment-policy.md) |
| Konbini / 銀行振込 — the customer pays hours-to-days later, webhook closes the row (flag-gated OFF, lifecycle always armed) | [async-payment-methods.md](async-payment-methods.md) |
| PayPay dynamic QR on customer-web (JPY-only; **refunds are manual on the PayPay portal** in the pilot) | [paypay-customer-web-qr.md](paypay-customer-web-qr.md) |

## Cash drawer, shifts, reports

| Want to know | Read |
|---|---|
| What a manager sees for live shifts, historical reconciliation, Z-report printing, escalating a stuck shift | [manager-till-tracking.md](manager-till-tracking.md) |
| A shift is stuck — force-abandon vs scheduler-expire vs manual-settle, and the emergency overrides | [cashier-shift-recovery.md](cashier-shift-recovery.md) |
| Sổ quan sát máy 釣銭機 ở Cloud + đối soát BA CHÂN tiền mặt (sổ ↔ MÁY ↔ người đếm), ngưỡng lệch theo brand | [cash-device-observation.md](cash-device-observation.md) |
| The cashier-facing side of the same flow (open, sell, close 精算) in Vietnamese, non-technical | [van-hanh/pos-cai-dat-mo-ca.md](van-hanh/pos-cai-dat-mo-ca.md) · [van-hanh/pos-ket-ca-bao-cao.md](van-hanh/pos-ket-ca-bao-cao.md) |

## Reconciling with the processor

| Want to know | Read |
|---|---|
| Real per-transaction gateway fees, two-direction payout reconciliation, pending-payout aging (estimates are dashboard-only) | [gateway-settlement.md](gateway-settlement.md) |

## Certifying a gateway

| Want to know | Read |
|---|---|
| Cách thực sự bật thanh toán trên production — key nào, đổ ở đâu, tự kiểm ra sao, lùi thế nào | [payment-go-live.md](payment-go-live.md) |
| What "second-provider proof" looked like for PayPay OPA PreAuth & Capture (evidence for architecture review, not a go-live checklist) | [payment-gateway-paypay-certification.md](payment-gateway-paypay-certification.md) |

## Payment hardware

| Want to know | Read |
|---|---|
| Stripe Terminal smart readers (WisePOS) — Cloud creates the `card_present` intent and pushes it to the reader; OFF pending certification | [stripe-terminal-card-present.md](stripe-terminal-card-present.md) |
| Verifone P400 via VescaJS for pos-web — why the browser cannot open `ws://` and the workstation has to bridge it | [pos-card-terminal-p400-vesca.md](pos-card-terminal-p400-vesca.md) |
| 釣銭機 (Glory YRT-R08-MN) — HTTP/JSON adapter on the LAN, and why the machine's amounts are recorded locally only | [cash-changer-glory-adapter.md](cash-changer-glory-adapter.md) |

## Adjacent — money-shaped but not payment plumbing

| Want to know | Read |
|---|---|
| Consumption tax: brand-scoped tax types (標準 / 軽減 / 非課税), resolution tiers, 適格返還請求書 (赤伝) | [tax-types.md](tax-types.md) |
| Editing or voiding a sold item, and the stock consequence of voiding something already cooked | [item-edit-and-void-policy.md](item-edit-and-void-policy.md) |
| A workstation that sold while offline: signed evidence, Cloud re-pricing from a catalog revision | [offline-order-evidence.md](offline-order-evidence.md) |
| Per-country compliance (JP / VN profiles) and VN CQT e-invoice transmission | [compliance-profiles.md](compliance-profiles.md) |
