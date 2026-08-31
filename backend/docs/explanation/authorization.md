---
title: Authorization and Access Control
category: explanation
tags: [role, permission, policy, brand, shop, warehouse-member, org-admin, manager, staff, access-control, approval]
summary: Explains the authorization model -- authentication, brand-level vs shop-level roles, role-based access (org-admin, org-manager, warehouse manager/staff), and 35+ granular permissions across 12 functional groups -- including approval rules and access control matrices.
related: [sso-authentication, policy, stock-management, product-domain]
---

# Authorization and Access Control

> ⚠️ **Before writing or refactoring any policy**, read [Authorization Bug — Organization ID Space Mismatch](./org-id-space-bug.md). It documents a critical class of bugs that silently 403s every endpoint when policies compare the user's SSO shadow id against a model's local Organization PK. The hotfix landed 2026-04-14 and the doc lists every wrong + right pattern with examples.

This document explains the authorization model used across the system, including roles, permissions, approval rules, and access control matrices. Read this to understand how the system determines what each user can see and do.

## Overview

The authorization system operates on three layers:

1. **Authentication:** SSO or email/password login (see [SSO Authentication](../guide/sso-authentication.md))
2. **Role-based access:** Organization-level and warehouse-level roles define broad capabilities
3. **Permission-based access:** 35+ granular permissions across 12 functional groups control individual actions

---

## Roles

### Organization Roles

| Role | Description | Access |
| ---- | ----------- | ------ |
| `org-admin` | Organization administrator | Full access to all resources |
| `org-manager` | Organization manager | Create, edit, and approve. Cannot delete system configuration |

### Warehouse Roles

| Role | Description | Access |
| ---- | ----------- | ------ |
| `manager` | Warehouse manager | View, create, and approve warehouse documents. Eligible for auto-approve |
| `staff` | Warehouse staff | View and create warehouse documents. Cannot approve |

### Warehouse Access Rules

- Non-admin users only see warehouses they are assigned to (via `warehouse_members`)
- Org Admin users see all warehouses in the organization
- Users not assigned to any warehouse see no warehouse data

---

## Permission Groups

### Product Management

| Permission | Description |
| ---------- | ----------- |
| `products.view` | View product list and details |
| `products.create` | Create new products |
| `products.update` | Edit products |
| `products.delete` | Delete products |
| `products.approve` | Approve or reject products |
| `products.import` | Import products from CSV |
| `products.export` | Export products to CSV |

### Menu Management

| Permission | Description |
| ---------- | ----------- |
| `menus.view` | View menus |
| `menus.create` | Create menus |
| `menus.update` | Edit menus and manage menu items |
| `menus.delete` | Delete menus |
| `menus.approve` | Approve or reject menus |

#### Shop-side menu policy methods

The shop-side menu façade adds three methods to `MenuPolicy`. All three additionally require `menu.master_menu_id IS NOT NULL` (the menu must be a clone of an HQ master) and `menu.branch_id == resolved shop.id`:

| Policy method            | Allowed roles                          | Used by                                                              |
| ------------------------ | -------------------------------------- | -------------------------------------------------------------------- |
| `shopView`               | Any authenticated shop user (Staff+)   | List branch menus, show one menu, list items                         |
| `shopUpdateAvailability` | Shop Staff and above                   | Toggle one item or bulk-toggle many items between Available/Unavailable/OutOfStock |
| `shopUpdatePrice`        | Shop Manager and above                 | Override per-shop `selling_price`; reset to `master_price`            |

