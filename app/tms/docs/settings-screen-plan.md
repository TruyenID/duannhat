# Kế hoạch màn hình Settings — Cấu hình thiết bị ngoại vi

## Tổng quan

Thêm màn hình Settings vào TMS app cho phép cấu hình IP tĩnh của máy in nhiệt Star MC-Print3 kết nối qua LAN, kèm chức năng test kết nối bằng cách in thử.

---

## Bước 0 — Cài đặt dependency

### Cài package

```sh
npm install react-native-star-io10
```

### Build native lên thiết bị thật (bắt buộc)

```sh
npx expo run:ios --device
```

> Sau bước này không dùng Expo Go nữa. Hot reload JS vẫn hoạt động bình thường với `npx expo start`.

---

## Bước 1 — Tạo `src/lib/printer.ts`

File mới. Wrap `react-native-star-io10`, expose 2 function dùng trong toàn app.

### Function 1: `testPrinterConnection(ip: string): Promise<void>`

- Kết nối tới máy in qua LAN với IP truyền vào
- In test page:

```
================================
        TEST PRINT
        TempoFast TMS
   IP: 192.168.1.232
   2026/04/13  13:45:00
        ✓ CONNECTED
================================
```

- Partial cut sau khi in
- Throw error nếu kết nối thất bại (timeout, IP sai, máy in offline)

### Function 2: `printReceiptImage(ip: string, imageBase64: string): Promise<void>`

- Dùng cho tính năng in hoá đơn sau này
- Nhận ảnh base64 PNG, gửi lên máy in
- Partial cut sau khi in

### Cấu hình máy in

```ts
const PRINTER_DOTS_WIDTH = 576;  // MC-Print3 @ 203 DPI, giấy 80mm, vùng in ~72mm
```

---

## Bước 2 — Tạo `src/hooks/use-printer-config.ts`

Hook mới. Lưu/đọc IP máy in từ AsyncStorage.

### Storage key

```ts
const STORAGE_KEY = 'tms_printer_ip';
```

### Interface

```ts
interface UsePrinterConfigReturn {
  printerIp: string;          // IP hiện tại, rỗng nếu chưa cấu hình
  isLoading: boolean;         // đang đọc từ AsyncStorage
  savePrinterIp: (ip: string) => Promise<void>;  // validate + lưu
}
```

### Validation IP

```ts
function isValidIp(ip: string): boolean {
  const regex = /^(\d{1,3}\.){3}\d{1,3}$/;
  if (!regex.test(ip)) return false;
  return ip.split('.').every(n => Number(n) >= 0 && Number(n) <= 255);
}
```

- Nếu IP không hợp lệ → throw error, không lưu
- Lưu vào AsyncStorage sau khi validate thành công

---

## Bước 3 — Tạo `app/settings.tsx`

Screen mới. Route: `/settings`

### Layout

```
┌─────────────────────────────────┐
│  ← 設定 / Settings / Cài đặt   │
├─────────────────────────────────┤
│                                 │
│  [Section] 周辺機器             │
│                                 │
│  ┌───────────────────────────┐  │
│  │ 🖨  レシートプリンター      │  │
│  │     Star MC-Print3 (LAN)  │  │
│  │                           │  │
│  │  IP Address               │  │
│  │  ┌─────────────────────┐  │  │
│  │  │ 192.168.1.232       │  │  │
│  │  └─────────────────────┘  │  │
│  │                           │  │
│  │  ● 保存済み / ○ 未設定    │  │
│  │                           │  │
│  │  [        保存        ]   │  │
│  │  [    テスト接続       ]  │  │
│  │                           │  │
│  │  ✅ 接続成功              │  │
│  │  ❌ 接続失敗: <lý do>     │  │
│  └───────────────────────────┘  │
│                                 │
│  [Section] 言語 / Language      │
│  (ja / en / vi switcher)        │
│                                 │
└─────────────────────────────────┘
```

### States của nút "テスト接続"

| State | Hiển thị | Hành động |
|-------|----------|-----------|
| `idle` | "テスト接続" | Nhấn → bắt đầu test |
| `loading` | Spinner + "接続中..." | Disabled |
| `success` | ✅ "接続成功" | Auto reset sau 3 giây |
| `error` | ❌ "接続失敗: timeout" | Nhấn lại để thử |

### Logic xử lý

