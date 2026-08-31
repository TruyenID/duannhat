# Implementation Phases

## Phase 1: Foundation + MVP

**Muc tieu**: App desktop co the tao order, in receipt, luu local.

### Tasks

1. **Project Scaffold**
   - Wails v3 project: `go.mod`, `main.go`, `wails.json`
   - Makefile (theo pattern godx)
   - Directory structure theo ARCHITECTURE.md

2. **Config Module** (`internal/config/`)
   - Config directory: `~/.ws-app/`
   - Load/save config file (TOML hoac JSON)
   - Env override: `WS_APP_CONFIG_DIR`
   - Settings: store name, server port, tax rate, cloud API URL

3. **SQLite Database** (`internal/db/`)
   - Connection manager voi WAL mode
   - Migration runner (go:embed)
   - Migration 001: initial schema (tat ca tables tu DATABASE.md)
   - Helper functions: Exec, Query, QueryRow, Transaction

4. **Order Engine** (`internal/order/`)
   - Models: Order, OrderItem, OrderStatus
   - Create order voi items
   - Update order status (state machine validation)
   - Add/remove items
   - Calculate totals (subtotal, tax, total)
   - Daily order number auto-increment

5. **Printer Support** (`internal/device/`)
   - ESC/POS encoder: text, bold, alignment, cut, open drawer
   - UTF-8 -> Shift_JIS encoding
   - USB printer connection (single printer)
   - Print kitchen ticket template
   - Print receipt template

6. **Frontend Scaffold** (`frontend/`)
   - React 19 + TypeScript + Vite
   - @omnifyjp/ui integration
   - Tailwind CSS setup
   - Router (react-router hoac tanstack-router)
   - Main layout: sidebar + content area

7. **Dashboard Page**
   - Active orders summary
   - Device status indicators
   - Quick stats (orders today, revenue)
   - LAN IP + QR code display

8. **Orders Page**
   - Create new order (select table, add items)
   - View active orders list
   - Update order status
   - Simple menu item selector

9. **Wails Bindings**
   - Expose Go services to frontend
   - Order CRUD bindings
   - Device status bindings
   - Config get/set bindings

### Deliverables
- `wails3 dev` chay duoc, hien thi dashboard
- Tao order -> luu SQLite -> hien thi tren UI
- In kitchen ticket va receipt (1 may in USB)
- `make build` tao executable cho platform hien tai

---

## Phase 2: LAN Server + Cloud Sync

**Muc tieu**: Tablet/phone dat order qua LAN, sync voi Omnify cloud.

### Tasks

1. **Local HTTP Server** (`internal/server/`)
   - HTTP server on configurable port
   - REST endpoints (theo LOCAL_SERVER.md)
   - LAN-only middleware
   - CORS configuration

2. **WebSocket Hub**
   - Client connection management
   - Event broadcast system
   - Subscribe/unsubscribe channels

3. **Sync Engine** (`internal/sync/`)
   - Online/offline monitor
   - Outbound queue processor
   - Inbound sync (menu items, org info)
   - Conflict resolution (theo SYNC.md)

4. **Omnify API Client**
   - Auth: login/token refresh (theo godx pattern)
   - Menu items API
   - Orders API
   - Payments API

5. **mDNS Discovery** (`internal/discovery/`)
   - Advertise `_ws-app._tcp.local.`
   - TXT records voi branch info

6. **Frontend Updates**
   - Sync status indicator (header)
   - Sync page (queue status, history)
   - Online/offline indicator
   - Real-time order updates via WebSocket

### Deliverables
- Tablet mo browser -> truy cap ws-app qua LAN IP
- Dat order tu tablet -> hien thi tren desktop app
- Tat internet -> van dat order binh thuong
- Bat internet -> orders tu dong sync len cloud

---

## Phase 3: Multi-Device

**Muc tieu**: Nhieu may in, routing thong minh, may goi nhan vien.

### Tasks

1. **Network Printer Discovery**
   - mDNS scan cho printers
   - Auto-detect printer model
   - Test print functionality

2. **Multi-Printer Routing**
   - Config: menu item -> printer group -> physical printer
   - Route order items toi dung may in
   - Fallback printer khi primary offline

3. **Print Queue**
   - Queue per printer
   - Retry logic (3 attempts, 2s delay)
   - Alert user khi printer unreachable
   - Reprint functionality

4. **Device Management UI**
   - Add/remove/edit devices
   - Test connection button
   - Printer config (paper width, encoding, cut type)
   - Status monitoring dashboard

5. **Staff Caller Integration**
   - Research protocol cua may goi nhan vien
   - Basic integration (send call signal)

### Deliverables
- Quan co 3 may in (bep, bar, receipt) -> items tu dong in dung noi
- Them/xoa may in tu Devices page
- May in offline -> alert + retry

---

## Phase 4: POS + Polish

**Muc tieu**: Thanh toan, production-ready, installer.

### Tasks

1. **Cash Drawer**
   - ESC/POS kick command
   - Config: drawer connected to which printer

2. **Payment Recording**
   - Cash payment (tinh tien thua)
   - Card payment tracking (manual entry)
   - E-money tracking
   - Split payment

3. **Receipt Formatting**
   - Store branding (name, address, logo)
   - Tax calculation display
   - Payment details
   - Customizable footer message

4. **Reporting**
   - Daily sales summary
   - Orders by time period
   - Popular items
   - Export CSV

5. **Auto-Update**
   - Check for new version on startup
   - Download + prompt install
   - Version display in Settings

6. **Installer Packaging**
   - macOS: DMG with drag-to-Applications
   - Windows: MSI installer
   - Linux: AppImage

7. **Production Hardening**
   - Error recovery (app crash -> restart)
   - Data backup (periodic SQLite backup)
   - Logging (structured JSON logs)
   - Performance optimization

### Deliverables
- Thanh toan -> mo hop tien -> in receipt -> dong order
- Report doanh thu cuoi ngay
- Installer cho 3 OS
- App stable, khong mat data
