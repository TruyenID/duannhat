---
title: Branch isolation — the recorded decision
category: explanation
tags: [branch, authorization, policy, issue-904]
summary: "Recorded ruling that a branch is a data-isolation boundary — a role pivot with branch_id NULL grants org-wide, a set branch_id grants that branch only."
related: [notification-rules]
---

# Branch isolation — the recorded decision (#904)

**Decision (2026-07-27): (A) — branch IS a data-isolation boundary.**
A `role_user_pivots` row with `branch_id = NULL` grants the role **org-wide**
(every branch + HQ); a row with `branch_id` set grants it **for that branch
only**. A user whose only role is scoped to branch A must not read or mutate
branch B's data, and must not perform HQ (brand-level) mutations.

This was originally filed as a question because the codebase disagreed with
itself: `StockLevelPolicy` honoured branch scope while `ResolvesShopContext`
and the order policies checked org only. The disagreement was resolved **in
code** by `dc33b5b9` (2026-07-21, "enforce Platform branch assignments") —
this document records the intent so the next inconsistency is a bug by
definition, not a new design question.

## Where the boundary is enforced

| Layer | Enforcement |
|---|---|
| `ResolvesShopContext` (every `/shops/{slug}/…` route) | Pivot lookup requires `branch_id IS NULL OR branch_id = shop.id` → wrong-branch users 403 before any controller runs. Device tokens additionally require `device.branch_id === shop.id`. |
| `App\Services\Iam\UserWorkspaceAccess` | The centralised scope reader: `branches()` returns org-wide ∪ branch-assigned; `canAccessHeadquarters()` requires an org-wide pivot (`branch_id IS NULL`). HQ mutations (e.g. `EloquentProductPersistence`) gate on it. |
| Branch-aware policies (`StockLevelPolicy`, …) | `hasRoleInContext(role, org, branch)` — consistent with (A), not aspirational. |
| dxs-sso `HasSsoRoles` | Assign/check APIs model the branch dimension (`wherePivot('branch_id', …)` vs `wherePivotNull`). |
| `EloquentRoleAssignmentDirectory` (tầng thông báo) | Mọi truy vấn theo chi nhánh nhận CẢ dòng `branch_id IS NULL` của cùng tổ chức. Xem cảnh báo ngay dưới. |

Covered by `UserContextControllerTest`, `PlatformSsoIntegrationTest`, the
branch-assignment cases in `DashboardTest`, and
`AllBranchesAccessIsVisibleToRoleQueriesTest`.

> **Ruling này từng bị vi phạm ở tầng thông báo suốt một thời gian dài** (#2460).
> `userIdsWithRoleInBranch()` khớp `branch_id = X` chính xác, nên nó bỏ đúng
> những dòng `branch_id IS NULL` — tức bỏ những người quyền cao nhất, khỏi mọi
> audience theo chi nhánh. Cùng một dòng pivot được
> `userIdsWithRoleInOrganization()` coi là hợp lệ và
> `userIdsWithRoleInBranch()` coi là không tồn tại.
>
> Chính vì tài liệu này tồn tại mà nó là **lỗi theo định nghĩa**, không phải một
> câu hỏi thiết kế mới — đúng như đoạn mở đầu dự liệu. Khi viết một truy vấn mới
> theo chi nhánh, `branch_id IS NULL` nghĩa là **mọi chi nhánh**, không phải
> "không chi nhánh nào".

## The deliberate second-layer gap

Some policies (notably `CustomerOrderPolicy`) still check **org only**. On
shop routes this is not a hole — the middleware has already rejected
cross-branch access before the policy runs; the org check is a backstop.
It becomes a hole only if a route ever reads/mutates branch-scoped data
**without** passing through shop context or an HQ org-wide gate. No such
route is known today; hardening the org-only policies to also assert branch
scope (defence-in-depth) is tracked separately — see the follow-up issue
linked from #904. When adding a NEW route that touches branch data, route it
through `ResolvesShopContext` (or gate on `UserWorkspaceAccess`) — do not
rely on a policy alone.
