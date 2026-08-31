# Gate 1 schema validation evidence

Date: 2026-07-22
Omnify: 5.9.8
Scope: T1.1–T1.7 payment schemas and the modified `PaymentMethod`/`OrderPayment` schemas

## Results

| Validator | Result | Evidence |
| --- | --- | --- |
| Branch-local Omnify CLI | Pass | All 185 schemas valid; only the pre-existing unknown `default` connection warning |
| Named reference audit | Pass | All 26 unique Association/EnumRef targets resolve to a schema in this worktree |
| Omnify MCP, independently resolvable schemas | Pass | 11 shared enums, `PaymentPolicyRevision`, and `PaymentMethod` |
| Omnify MCP, new cross-schema graph | Context-limited | 10 schemas report only new target/EnumRef misses because MCP is indexed to the original worktree; no standalone schema errors remain |

MCP validation found and T1.8 corrected three issues that the graph-level CLI did not report:

- shortened the `OrderPayment` provider/environment snapshot index to a MySQL-safe explicit name;
- shortened the `PaymentGatewayConnectionOption` effective-window index to a MySQL-safe explicit name;
- removed ineffective `length` metadata from the inline `PaymentMethod.type` enum.

No association or EnumRef was weakened to accommodate MCP's stale project index. The complete
branch-local dependency graph remains the authoritative cross-schema result until the branch is
available to that index.

## Commands

```bash
pnpm exec omnify validate --verbose
pnpm exec omnify diff
```

The generated-artifact check and generation are intentionally deferred to T1.9.
