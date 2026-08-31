---
title: Approval Workflow
category: explanation
tags: [approval, workflow, recipe, product, trait, audit, status]
summary: Explains the generic 4-state approval workflow (draft / pending / approved / rejected) shared by Recipe and Product via the HasApprovalWorkflow trait, including the two-tier re-approval rule on Recipe.
related: [product-workflow, allergen-data-model, authorization]
---

# Approval Workflow

This document explains the generic approval workflow shared by `Recipe` and `Product`, implemented as the `HasApprovalWorkflow` trait + `Approvable` contract introduced in plan-003.

## State machine

```text
[draft] ──submit──> [pending] ──approve──> [approved]
                       │
                       └──reject──> [rejected] ──resubmit──> [pending]
```

Only the four trait methods (`markAsPending`, `markAsApproved`, `markAsRejected`, plus `assertApprovalStatus` for the guard) may transition state. Direct DB writes bypass the audit log and the service-level guards — never do them in seeders, jobs, or controllers.

## Architecture

| Concern | Where |
|---|---|
| State machine primitive | `app/Concerns/HasApprovalWorkflow.php` |
| Contract | `app/Contracts/Approvable.php` |
| Normalized enum | `app/Omnify/Enums/ApprovalStatusEnum.php` (4 values: draft / pending / approved / rejected) |
| Status-mismatch exception | `app/Exceptions/InvalidStatusTransitionException.php` (renders 422 with `{from, action, allowed, subject}`) |
| Recipe service methods | `app/Services/Product/RecipeService.php::submitForApproval / approve / reject` |
| Product service methods | `app/Services/Product/ProductService.php::submitForApproval / approve / reject` (kept `activate` / `deactivate` as lifecycle-only, distinct from approval) |

## Why an interface, not just a trait?

Recipe stores its approval state in a dedicated `approval_status` column (the `ApprovalStatusEnum` directly). Product reuses its existing `status` column which carries a wider `ProductStatusEnum` (`draft` / `pending` / `approved` / `active` / `inactive` / `rejected`) — `active` and `inactive` are post-approval lifecycle states that the trait never touches.

Both models implement `Approvable::getApprovalStatus()` / `setApprovalStatus()` to project their own column onto the normalized 4-state enum. The trait operates against the normalized enum only.

Product's projection: `active` and `inactive` both project to `Approved` (you can only become active after passing approval, so the 4-state read sees them as the same approval bucket). `setApprovalStatus(Approved)` writes back as `Approved` — the `active/inactive` lifecycle transitions stay on `ProductService::activate` / `::deactivate`.

## Standardized columns (writable by the trait)

Both Recipe and Product carry the same five columns; the trait reads/writes them by name:

- `approved_by_id` — FK to `users.id`
- `approved_at` — datetime
- `rejected_by_id` — FK to `users.id`
- `rejected_at` — datetime
- `rejection_reason` — text (≤1000)

`markAsPending()` clears `rejected_by_id` and `rejected_at` but **preserves `rejection_reason`** for history. The reason is cleared only when the model is explicitly edited (the Recipe form clears it when submit-for-approval fires).

## "Cannot approve own submission"

Service-level guard, NOT a policy method. Lives in `RecipeService::approve` and `ProductService::approve`. The check compares `$model->created_by_id === $approver->getKey()` — when true, throws `\InvalidArgumentException` (rendered as 422 by Laravel's default exception handler with code `cannot_approve_own_submission`).

This is intentional: policies answer "are you allowed to call this method?" — not "is this specific transition legal?". A reviewer who can approve recipes in general should still be blocked from approving their own work; that's a business rule, not an authorization rule.

## Two-tier re-approval (Recipe only)

When an `approved` Recipe is updated, a structural-field change moves it back to `pending` automatically; non-structural changes leave the status alone.

| Field | Treatment |
|---|---|
| `ingredients` | Structural — auto-repend |
| `material_id` | Structural — auto-repend |
| `output_quantity` | Structural — auto-repend |
| `output_unit` | Structural — auto-repend |
| `description` | Non-structural — status preserved |
| `instructions` | Non-structural — status preserved |
| `preparation_time` | Non-structural — status preserved |
| `is_active` | Non-structural — status preserved |

Auto-repend writes an audit row `recipe.auto_repending` with `context.changed_fields: [...]`. The frontend Recipe detail page shows a warning banner before save — `showReapprovalWarning` in `web/admin/src/app/hq/[brandSlug]/recipes/[id]/page.tsx`, copy key `hq.recipes.alerts.reapproval_warning`: *"Changing the ingredients or output will send this recipe back to pending."* (The dialog-era `recipe-form-dialog.tsx` + `formState.dirtyFields` mechanism this paragraph used to describe is gone — the screen is route-based now.)

### Cross-trigger: Material allergen change

Updating a Material's `allergens` set recomputes the `allergen_rollup` cache on every downstream Recipe (Recipe.material_id or referenced via Recipe.ingredients[].material_id). If the rollup actually changes (non-empty delta), any `approved` downstream Recipe is auto-repended with audit row `recipe.auto_repending` carrying `context: {source: 'material_allergen_change', material_id: ...}`.

Empty-delta changes (e.g. adding an allergen that's already in the rollup via another upstream Material) do **not** trigger repend — explicit decision (DESIGN.md §Decision 4) to avoid approval fatigue.

## Audit events

Every transition writes a row via the existing `AuditsActivity` trait. Event names:

| Event | Context | Where |
|---|---|---|
| `recipe.submitted_for_approval` | `previous_status` | RecipeService::submitForApproval |
| `recipe.approved` | `approver_id` | RecipeService::approve |
| `recipe.rejected` | `approver_id`, `rejection_reason` | RecipeService::reject |
| `recipe.auto_repending` | `changed_fields[]` (structural edit) OR `source`, `material_id` (allergen change) | RecipeService::update / MaterialService::update |
| `submitted_for_approval` | (none) | ProductService::submitForApproval (legacy event name preserved for backward compat) |
| `approved` | `approved_by_id` | ProductService::approve |
| `rejected` | `rejected_by_id`, `rejection_reason` | ProductService::reject |

Audit rows are read via the polymorphic `audit_logs` table; the model lives at `app/Models/AuditLog.php` (created in plan-003 alongside this doc).

## Adding the trait to a new model

1. Add the five standard columns to the YAML schema (`approval_status: EnumRef ApprovalStatus default=draft nullable=true`, `approved_by/at`, `rejected_by/at`, `rejection_reason`).
2. `omnify generate --project api --force`.
3. Edit the user-editable model:
   ```php
   class MyModel extends MyModelBaseModel implements Approvable
   {
       use AuditsActivity;
       use HasApprovalWorkflow;

       public function getApprovalStatus(): ApprovalStatusEnum
       {
           return $this->approval_status instanceof ApprovalStatusEnum
               ? $this->approval_status
               : ApprovalStatusEnum::from($this->approval_status);
       }

       public function setApprovalStatus(ApprovalStatusEnum $status): void
       {
           $this->approval_status = $status->value;
       }
   }
   ```
4. In the service, write `submitForApproval / approve / reject` methods that call `assertApprovalStatus` then `markAs*`, and emit `logAudit(...)`.
5. In the policy, add `submitForApproval / approve / reject` methods (typically delegating to org membership for now; the project's role matrix is TODO).
6. Wire controller endpoints + routes following the Recipe pattern.
