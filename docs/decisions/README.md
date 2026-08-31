---
title: Architecture decision records
category: contributing
tags: [architecture, adr, decision-record, governance]
summary: Where architecture decisions are recorded, the MADR format used, when a change needs an ADR instead of a doc edit, and the index of accepted records.
related: [module-boundaries, architecture-baseline]
---

# Architecture decision records

An ADR records **one decision, once, with the reasoning that made it** — so the
next person can tell a deliberate choice apart from an accident, and can reopen
it on the merits instead of re-deriving it.

Format is [MADR](https://adr.github.io/madr/) (Markdown ADR), the de-facto
standard: context → decision → consequences, with an explicit status. Files are
`NNNN-kebab-title.md`, numbered in order, **never renumbered**.

## When a change needs an ADR

An ADR is required when a change decides something that later code cannot easily
walk back:

- module boundaries, dependency direction, or what may cross them;
- how modules talk to each other (events, direct calls, shared tables);
- transaction ownership across a boundary;
- persistence topology (one database vs several, replicas, sharding);
- extracting anything out of the monolith into its own deployable.

An ADR is **not** required for a bug fix, a new endpoint that stays inside one
module, a refactor with no boundary effect, or anything already settled by an
accepted ADR. Those belong in the normal docs (`docs/explanation/…`,
`docs/guide/…`).

Rule of thumb: if the reasonable question six months from now is *"why is it
like this?"* rather than *"how does this work?"* — write an ADR.

## Statuses

| Status | Means |
|---|---|
| `Proposed` | Written, not agreed. Nothing in the codebase may rely on it yet. |
| `Accepted` | In force. Code that contradicts it is a defect in the code or an ADR that needs superseding. |
| `Superseded by NNNN` | Replaced. **The file stays** — the old reasoning is why the new decision exists. |
| `Deprecated` | No longer applies and nothing replaced it (the problem disappeared). |

Never delete or rewrite an accepted ADR to reflect a new decision. Write the new
one and mark the old one superseded; a decision log that gets edited is not a log.

## Index

| ADR | Status | Decides |
|---|---|---|
| [0001 — Modular monolith](0001-modular-monolith.md) | Accepted (2026-08-01) | Modular monolith over microservices; dependency direction; event + transaction policy; the test a module must pass before it may become its own service |
| [0002 — Đồng bộ danh tính Platform → Tempo](0002-platform-tempo-identity-sync.md) | Accepted (2026-08-17) | Thay "kéo lúc đăng nhập" bằng transactional outbox trên Platform + SNS/SQS, hợp đồng SCIM 2.0 (danh mục) và OpenID SSF/CAEP (thu hồi quyền); loại Kafka và broker tự dựng, kèm ba lớp chống sót đường ghi |
