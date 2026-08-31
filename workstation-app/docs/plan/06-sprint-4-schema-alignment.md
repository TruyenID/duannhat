# Sprint 4 — Schema Alignment Workstation ↔ Cloud

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** Align workstation `orders` + `order_items` schema với cloud `customer_orders` + `customer_order_items` để Sync DOWN (Recover) và Sync UP đều là JSON marshal/unmarshal trực tiếp, không cần transformer layer. Sau Sprint 4 → schema drift cloud↔workstation = 0, mọi field cloud gửi xuống đều persist được local.

**Architecture:** Workstation = single-writer local replica. Cloud = canonical source of truth. Schema align về cloud (vì cloud được generate từ Omnify YAML — canonical). Workstation-specific extensions (`printer_group`, `order_number` cho offline display, `synced_at` cho push tracking) giữ riêng nhưng đặt sau core fields.

**Scope decisions:**
- **Align**: order_code, order_type, status enum, all amount fields (subtotal, discount_amount, service_charge, tax_amount, total_amount, paid_amount, total_tip), timing (opened_at, checkout_at, closed_at, voided_at), guest_count, note, customer_takeaway_*, table_id, organization_id, brand_id, branch_id
- **Skip cho v1.0**: stripe_payment_intent_id, coupon_*, applied_promotion_*, topping_subtotal, stock_out_transaction_id, scheduled_pickup_time, pickup_type — workstation pilot không có flow này, thêm trong Sprint 5+
- **Workstation extension**: printer_group + status (item-level), printed_at, menu_item_name (denormalized cho offline)

**Tech Stack:** Go 1.25 / SQLite (workstation), PHP 8.4 / Laravel 13 / Pest 4 (backend). Không thêm dependency.

**Sprint scope:** 2 dev × 2 ngày HOẶC 3 dev × 1.5 ngày parallel (~21h tổng).

**Định nghĩa "done":**
- E2E roundtrip: workstation tạo order local → push UP → cloud nhận đúng schema → recover DOWN → local rebuild identical row (modulo workstation-only fields)
- `go test -race ./...` xanh
- Pest backend xanh
- DEMO flow Section 3 vẫn work (cloud-paired demo)

---

## Background — Why this sprint

Sprint 2 ship được Recovery flow nhưng:
- Em hardcode `order_number=0` → UI hiển thị "#000"
- Em không pull `order_code` → no display label
- Em không pull `items` array → order_items rỗng → UI "0 items"
- Status enum khác cloud → mapping ad-hoc trong code
- Field naming khác → cần transformer code mỗi lần sync

Sprint 4 fix root cause: **schema align triệt để**, không patch incrementally.

---

## File Structure

### Workstation Go (workstation-app/)

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `internal/store/migrations/007_align_orders_schema.sql` | **NEW** | Migration: rename + add columns trên orders, order_items |
| `internal/service/order_service.go` | **MODIFY** | Refactor Order, Item structs để mirror cloud naming |
| `internal/service/order_status.go` | **NEW** (or inline) | Status enum aligned: pending, open, dining, checkout, paying, closed, voided |
| `internal/service/sync_pull.go` | **MODIFY** | `Recover()` direct unmarshal Cloud JSON → Order (no transformer) |
| `internal/service/sync_service.go` | **MODIFY** | `handleOrderCreate` push UP với new schema |
| `internal/handler/routes.go` | **MODIFY** | handleCreateOrder + handleUpdateOrder + handleRecordPayment + display helpers |
| `internal/service/order_code_gen.go` | **NEW** | Local order_code generator: format `WS-{deviceID-prefix}-{YYYYMMDD}-{counter}` |
| `internal/service/order_service_roundtrip_test.go` | **NEW** | E2E roundtrip: create → push → recover → assert equal |

