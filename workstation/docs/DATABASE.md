# Database Design

> ⚠️ **STALE SNIPPETS — schema sections below were authored pre-Sprint 4
> alignment (migration 007, 2026-05-21) and the workstation print-status
> split (migration 009, 2026-05-24).** Column names, default values, and
> enum values have moved on. The CREATE TABLE blocks remain useful as an
> overview of the table set but **MUST NOT be treated as authoritative**.
>
> Single source of truth: `internal/store/migrations/*.sql` (hand-written,
> versions 1..999) + `migrations/omnify/*.sql` (omnify codegen, versions
> 1000+). See `migrations_e2e_test.go` for the canonical full-chain test.

## Engine: SQLite

- Pure Go driver: `modernc.org/sqlite` (khong can CGO, de cross-compile)
- WAL mode cho concurrent reads
- Migrations embedded qua `go:embed`

## Connection Pragmas

```sql
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA foreign_keys = ON;
PRAGMA synchronous = NORMAL;
```

## Schema

### menu_items
Menu items cached tu cloud. Cloud la source of truth.

```sql
CREATE TABLE menu_items (
    id            TEXT PRIMARY KEY,       -- UUID tu cloud
    cloud_id      TEXT UNIQUE,            -- ID tren cloud system
    name          TEXT NOT NULL,           -- Ten mon (VD: "Cafe Sua Da")
    name_ja       TEXT,                    -- Ten tieng Nhat (VD: "アイスカフェオレ")
    category      TEXT,                    -- Nhom mon (drink, food, dessert)
    price         INTEGER NOT NULL,        -- Gia nho nhat (yen/dong)
    printer_group TEXT NOT NULL DEFAULT 'kitchen',  -- kitchen, bar, dessert
    sort_order    INTEGER DEFAULT 0,
    is_active     INTEGER DEFAULT 1,
    image_url     TEXT,
    cloud_updated_at TEXT,                 -- Last update tu cloud
    local_updated_at TEXT NOT NULL         -- Last local cache time
);

CREATE INDEX idx_menu_items_category ON menu_items(category);
CREATE INDEX idx_menu_items_active ON menu_items(is_active);
```

### orders
Orders - tao local, sync len cloud.

```sql
CREATE TABLE orders (
    id            TEXT PRIMARY KEY,        -- Local UUID
    cloud_id      TEXT,                    -- NULL cho toi khi sync thanh cong
    order_number  INTEGER NOT NULL,        -- So thu tu trong ngay (auto-increment daily)
    table_number  TEXT,                    -- So ban (VD: "A1", "B3")
    status        TEXT NOT NULL DEFAULT 'open',
    -- Statuses (post-migration 007, cloud-aligned):
    --   pending, open, dining, checkout, paying, closed, voided
    -- (pre-S4 values open/preparing/ready/served/paid/cancelled are migrated by 007)
    customer_count INTEGER DEFAULT 1,      -- So khach
    notes         TEXT,                    -- Ghi chu chung cho order
    subtotal      INTEGER NOT NULL DEFAULT 0,  -- Tong truoc thue
    tax           INTEGER NOT NULL DEFAULT 0,  -- Thue
    total         INTEGER NOT NULL DEFAULT 0,  -- Tong sau thue
    payment_method TEXT,                   -- cash, card, e-money, NULL = chua thanh toan
    paid_at       TEXT,                    -- Thoi diem thanh toan
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    synced_at     TEXT                     -- NULL = chua sync
);

CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_created ON orders(created_at);
CREATE INDEX idx_orders_synced ON orders(synced_at);
```

### order_items
Chi tiet tung mon trong order.

