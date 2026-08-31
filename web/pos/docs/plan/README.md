# pos-web Plans

Implementation plans cho các initiatives của pos-web. Mỗi file = 1 plan độc lập, sequenced theo dependency.

## Active Plans

| # | Plan | Status | Issue | Priority |
|---|------|--------|-------|----------|
| 01 | [Production hardening — Phase 1](./01-production-hardening-phase-1.md) | ✅ Shipped 2026-05-23 | [#284](https://github.com/godx-jp/godx-tempo/issues/284) | Critical/High |
| 02 | [Workstation integration — Demo](./02-workstation-integration-demo.md) | ✅ Shipped 2026-05-23 | [#286](https://github.com/godx-jp/godx-tempo/issues/286) + [ws#17](https://github.com/godx-jp/godx-tempo-workstation-app/issues/17) | High |

## Convention

- Filename: `{NN}-{kebab-case-topic}.md`
- Status: Draft → In Progress → Shipped → Archived
- Mỗi plan có Status section ở cuối, update khi tasks complete

## Reference patterns

Tham khảo plan style từ workstation: [`workstation/docs/plan/`](https://github.com/godx-jp/godx-tempo-workstation-app/tree/main/docs/plan) (repo cũ đã archive; nay in-tree tại `workstation/docs/plan/`).
