---
title: Store operations handbook (workstation / POS / admin)
category: guide
tags: [setup, workstation, pos, admin-web, pairing, printer, shift, non-technical]
summary: The contents page of the operations handbook — 11 self-contained parts in guide/van-hanh/, split by reader role (opening a new store, admin, workstation and printers, POS, the other devices, maintenance, appendices).
related:
  - guide/van-hanh/rollout-quan-moi.md
  - guide/cashier-shift-recovery.md
  - guide/manager-till-tracking.md
  - guide/setup-kds-device.md
  - guide/tax-types.md
  - guide/setup-docker.md
---

# Store operations handbook

> **Who is this document for?**
> Store managers, cashiers and support staff — **no programming knowledge is
> required**.
> You only need to be able to use a computer, a web browser and a receipt printer.
>
> Sections marked 🔧 **FOR IT** are a technician's job; you can skip them.
>
> **Every figure, button name and error message in this handbook has been checked
> directly against the source code.** Where the source and older documents
> disagree, this handbook follows the source and records it in
> [Appendix C](van-hanh/phu-luc.md#appendix-c--known-pitfalls).

**This page is the contents.** The content lives in 11 files under
[`van-hanh/`](van-hanh/), each readable on its own — open the part you need instead
of loading the whole handbook. The order below is the order to read them in when
setting up a store from scratch.

---

## Contents

### PART I — overview and preparation

| Open | What is inside |
|---|---|
| [Overview and the new-store rollout](van-hanh/rollout-quan-moi.md) | §0 understand the system in five minutes · §1 what to prepare first · §2 the A-to-Z route for a new store |

### PART II — Admin (in a browser, for managers)

| Open | What is inside |
|---|---|
| [Signing in, the menu map, store settings](van-hanh/admin-cai-dat.md) | §3 signing in plus the full menu map · §4 store settings (6 tabs, card by card) |
| [Menus, zones/tables/QR, device pairing](van-hanh/admin-menu-ban-thiet-bi.md) | §5 the store's menu · §6 zones, tables and QR codes · §7 creating devices and pairing codes |
| [Shift monitoring](van-hanh/admin-giam-sat-ca.md) | §16 open shifts, cash discrepancies, stale shifts and the three exit doors |

### PART III — the workstation (the in-store machine)

| Open | What is inside |
|---|---|
| [The workstation and the printers](van-hanh/workstation-may-in.md) | §8 installing, first-run configuration and pairing · §9 printer setup |

### PART IV — the POS (the till)

| Open | What is inside |
|---|---|
| [Setup and opening a shift](van-hanh/pos-cai-dat-mo-ca.md) | §10 setting up the POS machine and checking the connection · §11 opening a shift |
| [Selling and taking payment](van-hanh/pos-ban-hang-thanh-toan.md) | §12 selling · §13 payment |
| [During the shift, closing it, and reports](van-hanh/pos-ket-ca-bao-cao.md) | §14 during the shift plus closing it (精算) · §15 reports on the POS itself |

### PART V — the other devices

| Open | What is inside |
|---|---|
| [KDS · kiosk · TMS · handheld · guest QR](van-hanh/kds-kiosk-tms-handy-qr.md) | §17 the kitchen display, the self-checkout kiosk, the table-management tablet, the handheld, and the guest web |

### PART VI — running it long term

| Open | What is inside |
|---|---|
| [Maintenance, troubleshooting, wall checklists](van-hanh/bao-tri-su-co.md) | §18 maintenance and backups · §19 troubleshooting · §20 checklists to print and pin up |

### Appendices

| Open | What is inside |
|---|---|
| [Appendices A-D](van-hanh/phu-luc.md) | A technical information for IT · B a quick reference of ports, URLs and environment variables · C the known pitfalls · D the glossary |

---

## Quick lookup

| What you need | Open |
|---|---|
| Opening a new store and not knowing where to start | [The A-to-Z route](van-hanh/rollout-quan-moi.md#2-new-store-rollout-a-to-z) |
| The POS cannot reach the workstation | [The lost-connection checklist](van-hanh/bao-tri-su-co.md#192-checklist-when-the-pos-loses-the-workstation) · [C1 mixed content](van-hanh/phu-luc.md#c1-mixed-content--the-https-pos-cannot-reach-the-http-workstation) |
| The printer produces no paper | [§9 printer setup](van-hanh/workstation-may-in.md#9-printer-setup) |
| Getting a 6-character pairing code for a device | [§7 creating and pairing devices](van-hanh/admin-menu-ban-thiet-bi.md#7-creating-and-pairing-devices) |
| A cash discrepancy at shift close | [§14 during the shift and closing it](van-hanh/pos-ket-ca-bao-cao.md#14-during-the-shift-and-closing-it) |
| A shift is stuck and will not close | [§16 shift monitoring — the three exit doors](van-hanh/admin-giam-sat-ca.md#16-admin--shift-monitoring) |
| Looking up a port, a URL or an environment variable | [Appendix B](van-hanh/phu-luc.md#appendix-b--quick-reference-ports-urls-environment-variables) |
| Not understanding a term | [Appendix D](van-hanh/phu-luc.md#appendix-d--glossary) |

---

## Related documents

- [Cashier Shift Recovery](cashier-shift-recovery.md) — the full business rules of the shift lifecycle
- [Manager Till Tracking](manager-till-tracking.md) — the shift monitoring screens in detail
- [Tax Types](tax-types.md) — the 軽減税率 / インボイス tax system
- [Setup KDS Device](setup-kds-device.md) — a dedicated guide for the kitchen display
- [Takeaway Payment Policy](takeaway-payment-policy.md) — the takeaway payment policy
- [Setup with Docker](setup-docker.md) — setting up a development environment