```sql
CREATE TABLE order_items (
    id            TEXT PRIMARY KEY,
    order_id      TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    menu_item_id  TEXT NOT NULL,
    menu_item_name TEXT NOT NULL,           -- Snapshot ten mon luc dat
    quantity      INTEGER NOT NULL DEFAULT 1,
    unit_price    INTEGER NOT NULL,         -- Snapshot gia luc dat
    notes         TEXT,                     -- Ghi chu rieng (VD: "khong duong")
    printer_group TEXT NOT NULL,            -- kitchen, bar, dessert
    status        TEXT NOT NULL DEFAULT 'pending',
    -- Statuses (cloud-aligned via PR #19, 5 values): pending, preparing, ready, served, voided
    print_status  TEXT NOT NULL DEFAULT 'pending',
    -- Print state (workstation-only, added by migration 009):
    --   pending, sent_to_kitchen, failed
    -- Cloud doesn't model print events; status no longer carries sent_to_kitchen.
    printed_at    TEXT,                     -- Workstation-local print timestamp
    created_at    TEXT NOT NULL
);

CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_order_items_status ON order_items(status);
```

### devices
Registry cac thiet bi ket noi.

```sql
CREATE TABLE devices (
    id              TEXT PRIMARY KEY,
    type            TEXT NOT NULL,          -- receipt_printer, kitchen_printer, bar_printer, pos, staff_caller
    name            TEXT NOT NULL,          -- Ten hien thi (VD: "May in bep")
    connection_type TEXT NOT NULL,          -- usb, network, bluetooth
    address         TEXT,                   -- IP:port, USB path, BT address
    config          TEXT,                   -- JSON blob cho device-specific settings
    -- VD printer config: {"paper_width": 80, "cut_type": "full", "encoding": "shift_jis"}
    is_active       INTEGER DEFAULT 1,
    last_seen_at    TEXT,                   -- Lan cuoi ket noi thanh cong
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
```

### sync_queue
Hang doi cac operations can sync len cloud.

```sql
CREATE TABLE sync_queue (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type   TEXT NOT NULL,            -- order, order_item, payment
    entity_id     TEXT NOT NULL,
    operation     TEXT NOT NULL,            -- create, update, delete
    payload       TEXT NOT NULL,            -- JSON payload day du
    idempotency_key TEXT UNIQUE,            -- UUID de chong duplicate tren cloud
    priority      INTEGER DEFAULT 0,       -- 0 = normal, 1 = high (payments)
    attempts      INTEGER DEFAULT 0,
    max_attempts  INTEGER DEFAULT 5,
    last_error    TEXT,
    created_at    TEXT NOT NULL,
    synced_at     TEXT                      -- NULL = chua sync
);

CREATE INDEX idx_sync_queue_pending ON sync_queue(synced_at) WHERE synced_at IS NULL;
CREATE INDEX idx_sync_queue_priority ON sync_queue(priority DESC, created_at ASC);
```

### schema_migrations
Theo doi migrations da chay.

```sql
CREATE TABLE schema_migrations (
    version   INTEGER PRIMARY KEY,
    name      TEXT NOT NULL,
    applied_at TEXT NOT NULL
);
```

### settings
Cau hinh app (key-value).

```sql
CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Default settings:
-- store_name: Ten quan
-- store_address: Dia chi
-- tax_rate: Thue suat (VD: "10" = 10%)
-- server_port: Port cua local server (default: 8080)
-- last_sync_at: Thoi diem sync cuoi cung
-- order_number_prefix: Prefix so order (VD: "A")
-- cloud_api_url: URL cua Omnify cloud API
-- branch_id: ID chi nhanh tren cloud
```

## Relationships

```
orders (1) ---> (N) order_items
menu_items (1) ---> (N) order_items (via menu_item_id)
devices (independent)
sync_queue (references entity_type + entity_id)
settings (independent key-value store)
```

## Migration Strategy

1. SQL files embedded in binary via `go:embed`
2. On app start: check `schema_migrations` table
3. Run missing migrations in order (by version number)
4. Each migration runs in a transaction
5. Migration file naming: `001_initial_schema.sql`, `002_add_settings.sql`, etc.

## Offline Data Lifecycle

| Operation | SQLite | sync_queue | Cloud |
|-----------|--------|------------|-------|
| Create order | INSERT immediately | Enqueue CREATE | POST when online |
| Update order status | UPDATE immediately | Enqueue UPDATE | PUT when online |
| Payment recorded | UPDATE order | Enqueue high-priority | POST when online |
| Menu sync (inbound) | REPLACE from cloud | N/A | Source of truth |
| Cancel order | UPDATE status | Enqueue UPDATE | PUT when online |