```
Nhấn "保存":
  1. Validate IP
  2. Nếu hợp lệ → savePrinterIp() → hiện "保存済み"
  3. Nếu không hợp lệ → hiện error "IPアドレスの形式が正しくありません"

Nhấn "テスト接続":
  1. Kiểm tra đã có IP chưa → nếu chưa → hiện toast "先にIPを保存してください"
  2. setTestState('loading')
  3. gọi testPrinterConnection(printerIp)
  4. Thành công → setTestState('success') → setTimeout 3s → setTestState('idle')
  5. Thất bại → setTestState('error') + lưu message lỗi
```

---

## Bước 4 — Sửa `app/home.tsx`

### Thêm nút Settings vào header

Hiện tại header:
```
[TMS Title + branch name]          [Logout]
```

Sau khi sửa:
```
[TMS Title + branch name]    [⚙]  [Logout]
```

Thêm `Pressable` với icon ⚙ (SVG) bên trái nút Logout:

```tsx
<Pressable onPress={() => router.push('/settings')}>
  {/* Gear icon SVG */}
</Pressable>
```

---

## Bước 5 — Thêm i18n keys

### `src/i18n/ja.json`

```json
"settings.title": "設定",
"settings.peripherals": "周辺機器",
"settings.printer_label": "レシートプリンター",
"settings.printer_model": "Star MC-Print3 (LAN)",
"settings.printer_ip": "IPアドレス",
"settings.printer_ip_placeholder": "例: 192.168.1.232",
"settings.printer_saved": "保存済み",
"settings.printer_not_configured": "未設定",
"settings.save_success": "IPアドレスを保存しました",
"settings.invalid_ip": "IPアドレスの形式が正しくありません",
"settings.test_connection": "テスト接続",
"settings.testing": "接続中...",
"settings.test_success": "接続成功",
"settings.test_failed": "接続失敗",
"settings.test_no_ip": "先にIPアドレスを保存してください"
```

### `src/i18n/en.json`

```json
"settings.title": "Settings",
"settings.peripherals": "Peripherals",
"settings.printer_label": "Receipt Printer",
"settings.printer_model": "Star MC-Print3 (LAN)",
"settings.printer_ip": "IP Address",
"settings.printer_ip_placeholder": "e.g. 192.168.1.232",
"settings.printer_saved": "Saved",
"settings.printer_not_configured": "Not configured",
"settings.save_success": "IP address saved",
"settings.invalid_ip": "Invalid IP address format",
"settings.test_connection": "Test Connection",
"settings.testing": "Connecting...",
"settings.test_success": "Connected successfully",
"settings.test_failed": "Connection failed",
"settings.test_no_ip": "Please save an IP address first"
```

### `src/i18n/vi.json`

```json
"settings.title": "Cài đặt",
"settings.peripherals": "Thiết bị ngoại vi",
"settings.printer_label": "Máy in hoá đơn",
"settings.printer_model": "Star MC-Print3 (LAN)",
"settings.printer_ip": "Địa chỉ IP",
"settings.printer_ip_placeholder": "Ví dụ: 192.168.1.232",
"settings.printer_saved": "Đã lưu",
"settings.printer_not_configured": "Chưa cấu hình",
"settings.save_success": "Đã lưu địa chỉ IP",
"settings.invalid_ip": "Địa chỉ IP không hợp lệ",
"settings.test_connection": "Kiểm tra kết nối",
"settings.testing": "Đang kết nối...",
"settings.test_success": "Kết nối thành công",
"settings.test_failed": "Kết nối thất bại",
"settings.test_no_ip": "Vui lòng lưu địa chỉ IP trước"
```

---

## Thứ tự thực hiện

1. `npm install react-native-star-io10`
2. Tạo `src/lib/printer.ts`
3. Tạo `src/hooks/use-printer-config.ts`
4. Thêm i18n keys vào 3 file
5. Tạo `app/settings.tsx`
6. Sửa `app/home.tsx` thêm nút Settings
7. `npx expo run:ios --device`

---

## Lưu ý

- `react-native-star-io10` chỉ hoạt động trên thiết bị thật (iOS/Android), không chạy trên Simulator vì cần WiFi thật để kết nối máy in
- IP máy in lưu ở AsyncStorage (không cần SecureStore vì không phải secret)
- Màn hình Settings truy cập được kể cả khi máy in chưa được cấu hình
