# Architecture

## High-Level Architecture

```
+------------------------------------------------------------------+
|                        ws-app (Desktop)                           |
|                                                                   |
|  +-------------------+    +-------------------+                   |
|  |   Wails Frontend  |    |   Local Server    |                   |
|  |   (React + TS)    |    |   (HTTP + WS)     |                   |
|  |   @omnifyjp/ui    |    |   Port 8080       |                   |
|  +--------+----------+    +--------+----------+                   |
|           |                        |                              |
|           v                        v                              |
|  +------------------------------------------------+              |
|  |              Go Backend Services                |              |
|  |                                                 |              |
|  |  +-------------+  +-------------+  +---------+  |              |
|  |  | Order Engine|  |Device Manager| |  Sync   |  |              |
|  |  +------+------+  +------+------+ | Engine  |  |              |
|  |         |                |         +----+----+  |              |
|  |         v                v              |       |              |
|  |  +-------------+  +-----------+         |       |              |
|  |  |   SQLite    |  | ESC/POS   |         |       |              |
|  |  |   (Local)   |  | Printers  |         |       |              |
|  |  +-------------+  +-----------+         |       |              |
|  +------------------------------------------------+              |
|                                                    |              |
+----------------------------------------------------+--------------+
                                                     |
                                              +------v-------+
                                              | Omnify Cloud  |
                                              | (DXS Platform)|
                                              +--------------+

         LAN Network:
         +------------------+
         | Tablet (Browser) |----> Local Server :8080
         | Phone  (Browser) |----> WebSocket /ws
         | Staff Caller     |----> Device Manager
         +------------------+
```

## Communication Flows

### 1. Desktop UI <-> Go Backend (Wails Bindings)
- Frontend goi Go functions truc tiep qua Wails bindings
- Khong can REST API cho desktop UI
- Go backend return data truc tiep ve frontend

### 2. LAN Devices <-> Local Server (HTTP + WebSocket)
- Tablet/phone truy cap Local Server qua HTTP
- WebSocket cho real-time updates (order created, status changed)
- Chi chap nhan connections tu private IP ranges

### 3. ws-app <-> Cloud (REST API)
- Sync engine goi Omnify/DXS REST API
- Outbound queue dam bao khong mat data khi offline
- Auth token tu Omnify SSO (theo pattern godx)

### 4. ws-app <-> Printers (ESC/POS)
- USB: Raw byte writes toi device file descriptor
- Network: TCP socket toi printer IP:9100
- Encoding: UTF-8 -> Shift_JIS cho may in nhiet Nhat

## Module Dependency Graph

```
main.go
  -> internal/app          (Wails bootstrap)
      -> internal/config   (App configuration)
      -> internal/db       (SQLite database)
      -> internal/order    (Order engine)
          -> internal/db
          -> internal/device
      -> internal/device   (Device manager)
          -> internal/device/escpos
      -> internal/server   (Local HTTP/WS server)
          -> internal/order
          -> internal/device
      -> internal/sync     (Cloud sync)
          -> internal/db
      -> internal/discovery (mDNS)
      -> internal/pos      (POS bridge)
          -> internal/device
```

## Data Flow: Order Lifecycle

```
1. Tablet/Phone -> POST /api/orders -> Local Server
2. Local Server -> Order Engine -> Validate + Save to SQLite
3. Order Engine -> Device Manager -> Route to Kitchen/Bar Printer
4. Device Manager -> ESC/POS Encoder -> Print Kitchen Ticket
5. Order Engine -> WebSocket Hub -> Broadcast "order_created"
6. All LAN clients receive real-time update
7. Sync Engine -> Queue operation -> Push to Cloud (when online)
```

## Data Flow: Offline -> Online Sync

```
1. [Offline] User creates orders -> Save to SQLite
2. [Offline] Each mutation -> Append to sync_queue table
3. [Online detected] Sync Monitor triggers sync
4. Outbound: Process sync_queue FIFO -> POST to Cloud API
5. Inbound: GET changes since last_sync_at -> Update local SQLite
6. Mark synced items in sync_queue (synced_at timestamp)
```