### Backend Laravel (backend/)

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `app/Http/Controllers/Api/V1/Workstation/OrderController.php` | **MODIFY** | `store()` validate aligned schema; `index()` return aligned shape |
| `tests/Feature/Workstation/WorkstationOrdersRoundtripTest.php` | **NEW** | Verify Pest: tạo qua POST → GET trả về identical |

### Frontend React (workstation-app/frontend/)

| File | Trạng thái | Trách nhiệm |
|---|---|---|
| `src/lib/api.ts` | **MODIFY** | Update Order, OrderItem TS types |
| `src/pages/Orders.tsx` | **MODIFY** | Display order_code (fallback order_number), items count, amounts |
| `src/pages/OrderDetail.tsx` (nếu có) | **MODIFY** | Show full order shape |

---

## Schema Mapping Reference

### orders table (after migration 007)

| Workstation col (after) | Cloud equivalent | Type | Notes |
|---|---|---|---|
| `id` | `id` | TEXT PK | UUID, same both |
| `cloud_id` | (n/a) | TEXT | Workstation-only: stores cloud's id after first push |
| `order_code` | `order_code` | TEXT | "ORD-2026-0007" or "WS-A1B2-20260521-001" |
| `order_number` | (n/a) | INTEGER | Workstation-only: sequential local display |
| `order_type` | `order_type` | TEXT | spot / dine_in / takeaway |
| `status` | `status` | TEXT | Aligned enum: pending/open/dining/checkout/paying/closed/voided |
| `table_id` | `table_id` | TEXT NULL | UUID FK to tables (cloud canonical) |
| `table_number` | (derived) | TEXT NULL | Workstation-only: display label from tables.code |
| `guest_count` | `guest_count` | INTEGER NULL | Renamed from customer_count |
| `note` | `note` | TEXT NULL | Renamed from notes (singular) |
| `customer_takeaway_name` | `customer_takeaway_name` | TEXT NULL | NEW |
| `customer_takeaway_phone` | `customer_takeaway_phone` | TEXT NULL | NEW |
| `subtotal` | `subtotal` | INTEGER | Yen integer (no decimals) |
| `discount_amount` | `discount_amount` | INTEGER | NEW, default 0 |
| `service_charge` | `service_charge` | INTEGER | NEW, default 0 |
| `tax_amount` | `tax_amount` | INTEGER | Renamed from tax |
| `total_tip` | `total_tip` | INTEGER | NEW, default 0 |
| `total_amount` | `total_amount` | INTEGER | Renamed from total |
| `paid_amount` | `paid_amount` | INTEGER | NEW, default 0 |
| `payment_method` | (n/a) | TEXT NULL | Workstation-only: cash/card/stripe |
| `opened_at` | `opened_at` | TEXT | Renamed from created_at semantics |
| `checkout_at` | `checkout_at` | TEXT NULL | NEW |
| `closed_at` | `closed_at` | TEXT NULL | Renamed from paid_at + cancelled paid |
| `voided_at` | `voided_at` | TEXT NULL | NEW |
| `void_reason` | `void_reason` | TEXT NULL | NEW |
| `organization_id` | `organization_id` | TEXT | NEW (for filter context) |
| `brand_id` | `brand_id` | TEXT | NEW |
| `branch_id` | `branch_id` | TEXT | NEW |
| `created_at` | `created_at` | TEXT | Standard |
| `updated_at` | `updated_at` | TEXT | Standard |
| `synced_at` | (n/a) | TEXT NULL | Workstation-only: push tracking |

### order_items table (after migration 007)

