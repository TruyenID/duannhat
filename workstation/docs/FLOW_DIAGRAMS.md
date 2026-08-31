# Flow Diagrams

## 1. Device Pairing Flow

```
Admin Web Panel                    Laravel Cloud                      ws-app
     |                                  |                               |
     |-- Tao device tren admin panel --->|                               |
     |   {name, type:workstation,       |                               |
     |    branch_id}                    |                               |
     |<-- pairing_code: "XK9F2A" ------|                               |
     |                                  |                               |
     |   (admin doc code cho operator)  |                               |
     |                                  |                               |
     |                                  |<- POST /v1/workstation/pair --|
     |                                  |   {pairing_code: "XK9F2A",   |
     |                                  |    device_info: {os,arch}}    |
     |                                  |                               |
     |                                  |-- 200 {device_token, device}->|
     |                                  |                               |
     |                                  |   Luu device_token vao SQLite |
     |                                  |                               |
     |                                  |<- GET /v1/workstation/sync/pull|
     |                                  |   (full sync, no ?since)      |
     |                                  |                               |
     |                                  |-- 200 {branch, menus,        |
     |                                  |    categories, tables} ------>|
     |                                  |                               |
     |                                  |   Luu tat ca vao SQLite.     |
     |                                  |   App san sang phuc vu.      |
```

## 2. Order Lifecycle

```
Khach (Tablet)     ws-app (LAN Server)      SQLite           Cloud API
     |                     |                    |                  |
     |-- POST /orders ---->|                    |                  |
     |   {table, items}    |                    |                  |
     |                     |-- INSERT order --->|                  |
     |                     |-- INSERT queue --->|                  |
     |                     |   (idempotency_key)|                 |
     |                     |-- WS broadcast --->| (LAN clients)   |
     |<-- 201 order -------|                    |                  |
     |                     |                    |                  |
     |                     |-- ESC/POS print -->| Kitchen Printer  |
     |                     |                    |                  |
     |   [Sync engine, moi 5s]                  |                  |
     |                     |<- SELECT pending --|                  |
     |                     |                    |                  |
     |                     |-- POST /workstation/sync/push ------->|
     |                     |   {operations: [order.create]}        |
     |                     |                    |                  |
     |                     |<-- 200 {ok, cloud_id} ---------------|
     |                     |-- UPDATE queue --->|                  |
     |                     |   (synced_at=now)  |                  |
```

## 3. Offline -> Online Recovery

```
ws-app                 Connectivity Monitor          Cloud API
  |                          |                          |
  | [Mang mat]               |                          |
  | Orders van duoc tao      |                          |
  | -> luu SQLite            |                          |
  | -> queue sync_queue      |                          |
  |                          |                          |
  |                          |-- HEAD check (fail) ---->|
  |                          |  (backoff: 10s->20s->40s)|
  |                          |                          |
  | ... phut/gio ...         |                          |
  |                          |                          |
  |                          |-- HEAD check (OK!) ----->|
  |<-- onChange(online) -----|                          |
  |                          |                          |
  | Buoc 1: Pull changes                               |
  |-- GET /workstation/sync/pull?since=last_pull_at --->|
  |<-- 200 {menus, tables, deleted_ids} ---------------|
  |                                                     |
  | Upsert menu, remove deleted, update last_pull_at    |
  |                                                     |
  | Buoc 2: Push queued operations                      |
  |-- POST /workstation/sync/push --------------------->|
  |   {operations: [tat ca pending items]}              |
  |<-- 200 {results: [ok, ok, error]} -----------------|
  |                                                     |
  | Mark ok -> synced_at                                |
  | Mark error -> increment attempts / log              |
```

## 4. Menu Update Flow

```
Admin Panel        Cloud API             ws-app
    |                  |                    |
    |-- PUT menu ----->|                    |
    |   (doi gia,      |                    |
    |    them mon)     |                    |
    |<-- 200 ok -------|                    |
    |                  |                    |
    |                  | [ws-app poll 30s]  |
    |                  |                    |
    |                  |<-- GET /workstation/menu/changes?since= --|
    |                  |                    |
    |                  |-- 200 {updated, deleted_ids} ------------>|
    |                  |                    |
    |                  | Update local menu_items                   |
    |                  | UI refresh tu dong                        |
```

## 5. Multi-Device LAN Architecture

```
                    Internet (Cloud)
                         |
                    [Cloud API]
                         |
                    ============
                    |  ws-app  |  <-- Workstation (Go + Wails)
                    |  :8080   |      SQLite + ESC/POS + mDNS
                    ============
                    /    |    \
                   /     |     \
            WiFi LAN Network
              /      |       \
     [Tablet]   [Phone]   [Kitchen Display]
      Browser    Browser    Browser
      :8080      :8080      :8080

     [Receipt    [Kitchen    [Bar        [Cash
      Printer]    Printer]    Printer]    Drawer]
      USB/TCP    USB/TCP     USB/TCP     ESC/POS
```

## 6. Heartbeat & Monitoring

```
ws-app                                 Cloud API
  |                                       |
  | [Moi 60s]                             |
  |-- POST /workstation/heartbeat ------->|
  |   {version, uptime, memory,           |
  |    active_orders, pending_sync,        |
  |    disk_free, db_size}                 |
  |                                       |
  |<-- 200 {server_time, status,          |
  |     messages: []}  -------------------|
  |                                       |
  | [Moi 5 phut]                          |
  |-- GET /workstation/config ----------->|
  |<-- 200 {tax_rate, receipt_header,     |
  |     sync_interval, features} ---------|
  |                                       |
  | Update local config tu cloud          |
```