Shop Staff is intentionally allowed to toggle availability (it's the daily 86'd workflow) but **denied** for price changes — pricing is a managerial decision. See [API: Shop → Shop Menus](../reference/api-shop.md#5-shop-menus) for the corresponding endpoints.

### Material Management

| Permission | Description |
| ---------- | ----------- |
| `materials.view` | View materials |
| `materials.create` | Create materials |
| `materials.update` | Edit materials |
| `materials.delete` | Delete materials |

### Recipe Management

| Permission | Description |
| ---------- | ----------- |
| `recipes.view` | View recipes |
| `recipes.create` | Create recipes |
| `recipes.update` | Edit recipes |
| `recipes.delete` | Delete recipes |

### Warehouse Management

| Permission | Description |
| ---------- | ----------- |
| `warehouses.view` | View warehouses |
| `warehouses.create` | Create warehouses (Org Admin only) |
| `warehouses.update` | Edit warehouse settings (Org Admin only) |
| `warehouses.delete` | Delete warehouses (Org Admin only) |

### Stock Transactions

| Permission | Description |
| ---------- | ----------- |
| `stock-transactions.view` | View stock transaction documents |
| `stock-transactions.create` | Create stock transaction documents |
| `stock-transactions.approve` | Approve stock transactions (Manager/Admin) |
| `stock-transactions.cancel` | Cancel stock transactions |

### Stock Transfers

| Permission | Description |
| ---------- | ----------- |
| `stock-transfers.view` | View stock transfer documents |
| `stock-transfers.create` | Create stock transfer documents |
| `stock-transfers.approve` | Approve stock transfers (Manager/Admin) |
| `stock-transfers.receive` | Confirm goods receipt (Manager/Admin) |
| `stock-transfers.cancel` | Cancel stock transfers |

### Stock Counts

| Permission | Description |
| ---------- | ----------- |
| `stock-counts.view` | View stock counts |
| `stock-counts.create` | Create stock count sessions |
| `stock-counts.approve` | Approve stock counts (Org Admin only) |

### Disposals

| Permission | Description |
| ---------- | ----------- |
| `disposals.view` | View disposal records |
| `disposals.create` | Create disposal documents |
| `disposals.approve` | Approve disposals (threshold check applies) |
| `disposals.waste-report` | View waste reports |

### Production

| Permission | Description |
| ---------- | ----------- |
| `production-orders.view` | View production orders |
| `production-orders.create` | Create production orders |
| `production-orders.approve` | Approve production orders |

### Settings and Access

| Permission | Description |
| ---------- | ----------- |
| `settings.view` | View system settings |
| `settings.update` | Edit system settings |
| `access.view` | View users, roles, and permissions |
| `access.manage` | Manage users, roles, and permissions |

---

## Permission Matrices by Operation

### Stock Transactions

| Operation | Staff (member) | Manager | Org Admin |
| --------- | -------------- | ------- | --------- |
| View | Yes (assigned warehouses) | Yes (assigned warehouses) | Yes (all warehouses) |
| Create | Yes | Yes | Yes |
| Edit (draft) | Yes | Yes | Yes |
| Submit | Yes | Yes | Yes |
| Approve | No | Yes | Yes |
| Cancel | Yes (draft only) | Yes | Yes |

### Stock Transfers

| Operation | Staff | Manager | Org Admin |
| --------- | ----- | ------- | --------- |
| Create | Yes | Yes | Yes |
| Approve (to InTransit) | No | Yes | Yes |
| Receive goods | No | Yes | Yes |
| Cancel | Yes (draft only) | Yes | Yes |

### Stock Counts

| Operation | Staff | Manager | Org Admin |
| --------- | ----- | ------- | --------- |
| Create | Yes | Yes | Yes |
| Start counting | Yes | Yes | Yes |
| Submit | Yes | Yes | Yes |
| Approve | No | No | Yes |

### Disposals

| Operation | Staff | Manager | Org Admin |
| --------- | ----- | ------- | --------- |
| Create | Yes | Yes | Yes |
| Approve (below threshold) | No | Yes | Yes |
| Approve (above threshold) | No | No | Yes |
| View waste report | No | Yes (assigned warehouses) | Yes (all warehouses) |

---

## Special Approval Rules

### Self-Approval Prohibition

- The creator of a product cannot approve that same product
- The creator of a menu cannot approve that same menu
- Enforced by the constraint `approved_by_id != created_by_id`

### Disposal Approval Threshold

When a warehouse has a `disposal_approval_threshold` configured:

- A Manager can approve disposals where the total value is at or below the threshold
- Disposals exceeding the threshold require Org Admin approval
- If the threshold is `null`, no value limit applies (standard role-based rules apply)

### Auto-Approve

A stock transaction is auto-approved when all of the following conditions are met:

1. The warehouse has the corresponding auto-approve flag enabled (e.g., `auto_approve_stock_in = true`)
2. The creator has the `manager` or `org-admin` role
3. Sufficient stock is available (for outbound transactions)

If any condition is not met, the document remains in `draft` or `pending` status.

---

## Brand-Level vs Shop-Level Roles

The authorization model distinguishes between Brand-level and Shop-level responsibilities. This determines which features a user can access depending on their organizational position.

### Brand HQ Users

Brand HQ users operate at the Brand level. They manage the product catalog and content that applies across all shops within the brand:

| Responsibility | Examples |
| -------------- | -------- |
| Product management | Create, edit, approve products, options, and SKUs |
| Master menu management | Create master menus, define base prices |
| Recipe and material management | Define recipes and material compositions |
| Category management | Organize product categories |
| Menu approval | Approve shop-created menus before activation |

### Shop Users

Shop users operate at the Branch (Shop) level. They manage day-to-day operations within their assigned shop:

| Responsibility | Examples |
| -------------- | -------- |
| Stock management | Create stock-in/out transactions, transfers, counts |
| Menu availability | Toggle menu item availability (Available, Unavailable, OutOfStock) |
| Branch menu customization | Override prices on cloned branch menus |
| Disposal management | Create and submit disposal records |
| Warehouse operations | Manage warehouse members, receive transfers |

### Menu Approval Flow Between Brand and Shop

Shops can create their own branch menus, but activation requires Brand-level approval:

```text
Shop creates branch menu (draft) --> Shop submits for approval --> Brand HQ approves --> Shop activates
```

This ensures brand consistency while allowing shops to adapt to local demand.

---

## Service API Key

The system supports API access from external services through the `ValidateServiceApiKey` middleware. External services send an API key as a Bearer token, and the system authenticates the request and grants access to designated endpoints.