| Workstation col (after) | Cloud equivalent | Type | Notes |
|---|---|---|---|
| `id` | `id` | TEXT PK | UUID |
| `customer_order_id` | `customer_order_id` | TEXT | Renamed from order_id (FK to orders) |
| `product_sku_id` | `product_sku_id` | TEXT NULL | NEW (cloud's reference) |
| `menu_item_id` | (n/a) | TEXT NULL | Workstation-only: kept for offline-created |
| `menu_item_name` | (denormalized) | TEXT | Workstation-only: cached for display offline |
| `quantity` | `quantity` | INTEGER | Yen-style integer for v1 (no fractional) |
| `unit_price` | `unit_price` | INTEGER | |
| `subtotal` | `subtotal` | INTEGER | NEW (quantity * unit_price + adjustments) |
| `status` | `status` | TEXT | Aligned: pending/sent_to_kitchen/preparing/ready/served/voided |
| `served_at` | `served_at` | TEXT NULL | Renamed |
| `voided_at` | `voided_at` | TEXT NULL | NEW |
| `void_reason` | `void_reason` | TEXT NULL | NEW |
| `note` | `note` | TEXT NULL | Renamed from notes |
| `printer_group` | (n/a) | TEXT | Workstation-only: kitchen/bar routing |
| `printed_at` | (n/a) | TEXT NULL | Workstation-only |
| `created_at` | `created_at` | TEXT | Standard |
| `updated_at` | `updated_at` | TEXT | NEW |

### Status enum alignment

| Old workstation | New aligned (cloud) | Migration logic |
|---|---|---|
| `open` | `open` | no-op |
| `preparing` | `dining` | rename in migration |
| `ready` | `dining` | merged with dining |
| `served` | `dining` | merged with dining |
| `paid` | `closed` | rename + set closed_at = old paid_at |
| `cancelled` | `voided` | rename + set voided_at = updated_at |

**Item status**: Workstation's `pending/sent_to_kitchen/preparing/ready/served/cancelled` → aligned `pending/sent_to_kitchen/preparing/ready/served/voided` (rename cancelled→voided).

---

## Task 1 — Migration 007: schema alignment (P0, ~3h)

**Files:** Create `internal/store/migrations/007_align_orders_schema.sql`

### Step 1.1 — Write migration

```sql
-- Sprint 4: Align workstation orders schema with cloud customer_orders.
-- Strategy: ALTER existing tables (SQLite needs CREATE-COPY-DROP-RENAME pattern
-- because SQLite < 3.25 ALTER TABLE is limited). modernc.org/sqlite is recent
-- enough but we still need to refactor table to add NOT NULL columns with
-- meaningful defaults for existing rows.

-- 1. Orders table refactor
PRAGMA foreign_keys = OFF;

CREATE TABLE orders_new (
    -- Identity
    id                       TEXT PRIMARY KEY,
    cloud_id                 TEXT,
    order_code               TEXT NOT NULL DEFAULT '',
    order_number             INTEGER NOT NULL DEFAULT 0,
    order_type               TEXT NOT NULL DEFAULT 'spot',

    -- Status + timing
    status                   TEXT NOT NULL DEFAULT 'open',
    opened_at                TEXT NOT NULL DEFAULT (datetime('now')),
    checkout_at              TEXT,
    closed_at                TEXT,
    voided_at                TEXT,
    void_reason              TEXT,

    -- Customer / table context
    table_id                 TEXT,
    table_number             TEXT,
    guest_count              INTEGER DEFAULT 1,
    customer_takeaway_name   TEXT,
    customer_takeaway_phone  TEXT,
    note                     TEXT,

    -- Amounts (yen integer)
    subtotal                 INTEGER NOT NULL DEFAULT 0,
    discount_amount          INTEGER NOT NULL DEFAULT 0,
    service_charge           INTEGER NOT NULL DEFAULT 0,
    tax_amount               INTEGER NOT NULL DEFAULT 0,
    total_tip                INTEGER NOT NULL DEFAULT 0,
    total_amount             INTEGER NOT NULL DEFAULT 0,
    paid_amount              INTEGER NOT NULL DEFAULT 0,
    payment_method           TEXT,

    -- Tenancy
    organization_id          TEXT NOT NULL DEFAULT '',
    brand_id                 TEXT NOT NULL DEFAULT '',
    branch_id                TEXT NOT NULL DEFAULT '',

    -- Sync tracking
    created_at               TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at               TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at                TEXT
);

-- Migrate existing data with status enum translation.
INSERT INTO orders_new (
    id, cloud_id, order_code, order_number, order_type,
    status,
    opened_at, closed_at, voided_at,
    table_number, guest_count, note,
    subtotal, tax_amount, total_amount,
    payment_method,
    created_at, updated_at, synced_at
)
SELECT
    id,
    cloud_id,
    CASE
        WHEN order_number > 0 THEN 'WS-' || printf('%06d', order_number)
        ELSE ''
    END AS order_code,
    order_number,
    CASE
        WHEN table_number IS NOT NULL AND table_number != '' THEN 'dine_in'
        ELSE 'takeaway'
    END AS order_type,
    -- Status enum translation
    CASE status
        WHEN 'preparing' THEN 'dining'
        WHEN 'ready'     THEN 'dining'
        WHEN 'served'    THEN 'dining'
        WHEN 'paid'      THEN 'closed'
        WHEN 'cancelled' THEN 'voided'
        ELSE status
    END AS status,
    created_at AS opened_at,
    CASE WHEN status = 'paid'      THEN COALESCE(paid_at, updated_at) END AS closed_at,
    CASE WHEN status = 'cancelled' THEN updated_at END AS voided_at,
    table_number,
    customer_count AS guest_count,
    notes AS note,
    subtotal,
    tax AS tax_amount,
    total AS total_amount,
    payment_method,
    created_at, updated_at, synced_at
FROM orders;

DROP TABLE orders;
ALTER TABLE orders_new RENAME TO orders;

-- Reindex
CREATE INDEX IF NOT EXISTS idx_orders_status     ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_branch     ON orders(branch_id);
CREATE INDEX IF NOT EXISTS idx_orders_opened     ON orders(opened_at);
CREATE INDEX IF NOT EXISTS idx_orders_synced     ON orders(synced_at);
CREATE INDEX IF NOT EXISTS idx_orders_order_code ON orders(order_code);

-- 2. Order_items refactor
CREATE TABLE order_items_new (
    id                  TEXT PRIMARY KEY,
    customer_order_id   TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_sku_id      TEXT,
    menu_item_id        TEXT,
    menu_item_name      TEXT NOT NULL DEFAULT '',
    quantity            INTEGER NOT NULL DEFAULT 1,
    unit_price          INTEGER NOT NULL DEFAULT 0,
    subtotal            INTEGER NOT NULL DEFAULT 0,
    note                TEXT,
    printer_group       TEXT NOT NULL DEFAULT 'kitchen',
    status              TEXT NOT NULL DEFAULT 'pending',
    served_at           TEXT,
    voided_at           TEXT,
    void_reason         TEXT,
    printed_at          TEXT,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO order_items_new (
    id, customer_order_id, menu_item_id, menu_item_name,
    quantity, unit_price, subtotal, note, printer_group,
    status, printed_at, created_at
)
SELECT
    id,
    order_id AS customer_order_id,
    menu_item_id,
    menu_item_name,
    quantity,
    unit_price,
    quantity * unit_price AS subtotal,
    notes AS note,
    printer_group,
    CASE status
        WHEN 'cancelled' THEN 'voided'
        ELSE status
    END AS status,
    printed_at,
    created_at
FROM order_items;

DROP TABLE order_items;
ALTER TABLE order_items_new RENAME TO order_items;

CREATE INDEX IF NOT EXISTS idx_order_items_order  ON order_items(customer_order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_status ON order_items(status);

PRAGMA foreign_keys = ON;
```

### Step 1.2 — Test migration

```bash
go test -race ./internal/store/... -v
# Migration runner should apply 007 cleanly on fresh DB and existing
# Sprint 1-3 DBs. Any FAIL → rollback design.
```

### Step 1.3 — Commit

```bash
git add internal/store/migrations/007_align_orders_schema.sql
git commit -m "feat(schema): Migration 007 — align orders + order_items với cloud schema"
```

---

## Task 2 — Refactor Order/Item Go structs (P0, ~2h)

**Files:** Modify `internal/service/order_service.go`

### Step 2.1 — New struct shapes

```go
type Order struct {
    // Identity
    ID          string    `json:"id"`
    CloudID     string    `json:"cloud_id,omitempty"`
    OrderCode   string    `json:"order_code"`
    OrderNumber int       `json:"order_number"`
    OrderType   string    `json:"order_type"`

    // Status + timing
    Status      Status     `json:"status"`
    OpenedAt    time.Time  `json:"opened_at"`
    CheckoutAt  *time.Time `json:"checkout_at,omitempty"`
    ClosedAt    *time.Time `json:"closed_at,omitempty"`
    VoidedAt    *time.Time `json:"voided_at,omitempty"`
    VoidReason  string     `json:"void_reason,omitempty"`

    // Customer / table context
    TableID                 string  `json:"table_id,omitempty"`
    TableNumber             string  `json:"table_number,omitempty"`
    GuestCount              int     `json:"guest_count"`
    CustomerTakeawayName    string  `json:"customer_takeaway_name,omitempty"`
    CustomerTakeawayPhone   string  `json:"customer_takeaway_phone,omitempty"`
    Note                    string  `json:"note,omitempty"`

    // Amounts
    Subtotal       int `json:"subtotal"`
    DiscountAmount int `json:"discount_amount"`
    ServiceCharge  int `json:"service_charge"`
    TaxAmount      int `json:"tax_amount"`
    TotalTip       int `json:"total_tip"`
    TotalAmount    int `json:"total_amount"`
    PaidAmount     int `json:"paid_amount"`
    PaymentMethod  string `json:"payment_method,omitempty"`

    // Tenancy
    OrganizationID string `json:"organization_id"`
    BrandID        string `json:"brand_id"`
    BranchID       string `json:"branch_id"`

    // Tracking
    CreatedAt time.Time  `json:"created_at"`
    UpdatedAt time.Time  `json:"updated_at"`
    SyncedAt  *time.Time `json:"synced_at,omitempty"`

    // Nested
    Items []Item `json:"items"`
}

type Item struct {
    ID              string `json:"id"`
    CustomerOrderID string `json:"customer_order_id"`
    ProductSkuID    string `json:"product_sku_id,omitempty"`
    MenuItemID      string `json:"menu_item_id,omitempty"`
    MenuItemName    string `json:"menu_item_name"`
    Quantity        int    `json:"quantity"`
    UnitPrice       int    `json:"unit_price"`
    Subtotal        int    `json:"subtotal"`
    Note            string `json:"note,omitempty"`
    PrinterGroup    string `json:"printer_group"`
    Status          ItemStatus `json:"status"`
    ServedAt        *time.Time `json:"served_at,omitempty"`
    VoidedAt        *time.Time `json:"voided_at,omitempty"`
    VoidReason      string     `json:"void_reason,omitempty"`
    PrintedAt       *time.Time `json:"printed_at,omitempty"`
    CreatedAt       time.Time  `json:"created_at"`
    UpdatedAt       time.Time  `json:"updated_at"`
}
```

### Step 2.2 — Refactor Scan call sites

GetByID, ListActive, ListByDate, getItems → query SELECT mới + Scan đầy đủ. Sử dụng sql.NullString cho mọi nullable col.

### Step 2.3 — Update Create + UpdateStatus + RecordPayment

Create input + insert phải set:
- `order_code` (via order_code_gen — Task 6)
- `order_type` (default 'spot' nếu không có table_id)
- `opened_at = now()`
- `status = 'open'`
- `organization_id, brand_id, branch_id` từ settings table

UpdateStatus phải honor enum mới (đặt closed_at khi → closed, voided_at khi → voided).

RecordPayment đổi: `status = 'closed', closed_at = now(), paid_amount = total_amount, payment_method = X`.

---

## Task 3 — Status enum alignment (P0, ~3h)

**Files:** Refactor `Status` + `ItemStatus` constants trong order_service.go + state transition table.

```go
type Status string

const (
    StatusPending  Status = "pending"
    StatusOpen     Status = "open"
    StatusDining   Status = "dining"
    StatusCheckout Status = "checkout"
    StatusPaying   Status = "paying"
    StatusClosed   Status = "closed"
    StatusVoided   Status = "voided"
)

var validTransitions = map[Status][]Status{
    StatusPending:  {StatusOpen, StatusVoided},
    StatusOpen:     {StatusDining, StatusCheckout, StatusVoided},
    StatusDining:   {StatusCheckout, StatusVoided},
    StatusCheckout: {StatusPaying, StatusOpen, StatusVoided},
    StatusPaying:   {StatusClosed, StatusCheckout, StatusVoided},
    StatusClosed:   {},
    StatusVoided:   {},
}
```

Item status tương tự (chỉ rename `cancelled` → `voided`).

Tests: order state transitions phải tested cho cả 7 status (skip những combo invalid).

---

## Task 4 — SyncPuller.Recover direct unmarshal (P0, ~2h)

**Files:** Modify `internal/service/sync_pull.go`

Sau khi struct align, Recover() chỉ cần:

```go
var resp struct {
    Data []Order `json:"data"`  // ← direct unmarshal vào aligned struct
    Count int    `json:"count"`
}

if err := p.cloudGet(ctx, path, &resp); err != nil {
    return 0, err
}

err := p.atomic(func(tx *sql.Tx) error {
    orderStmt, err := tx.Prepare(`
        INSERT INTO orders (
            id, cloud_id, order_code, order_type, status,
            opened_at, checkout_at, closed_at, voided_at, void_reason,
            table_id, guest_count, note,
            subtotal, discount_amount, service_charge, tax_amount,
            total_tip, total_amount, paid_amount,
            organization_id, brand_id, branch_id,
            created_at, updated_at, synced_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(id) DO UPDATE SET ...
    `)
    // ... loop o := range resp.Data, exec, then loop items

    itemStmt, err := tx.Prepare(`
        INSERT INTO order_items (
            id, customer_order_id, product_sku_id, menu_item_name,
            quantity, unit_price, subtotal, note, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET ...
    `)
    // ... loop items per order
})
```

Decimal handling: cloud trả `"50000.00"` (string). Em đã có `decimalToInt()` từ Sprint 2 — reuse. But với struct field type `int`, json.Unmarshal sẽ fail. Cần custom UnmarshalJSON cho Order:

```go
func (o *Order) UnmarshalJSON(data []byte) error {
    type Alias Order // avoid infinite recursion
    aux := &struct {
        Subtotal       json.Number `json:"subtotal"`
        DiscountAmount json.Number `json:"discount_amount"`
        // ... all decimal fields
        *Alias
    }{Alias: (*Alias)(o)}

    if err := json.Unmarshal(data, aux); err != nil {
        return err
    }
    o.Subtotal = decimalToInt(aux.Subtotal)
    // ... etc
    return nil
}
```

Same pattern cho Item.

---

## Task 5 — Backend OrderController.store schema update (P0, ~2h)

**Files:** Modify `backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php`

Workstation sẽ push UP với full schema (order_code có sẵn từ local gen). Cloud:
- Validate aligned fields
- Generate cloud order_code (override workstation's local code? Hoặc preserve nếu match pattern WS-*?)
- Decision: cloud preserve workstation's local order_code khi push đầu tiên, chỉ override nếu collision

→ Workstation push body:
```json
{
  "order_code": "WS-A1B2-20260521-001",
  "order_type": "dine_in",
  "status": "open",
  "table_id": "<uuid>",
  "guest_count": 2,
  "note": "...",
  "subtotal": 10000,
  "tax_amount": 1000,
  "total_amount": 11000,
  "opened_at": "...",
  "items": [ ... ]
}
```

Backend validate + insert with all fields. Pest test verify roundtrip.

---

## Task 6 — Local order_code generation (P0, ~2h)

**Files:** New `internal/service/order_code_gen.go`

```go
package service

import (
    "fmt"
    "sync"
    "time"
)

// LocalCodeGenerator generates offline-safe order codes in format
//   WS-{deviceShort}-{YYYYMMDD}-{counter}
// e.g. "WS-A1B2-20260521-001"
//
// Counter resets daily, persisted in SQLite settings table.
type LocalCodeGenerator struct {
    db          *store.DB
    deviceShort string
    mu          sync.Mutex
}

func NewLocalCodeGenerator(db *store.DB, deviceID string) *LocalCodeGenerator {
    short := deviceID
    if len(short) > 4 {
        short = short[:4]
    }
    return &LocalCodeGenerator{db: db, deviceShort: short}
}

func (g *LocalCodeGenerator) Next() (string, error) {
    g.mu.Lock()
    defer g.mu.Unlock()

    today := time.Now().Format("20060102")
    counterKey := "order_code_counter_" + today

    var counter int
    _ = g.db.QueryRow(
        "SELECT CAST(value AS INTEGER) FROM settings WHERE key = ?", counterKey,
    ).Scan(&counter)
    counter++

    if _, err := g.db.Exec(
        `INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
        counterKey, fmt.Sprintf("%d", counter),
    ); err != nil {
        return "", err
    }

    return fmt.Sprintf("WS-%s-%s-%03d", g.deviceShort, today, counter), nil
}
```

Wire vào OrderEngine constructor + use in Create. Tests: parallel gen → no collision, daily reset.

---

## Task 7 — Frontend TS types + UI (P0, ~3h)

**Files:** 
- `frontend/src/lib/api.ts` — TS types
- `frontend/src/pages/Orders.tsx` — list view
- `frontend/src/pages/OrderDetail.tsx` — detail view (if exists)

Update TS types match new struct:

```ts
export interface Order {
  id: string;
  cloud_id?: string;
  order_code: string;
  order_number: number;
  order_type: 'spot' | 'dine_in' | 'takeaway';
  status: 'pending' | 'open' | 'dining' | 'checkout' | 'paying' | 'closed' | 'voided';
  opened_at: string;
  closed_at?: string;
  voided_at?: string;
  table_id?: string;
  table_number?: string;
  guest_count: number;
  note?: string;
  subtotal: number;
  discount_amount: number;
  service_charge: number;
  tax_amount: number;
  total_tip: number;
  total_amount: number;
  paid_amount: number;
  payment_method?: string;
  organization_id: string;
  brand_id: string;
  branch_id: string;
  created_at: string;
  updated_at: string;
  synced_at?: string;
  items: OrderItem[];
}

export interface OrderItem {
  id: string;
  customer_order_id: string;
  product_sku_id?: string;
  menu_item_id?: string;
  menu_item_name: string;
  quantity: number;
  unit_price: number;
  subtotal: number;
  note?: string;
  printer_group: string;
  status: 'pending' | 'sent_to_kitchen' | 'preparing' | 'ready' | 'served' | 'voided';
  printed_at?: string;
  created_at: string;
  updated_at: string;
}
```

Orders.tsx display: `{order.order_code || `#${String(order.order_number).padStart(3, '0')}`}`, `{order.items.length} items`, `{order.total_amount}`.

Status badge color mapping update theo enum mới.

---

## Task 8 — E2E roundtrip test (P0, ~3h)

**Files:** New `internal/service/order_service_roundtrip_test.go`

```go
func TestOrderRoundtrip(t *testing.T) {
    // 1. Workstation tạo order local
    eng, db := newOrderEngineForTest(t)
    codeGen := NewLocalCodeGenerator(db, "ABCDEF")
    
    o, err := eng.Create(CreateOrderInput{
        TableID:    "table-1",
        GuestCount: 2,
        Items: []CreateItemInput{
            {MenuItemID: "m1", Quantity: 2, UnitPrice: 5000, PrinterGroup: "kitchen"},
        },
    }, codeGen)
    // assert o.OrderCode starts with "WS-"
    
    // 2. Push UP via SyncEngine.handleOrderCreate (mock cloud server)
    // Verify cloud receives aligned JSON
    
    // 3. Cloud return order_code (might be same WS- or rewrite to ORD-)
    // Workstation persists cloud_id
    
    // 4. Simulate re-pair: wipe local, Recover()
    // Cloud returns same order back via /workstation/orders?since=
    
    // 5. Assert recovered Order equals original (modulo workstation-only fields)
}
```

Plus integration tests for status transitions, partial item updates, void/refund flow.

---

## Task 9 — Data migration verification (P0, ~1h)

**Files:** Manual verification script + commit

Sau khi Migration 007 chạy:

```sh
# Pre-migration baseline
sqlite3 ~/.ws-app/ws-app.db "SELECT id, order_number, status, total FROM orders LIMIT 5;" > /tmp/pre.txt

# Run migration (auto on next ws-app start)
./build/bin/ws-app &
sleep 5
pkill ws-app

# Post-migration check
sqlite3 ~/.ws-app/ws-app.db "SELECT id, order_code, order_number, status, total_amount, closed_at FROM orders LIMIT 5;" > /tmp/post.txt

# Diff: status enum translated correctly?
# - 'paid' → 'closed' + closed_at populated
# - 'cancelled' → 'voided' + voided_at populated
diff /tmp/pre.txt /tmp/post.txt
```

Add `docs/runbooks/sprint-4-migration.md` capturing manual steps + rollback (restore from snapshot backup if migration corrupts data).

---

## Risk + Rollback

| Risk | Mitigation |
|---|---|
| Migration 007 fail on existing DB | Snapshot backup (Sprint 1 Task 4) → restore. Test migration on copy first. |
| Status enum translation lose data | Migration adds closed_at/voided_at từ updated_at — preserves info |
| Backend API change breaks Sprint 2 contract | Backend Pest test pre-Sprint 4 (`WorkstationOrdersListTest`) must still pass — em verify trước commit S4.5 |
| Frontend TS types broken | Wails bindings regenerate via `wails3 generate bindings` |
| Local order_code collision | Counter persisted with date prefix, mutex serialised — collision impossible single-writer |
| Push UP with new schema rejected by old backend | Sprint 4 ships backend + workstation cùng commit chain → atomic deploy |

---

## Effort Summary

| Task | Hours | Owner suggested |
|---|---|---|
| 1. Migration 007 | 3 | Go |
| 2. Refactor structs | 2 | Go |
| 3. Status enum align | 3 | Go |
| 4. Recover direct unmarshal | 2 | Go |
| 5. Backend store update | 2 | BE |
| 6. Local code generator | 2 | Go |
| 7. Frontend types + UI | 3 | FE |
| 8. E2E roundtrip test | 3 | Go + BE |
| 9. Data migration script | 1 | Go |
| **Total** | **21** | |

Realistic: 2 devs × 2 ngày HOẶC 3 devs × 1.5 ngày.

---

## Execution sequencing (single-dev solo)

Day 1: Tasks 1, 2, 3 (schema layer — all Go)
Day 2: Tasks 4, 6, 5 (sync flows — Go + BE)
Day 3: Tasks 7, 8, 9 (FE + verify + polish)

NO Co-Authored-By trong commits.
