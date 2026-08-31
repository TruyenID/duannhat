# Device Integration

## Printer roles (multi-role — plan 07)

A physical printer is registered **once** and assigned a **list of roles** —
not a single fixed type. One device can answer for several roles at the same
time, so all three shop topologies work without code changes:

1. **1 printer** → tick every role (kitchen + hold + bar + receipt) → all jobs
   print on that one device.
2. **Many printers** → each device ticks one role.
3. **Mixed** → one device ticks several roles (e.g. kitchen + receipt) while
   others take the rest.

Print resolution calls `Manager.GetPrinterByRole(role)`, which returns the
first registered device whose role list contains `role`. There is **no longer**
any `*_printer_ip` settings fallback — every printer is a real device record
(migration `013_printer_roles.sql` backfilled the legacy IP keys into device
records, collapsing roles that shared an IP into one multi-role device, then
deleted the keys). `Manager.RolesWithoutPrinter()` drives the
"role chưa máy nào đảm nhiệm" warning shown on the Devices page.

| Role (`DeviceType`) | Connection | Protocol | Notes |
|------|-----------|----------|----------|
| `kitchen_printer` | USB, Network | ESC/POS | Bếp ticket |
| `hold_printer` | USB, Network | ESC/POS | Runner/hold ticket — falls back to kitchen printer if unassigned |
| `bar_printer` | USB, Network | ESC/POS | Bar ticket |
| `receipt_printer` | USB, Network | ESC/POS | Hóa đơn (template owned by plan 08) |
| `staff_caller` | Network, USB | TBD | Not driven as a printer |
| Cash Drawer | Via Printer | ESC/POS kick | Future |
| Customer Display | USB, Network | TBD | Future |

## Printer Support

### ESC/POS Protocol

Giao thuc chuan cua may in nhiet (Epson, Star Micronics, Bixolon, etc.).

#### Connection Methods

**USB (Phase 1):**
- macOS: `/dev/cu.usbserial-*` hoac `/dev/tty.usbserial-*`
- Linux: `/dev/usb/lp0` hoac `/dev/ttyUSB0`
- Windows: `\\.\COM3` hoac direct USB

**Network/TCP (Phase 1):**
- Standard port: 9100 (raw TCP)
- Connect toi `printer_ip:9100`
- Gui raw ESC/POS bytes qua TCP socket

**Bluetooth (Future):**
- BLE hoac Classic Bluetooth
- Phu thuoc vao OS APIs

### ESC/POS Commands

```
ESC @          -> Initialize printer
ESC a n        -> Text alignment (0=left, 1=center, 2=right)
ESC ! n        -> Print mode (bold, double height/width)
GS V m         -> Cut paper (0=full cut, 1=partial cut)
ESC p m t1 t2  -> Open cash drawer
GS ( k         -> Print QR code
ESC t n        -> Select character code table
FS &           -> Select Kanji character mode
FS C n         -> Select Kanji encoding (Shift_JIS)
```

### Character Encoding

- Input: UTF-8 (Go strings)
- Output: Shift_JIS (Code Page 932) cho may in Nhat
- Library: `golang.org/x/text/encoding/japanese`
- Luu y: Kanji mode (`FS &`) phai bat truoc khi gui text tieng Nhat

### Print Templates

#### Kitchen Ticket
```
================================
          ORDER #042
          Table: A3
================================
x2  Pho Bo               [URGENT]
    -> Khong hanh
x1  Com Ga
x3  Tra Da
--------------------------------
Time: 14:32  Staff: Tanaka
================================
```

#### Receipt
```
      QUAN ABC
   123 Nguyen Hue, Q1
   Tel: 028-1234-5678
================================
Order: #042    Table: A3
Date: 2026-04-11 14:32
--------------------------------
Pho Bo           x2    180,000
Com Ga           x1    120,000
Tra Da           x3     30,000
--------------------------------
Subtotal:             330,000
Tax (10%):             33,000
================================
TOTAL:                363,000
================================
Payment: Cash
Received:             400,000
Change:                37,000
--------------------------------
   Thank you! See you again!
================================
```

## Device Discovery

### mDNS/Zeroconf

- Scan LAN cho services:
  - `_ipp._tcp` (Internet Printing Protocol)
  - `_pdl-datastream._tcp` (Raw printing - port 9100)
- Library: `github.com/grandcat/zeroconf`
- Khi tim thay printer -> hien thi trong Devices page de user add

### Manual Configuration

- User nhap IP:port thu cong
- Test connection (gui ESC/POS init command, cho phan hoi)
- Luu vao `devices` table

## Device Manager Architecture

```go
// Device interface - moi loai device implement interface nay
type Device interface {
    ID() string
    Type() DeviceType        // primary (first) role — back-compat
    Roles() []DeviceType     // full role list (multi-role)
    HasRole(role DeviceType) bool
    Name() string
    Connect() error
    Disconnect() error
    Status() DeviceStatus
    IsConnected() bool
}

// DeviceType enum
type DeviceType string
const (
    DeviceTypeReceiptPrinter  DeviceType = "receipt_printer"
    DeviceTypeKitchenPrinter  DeviceType = "kitchen_printer"
    DeviceTypeBarPrinter      DeviceType = "bar_printer"
    DeviceTypeStaffCaller     DeviceType = "staff_caller"
    DeviceTypePOS             DeviceType = "pos"
)

// DeviceStatus
type DeviceStatus string
const (
    DeviceStatusOnline       DeviceStatus = "online"
    DeviceStatusOffline      DeviceStatus = "offline"
    DeviceStatusError        DeviceStatus = "error"
    DeviceStatusPrinting     DeviceStatus = "printing"
)

// Manager quan ly tat ca devices
type Manager struct {
    mu      sync.RWMutex
    devices map[string]Device
    db      *db.DB
    events  chan DeviceEvent
}
```

## Print Queue

```
Order Created
    |
    v
Order Router (group items by printer_group)
    |
    +---> Kitchen items -> Kitchen Printer Queue
    +---> Bar items     -> Bar Printer Queue
    +---> Receipt       -> Receipt Printer Queue (on payment)
    |
    v
Each Queue:
    - Retry up to 3 times on failure
    - 2 second delay between retries
    - Alert user if printer unreachable
    - Mark order_item.printed_at on success
```
