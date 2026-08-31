# Kế hoạch tích hợp tính năng in hoá đơn vào godx-kiosk

> **Trạng thái**: ✅ **Đã implement xong** (2026-05-17). Xem [Tổng kết implementation](#tổng-kết-implementation) ở cuối file.

## Tổng quan

Hợp nhất logic in hoá đơn từ project demo `receipt_print` (`/Users/phamduyanh1910/Documents/famgia/receipt`) vào `godx-kiosk`. Mục tiêu:

1. **Auto-print** hoá đơn ngay khi khách thanh toán thành công (vào `app/success.tsx`).
2. **Manual print**: nút "In hoá đơn" trên `success.tsx` thực hiện cùng thao tác (dùng cho trường hợp giấy hết, in hỏng, khách yêu cầu in lại).
3. **Data lifecycle**: dữ liệu hoá đơn tự xoá khi rời `success.tsx` quay về `advertise.tsx` (tương tự pattern hiện tại của kiosk).

Reference implementation: ``receipt_print/src/screens/WebViewReceiptScreen.tsx`` — đây là màn hình duy nhất của project demo hoạt động đúng kỳ vọng.

---

## Hiện trạng kiosk

Kiosk **đã có sẵn 80% hạ tầng**:

| Phần | Trạng thái | Vị trí |
|------|-----------|--------|
| Dependency `react-native-star-io10` | ✅ Done | `package.json` |
| `testPrinterConnection(ip)` | ✅ Done | `src/lib/printer.ts` |
| `printReceiptImage(ip, base64)` | ✅ Done | `src/lib/printer.ts` |
| Hook config IP máy in (AsyncStorage) | ✅ Done | `src/hooks/use-printer-config.ts` |
| Settings UI để cấu hình IP + test | ✅ Done | `app/settings.tsx` |
| Nút "In hoá đơn" trên 3 success screens | ⚠️ Có UI, nhưng chỉ show Alert | `app/success.tsx`, `app/split/success.tsx`, `app/custom/success.tsx` |
| **Render nội dung hoá đơn → ảnh PNG** | ❌ Chưa có | — |
| **HTML template hoá đơn** | ❌ Chưa có | — |

---

## Kết quả audit API hiện có (đã verify)

Đã kiểm tra response thực tế của 3 endpoint mà plan dự kiến sử dụng:

| Endpoint | Trạng thái | Thiếu gì |
|----------|-----------|----------|
| `GET /api/v1/kiosk/me` | ✅ **Đầy đủ** | Không thiếu. Có `branch.name`, `branch.address`, `branch.phone`, `branch.currency`, `branch.timezone`, `branch.locale` |
| `GET /api/v1/kiosk/orders` | ⚠️ **Thiếu vài field** | `tax_amount`, `service_charge` (DB đã có, controller chưa return). `line_total` không có nhưng dễ tính client (`qty × unit_price`). `discount_percent` chưa có trong schema |
| `POST /api/v1/kiosk/payments` | ⚠️ **Thiếu nhẹ** | Response không echo lại `method` code (card/qr/cash). Frontend tự track được vì chính frontend là bên submit |

### Quyết định: Phương án 2 — Sửa nhẹ backend (3 dòng)

**Đã chốt làm theo Phương án 2** thay vì Phương án 1 (thuần frontend). Lý do:
- Thay đổi backend rất nhẹ, không phá vỡ contract hiện tại (chỉ thêm field optional vào response)
- Tránh tính ước lượng VAT/service charge ở client (dễ sai lệch với DB → gây nhầm lẫn cho khách)
- Vẫn đúng tinh thần plan: **không endpoint mới, không template ở backend, không migration**

### Backend impact tổng kết

| Việc | Backend? |
|------|----------|
| Thêm endpoint mới | ❌ KHÔNG |
| Tạo Blade template trên backend | ❌ KHÔNG |
| Tạo bảng DB / migration mới | ❌ KHÔNG |
| Sửa logic business hiện tại | ❌ KHÔNG |
| **Thêm 3 dòng vào response Resource** | ✅ Có (xem [Bước 0.5](#bước-05--mở-rộng-response-backend-3-dòng)) |
| Frontend kiosk | ✅ Toàn bộ thay đổi nằm đây |

---

## Kiến trúc đề xuất (Phase 1)

### Flow tổng thể

```
[Payment thành công ở payment/{method}.tsx]
        ↓
[ReceiptProvider.setReceiptData(data)]
        ↓
[router.replace('/success')]
        ↓
[success.tsx mount]
        ↓
[useReceiptPrinter() auto-trigger]
        ↓
   ┌────────────────────────────────────────┐
   │ 1. buildReceiptHtml(data) → HTML string │
   │ 2. <HiddenReceiptCanvas html={html} />  │
   │    (WebView ẩn off-screen)              │
   │ 3. Đợi WebView render xong              │
   │ 4. captureRef → PNG base64              │
   │ 5. printReceiptImage(ip, base64)        │
   │ 6. Update status                        │
   └────────────────────────────────────────┘
        ↓
[Hiển thị status: "Đang in" / "Đã in" / "Lỗi → Bấm in lại"]
        ↓
[Nút "In lại" → gọi cùng useReceiptPrinter().print()]
        ↓
[IdleTimer 30s hoặc user touch → router về /advertise]
        ↓
[ReceiptProvider.clear()]
```

### Vì sao chọn HTML local thay vì fetch từ backend?

3 lựa chọn đã cân nhắc:

| Phương án | Đánh giá |
|-----------|----------|
| **A. HTML generate trong app (TS function)** | ✅ **Chọn cho Phase 1** |
| B. Fetch HTML từ `receipt-api` Laravel | Phải duy trì 2 backend, phức tạp hơn không cần thiết |
| C. Thêm endpoint vào godx backend | Phụ thuộc backend godx phải làm thêm |

Lý do chọn A:
- Kiosk đã có **toàn bộ data cần thiết** từ `useOrder()` + `usePayment()` + `/kiosk/me`
- Không cần round-trip mạng → in nhanh hơn
- **In được kể cả khi mất mạng** (quan trọng cho restaurant đông giờ peak)
- Template chỉ là 1 file TypeScript thuần — dễ chỉnh sửa khi cần
- Tương lai chuyển sang Phase 2 (backend-driven, xem [Note: Customize hoá đơn](#note-customize-hoá-đơn-yêu-cầu-tương-lai)) chỉ cần sửa 1 chỗ

### So sánh trực quan: Cách cũ (backend Blade) vs Phase 1 (TS local)

#### Cách cũ — render template ở backend (như `WebViewReceiptScreen.tsx` demo đang làm)

```
[App Kiosk]              [Laravel Backend]            [Máy in]
    │                          │                          │
    │  GET /api/receipt/123    │                          │
    ├─────────────────────────>│                          │
    │                          │ Render thermal.blade.php │
    │                          │ với data từ DB           │
    │   { html: "<html>..." }  │                          │
    │<─────────────────────────┤                          │
    │                          │                          │
    │ Load HTML vào WebView    │                          │
    │ Capture → PNG (base64)   │                          │
    │                          │                          │
    │ star-io10: printer.print(png)                       │
    ├─────────────────────────────────────────────────────>│
    │                          │                          │ In ✓
```

**Đặc điểm**: phụ thuộc backend + network. Mất mạng = không in được.

#### Phase 1 — render template ngay trong app (TypeScript)

```
[App Kiosk]                                              [Máy in]
    │                                                        │
    │ Đã có sẵn từ các API gọi trước đó:                     │
    │   - order data (từ useOrder)                           │
    │   - payment data (từ usePayment)                       │
    │   - store info (từ /kiosk/me)                          │
    │                                                        │
    │ const html = buildReceiptHtml(receiptData)             │
    │ ↑ function TS thuần, chạy ngay trong app               │
    │                                                        │
    │ Load HTML vào WebView (ẩn off-screen)                  │
    │ Capture → PNG (base64)                                 │
    │                                                        │
    │ star-io10: printer.print(png)                          │
    ├───────────────────────────────────────────────────────>│
    │                                                        │ In ✓
```

**Đặc điểm**: KHÔNG cần backend riêng cho việc render template. KHÔNG cần network. In được offline.

#### Flow đầy đủ Phase 1 (từ payment đến in xong)

```
[Khách thanh toán xong tại payment/{method}.tsx]
        │
        │ 1. Backend confirm payment OK
        ▼
[Build receipt data từ state có sẵn]
   - order = useOrder(tableId).data
   - payment = response từ POST /payments
   - store = useKioskMe().data
        │
        │ setReceiptData({
        │   store, items, totals, paymentMethod, txId, ...
        │ })
        ▼
[router.replace('/success')]
        │
        ▼
[success.tsx mount]
        │
        │ useReceiptPrinter() auto-trigger
        ▼
   ┌──────────────────────────────────────────────┐
   │ STEP 1: const html = buildReceiptHtml(data)  │
   │   → tạo HTML string ngay trong app           │
   │   → KHÔNG gọi API                            │
   ├──────────────────────────────────────────────┤
   │ STEP 2: <HiddenReceiptCanvas html={html} />  │
   │   → WebView ẩn render HTML                   │
   │   → đo height, đợi render xong               │
   ├──────────────────────────────────────────────┤
   │ STEP 3: captureRef → PNG base64              │
   │   → react-native-view-shot chụp WebView      │
   ├──────────────────────────────────────────────┤
   │ STEP 4: printReceiptImage(ip, base64)        │
   │   → react-native-star-io10 gửi ảnh tới       │
   │     máy in qua LAN (hoặc Bluetooth)          │
   └──────────────────────────────────────────────┘
        │
        │ Update status: 'success' | 'error'
        ▼
[UI hiển thị: "Đã in" / "Lỗi → Bấm in lại"]
        │
        │ User bấm "In lại" → gọi lại STEP 1-4
        │
        │ IdleTimer 30s hoặc user touch
        ▼
[router → /advertise]
        │
        ▼
[ReceiptProvider.clear() → data hoá đơn bị xoá]
```

#### Vai trò của các thư viện

```
┌─────────────────────────────────────────────────────────────┐
│  Layer trong app                                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  buildReceiptHtml(data)        ← Pure TS function           │
│         ↓ HTML string                                       │
│                                                             │
│  react-native-webview          ← Render HTML thành pixels   │
│         ↓ WebView mounted                                   │
│                                                             │
│  react-native-view-shot        ← Capture WebView → PNG      │
│         ↓ PNG base64                                        │
│                                                             │
│  react-native-star-io10        ← SDK máy in Star            │
│         ↓ ESC/POS commands                                  │
│                                                             │
│  Star MC-Print3 (LAN)          ← Phần cứng                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

3 thư viện này phối hợp để chuyển HTML → giấy in ra. SDK `react-native-star-io10` chỉ tham gia ở bước cuối (in ảnh), KHÔNG quan tâm HTML đến từ đâu — nên việc chuyển nguồn HTML từ backend sang local không ảnh hưởng tới việc dùng SDK.

---

## Cấu trúc file cần tạo/sửa

```
app/kiosk/src/
├── lib/
│   ├── printer.ts                     ← (có sẵn)
│   ├── receipt-template.ts            ← MỚI: buildReceiptHtml(data): string
│   └── receipt-types.ts               ← MỚI: ReceiptData interface
├── hooks/
│   ├── use-printer-config.ts          ← (có sẵn)
│   └── use-receipt-printer.ts         ← MỚI: orchestration hook
├── components/
│   └── hidden-receipt-canvas.tsx      ← MỚI: hidden WebView + view-shot
├── providers/
│   └── receipt-provider.tsx           ← MỚI: lưu receipt data ngắn hạn
└── app/
    ├── _layout.tsx                    ← SỬA: thêm ReceiptProvider vào provider tree
    ├── success.tsx                    ← SỬA: gọi useReceiptPrinter, render HiddenReceiptCanvas
    ├── split/success.tsx              ← SỬA: tương tự
    ├── custom/success.tsx             ← SỬA: tương tự
    └── payment/
        ├── card.tsx                   ← SỬA: gọi setReceiptData trước khi navigate
        ├── qr.tsx                     ← SỬA: tương tự
        ├── emoney.tsx                 ← SỬA: tương tự
        └── cash.tsx                   ← SỬA: tương tự
```

---

## Các bước triển khai

### Bước 0 — Cài dependency

```sh
npm install react-native-webview react-native-view-shot --legacy-peer-deps
```

Build native (bắt buộc, không dùng được Expo Go sau bước này):

```sh
npx expo run:ios --device      # hoặc
npx expo run:android --device
```

### Bước 0.5 — Mở rộng response backend (3 dòng)

**File**: `backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php`

#### Sửa method `orders()` — thêm tax & service charge

Trong mảng response (khoảng dòng 86-89), thêm:

```php
'subtotal' => (float) $order->subtotal,
'discount' => (float) $order->discount_amount,
'tax_amount' => (float) $order->tax_amount,           // ← THÊM
'service_charge' => (float) $order->service_charge,    // ← THÊM
'total' => (float) $order->total_amount,
'currency' => $device->branch?->currency ?? 'JPY',
```

#### Sửa method `pay()` — echo lại payment method code

Trong mảng response (khoảng dòng 170-176), thêm:

```php
'payment_id' => $payment->id,
'reference_no' => $payment->payment_code,
'status' => $payment->status->value,
'method' => $paymentMethod->code,                       // ← THÊM (card/qr/emoney/cash)
'amount_paid' => (float) $payment->amount,
'expires_at' => $payment->expires_at?->toIso8601String(),
'confirm_type' => $paymentMethod->is_auto_confirm ? 'auto' : 'manual',
```

#### Cập nhật TypeScript types (kiosk side)

**File**: `app/kiosk/src/types/kiosk.ts`

Thêm field tương ứng vào interface `Order` và `PaymentResponse`. Nếu types được generate từ Omnify schema YAML thì cần regenerate sau khi backend thay đổi.

#### Test

```sh
cd backend
php -d memory_limit=-1 vendor/bin/pest --filter Kiosk --compact
```

Update assertions trong:
- `backend/tests/Feature/Kiosk/KioskOrdersTest.php` — assert thêm `tax_amount`, `service_charge`
- `backend/tests/Feature/Kiosk/KioskPaymentsTest.php` — assert thêm `method`

> ⚠️ Lưu ý Laravel best practices: chỉ thêm field vào response, không sửa logic. Đây là backward-compatible change — các client cũ vẫn hoạt động bình thường, chỉ là không dùng field mới.

### Bước 1 — Tạo `src/lib/receipt-types.ts`

Define interface cho data hoá đơn:

```ts
export interface ReceiptItem {
  name: string;
  qty: number;
  unitPrice: number;
  lineTotal: number;
}

export interface ReceiptStoreInfo {
  name: string;
  address: string;
  phone: string;
}

export interface ReceiptData {
  store: ReceiptStoreInfo;
  orderNumber: string;
  orderedAt: string;          // formatted "DD/MM/YYYY HH:mm"
  items: ReceiptItem[];
  subtotal: number;
  discountPercent: number;
  discount: number;
  vatPercent: number;
  vat: number;
  total: number;
  customerPaid: number;
  change: number;
  paymentMethod: string;       // "card" | "qr" | "emoney" | "cash"
  transactionId: string;
  // Optional cho split payment
  splitInfo?: {
    current: number;           // hoá đơn thứ N
    total: number;             // tổng số người
    splitMode: 'equal' | 'custom';
  };
}
```

### Bước 2 — Tạo `src/lib/receipt-template.ts`

Port nội dung từ ``thermal.blade.php`` sang TypeScript template literal.

Quy tắc port:
- `{{ $order->store_name }}` → `${data.store.name}`
- `@foreach ($order->items as $item) ... @endforeach` → `${data.items.map(item => \`...\`).join('')}`
- `number_format($order->total, 0, ',', '.')` → `formatVnd(data.total)` (helper)
- HTML/CSS giữ nguyên 100%

```ts
export function buildReceiptHtml(data: ReceiptData): string {
  return `<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=384px, initial-scale=1" />
  <style>
    /* y nguyên CSS từ thermal.blade.php */
  </style>
</head>
<body>
  <p class="center store-name">${escapeHtml(data.store.name)}</p>
  <p class="center sub">${escapeHtml(data.store.address)}</p>
  <!-- ... -->
  ${data.items.map(item => `
    <div class="row">
      <span class="t col-name">${escapeHtml(item.name)}</span>
      <span class="t col-qty">x${item.qty}</span>
      <span class="t col-price">${formatVnd(item.lineTotal)}đ</span>
    </div>
  `).join('')}
  <!-- ... -->
</body>
</html>`;
}

function formatVnd(n: number): string {
  return n.toLocaleString('vi-VN');
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]!));
}
```

**Lưu ý**: PHẢI escape HTML để tránh XSS (tên món có thể chứa `<`, `>`, `&`).

### Bước 3 — Tạo `src/providers/receipt-provider.tsx`

Context lưu data hoá đơn cho giai đoạn từ payment → success → advertise:

```ts
interface ReceiptContextValue {
  receiptData: ReceiptData | null;
  setReceiptData: (data: ReceiptData) => void;
  clear: () => void;
}
```

Đặt trong provider tree, sau `AuthProvider`:

```
SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider
  → AuthProvider → TerminalProvider → PaymentFlowProvider
  → ReceiptProvider ← THÊM Ở ĐÂY
  → IdleTimer → Stack
```

### Bước 4 — Tạo `src/components/hidden-receipt-canvas.tsx`

Component render WebView ẩn để capture:

```tsx
interface Props {
  html: string;
  onReady: (captureRef: React.RefObject<View>) => void;
}

// Position: absolute, off-screen (left: -10000)
// Width: 384px (matching RECEIPT_VIEW_WIDTH)
// WebView injectedJavaScript đo height → setHeight → onReady
```

Logic giống hệt phần render WebView trong `WebViewReceiptScreen.tsx` (lines 148-182), nhưng đặt off-screen thay vì hiển thị.

### Bước 5 — Tạo `src/hooks/use-receipt-printer.ts`

Hook orchestration toàn bộ flow:

```ts
type PrintStatus = 'idle' | 'preparing' | 'rendering' | 'capturing' | 'printing' | 'success' | 'error';

interface UseReceiptPrinterReturn {
  status: PrintStatus;
  error: string | null;
  print: () => Promise<void>;       // chạy lại từ đầu
  canvasProps: { html: string; ... }; // pass vào HiddenReceiptCanvas
}

export function useReceiptPrinter(): UseReceiptPrinterReturn {
  const { receiptData } = useReceiptContext();
  const { printerIp } = usePrinterConfig();
  // ... build HTML, manage WebView ref, capture, print
}
```

### Bước 6 — Sửa các payment screen để set receipt data

Trong `app/payment/card.tsx`, `qr.tsx`, `emoney.tsx`, `cash.tsx`:

Sau khi payment thành công (callback `confirm` API trả về OK):

```ts
const { setReceiptData } = useReceiptContext();
const { data: order } = useOrder(tableId);

// Trước khi navigate
setReceiptData(buildReceiptDataFromOrderAndPayment(order, payment, storeInfo));
router.replace('/success');
```

Tạo helper `buildReceiptDataFromOrderAndPayment(order, payment, store)` ở `src/lib/receipt-data-builder.ts` để tránh duplicate code 4 chỗ.

### Bước 7 — Sửa `app/success.tsx`

Thay `handlePrintReceipt()` hiện tại (chỉ show Alert) bằng:

```tsx
export default function SuccessScreen() {
  const { receiptData } = useReceiptContext();
  const { status, error, print, canvasProps } = useReceiptPrinter();
  const { printerIp } = usePrinterConfig();

  // Auto-print khi mount
  useEffect(() => {
    if (printerIp && receiptData && status === 'idle') {
      print();
    }
  }, []);

  return (
    <View>
      {/* UI hiện tại: success ring, receipt card... */}

      {/* Status hiển thị */}
      {status === 'printing' && <Text>{t('kiosk.printing')}</Text>}
      {status === 'success' && <Text>{t('kiosk.print_success')}</Text>}
      {status === 'error' && (
        <View>
          <Text>{t('kiosk.print_error')}: {error}</Text>
          <Button onPress={print}>{t('kiosk.reprint')}</Button>
        </View>
      )}

      <Button onPress={print}>{t('kiosk.reprint_receipt')}</Button>

      {/* Hidden WebView để render */}
      {receiptData && <HiddenReceiptCanvas {...canvasProps} />}
    </View>
  );
}
```

### Bước 8 — Apply tương tự cho `split/success.tsx` và `custom/success.tsx`

Cùng pattern, chỉ khác data:
- `split/success.tsx`: `splitInfo: { current, total, splitMode: 'equal' }`
- `custom/success.tsx`: `splitInfo: { current, total, splitMode: 'custom' }`

### Bước 9 — Clear receipt data khi rời success

Trong `useEffect` cleanup hoặc trong `IdleTimer` callback:

```ts
useEffect(() => {
  return () => {
    // Khi unmount success → clear data
    clear();
  };
}, []);
```

Hoặc handle ở `IdleTimer` khi navigate sang `/advertise`.

### Bước 10 — Thêm i18n strings

`src/i18n/{ja,en,vi}.json` — thêm keys:
- `kiosk.printing` — "Đang in..."
- `kiosk.print_success` — "Đã in hoá đơn"
- `kiosk.print_error` — "Lỗi in"
- `kiosk.reprint` — "Thử in lại"
- `kiosk.reprint_receipt` — "In hoá đơn"
- `kiosk.no_printer_configured` — "Chưa cấu hình máy in"

### Bước 11 — Test

- ✅ Auto-print hoạt động khi vào success.tsx
- ✅ Nút "In lại" hoạt động
- ✅ Khi máy in offline → hiển thị error, cho phép retry
- ✅ Khi chưa cấu hình IP → hiển thị thông báo, không crash
- ✅ Data tự clear khi về advertise (kiểm tra bằng cách quay lại success → không có data cũ)
- ✅ Ký tự Vietnamese hiển thị đúng (UTF-8)
- ✅ Số tiền format đúng (1.234.567đ)
- ✅ Split payment: hiển thị "Hoá đơn 1/3"

---

## Câu hỏi còn để mở (cần trả lời trước khi code)

> ✅ **Đã trả lời** (xem [Kết quả audit API](#kết-quả-audit-api-hiện-có-đã-verify)):
> - ~~Data từ `useOrder()` đầy đủ chưa?~~ → Thiếu nhẹ, sửa backend 2 dòng (Bước 0.5)
> - ~~Store info lấy từ đâu?~~ → Từ `/kiosk/me`, đã có sẵn đầy đủ

1. **Timing auto-print**:
   - In ngay khi mount success? Hay đợi 1-2s cho user thấy ✓ trước rồi mới in?
   - **Đề xuất**: đợi 800ms để animation success ring chạy xong, rồi mới print

2. **Retry khi auto-print fail**:
   - Tự retry 1 lần sau 2s? Hay chỉ show error và đợi user bấm?
   - **Đề xuất**: KHÔNG auto-retry. Show error + nút "In lại" để user chủ động.

3. **Số bản in**:
   - 1 bản (cho khách)? Hay 2 bản (khách + lưu)?
   - **Đề xuất**: 1 bản cho Phase 1. Cấu hình thêm trong Settings nếu cần sau.

4. **Split payment in hoá đơn riêng từng người, hay 1 hoá đơn tổng?**
   - **Đề xuất**: Mỗi người 1 hoá đơn riêng (hiển thị "Hoá đơn 1/3, số tiền: X"). In ngay sau khi mỗi người thanh toán xong.

5. **Discount percent có cần không?**
   - Hiện DB không có `discount_percent`, chỉ có `discount_amount`
   - Plan template hiện hiển thị "Giảm giá (10%):". Nếu cần "%" thì:
     - Option A: backend thêm field (cần check coupon/promo logic)
     - Option B: bỏ "%" trên hoá đơn, chỉ hiển thị số tiền giảm
   - **Đề xuất**: Option B cho Phase 1 (đơn giản, đủ thông tin)

---

## Note: Customize hoá đơn (yêu cầu tương lai)

> **Quan trọng**: Plan này (Phase 1) chọn approach **HTML local trong app**. Khi khách hàng yêu cầu customize hoá đơn (logo riêng, layout riêng), cần nâng cấp lên Phase 2.

### 3 mức độ customize và giải pháp tương ứng

#### Mức 1 — Branding cơ bản (đa số khách hàng chỉ cần mức này)

Khách muốn đổi:
- Logo cửa hàng
- Tên/địa chỉ/SĐT
- Câu chào header/footer
- Bật/tắt 1 số phần (QR, VAT...)

**Giải pháp**: Config JSON trong backend, app có template CỐ ĐỊNH đọc config.

```typescript
{
  storeName: "FAMGIA STORE",
  logoUrl: "https://cdn.../logo.png",
  headerMessage: "Chúc quý khách ngon miệng!",
  footerMessage: "Hẹn gặp lại!",
  showQrCode: true,
  showVat: true,
}
```

| Ưu | Nhược |
|----|-------|
| Đơn giản, nhanh | Layout cố định |
| Customize qua admin UI dễ | Không đổi được structure |
| Không cần release app khi đổi logo/text | |

#### Mức 2 — Tuỳ biến có giới hạn

- Chọn 1 trong vài layout có sẵn (Cafe / Restaurant / Retail)
- Sắp xếp lại thứ tự một số block

**Giải pháp**: App có sẵn vài templates, backend chọn template nào + truyền config.

```ts
const TEMPLATES = {
  'cafe-v1': buildCafeReceiptHtml,
  'restaurant-v1': buildRestaurantReceiptHtml,
  'retail-v1': buildRetailReceiptHtml,
};
```

| Ưu | Nhược |
|----|-------|
| Vẫn nhanh, không cần network | Thêm template mới = release app |
| Khách có nhiều lựa chọn | Vẫn bị giới hạn |

#### Mức 3 — Customize hoàn toàn (full HTML/CSS)

- Tự thiết kế layout từ đầu
- Mỗi tenant/branch hoá đơn khác nhau hoàn toàn

**Giải pháp**: Backend lưu HTML template (Blade/Handlebars), app fetch HTML.

```
Admin UI (web) → Sửa template HTML/CSS → Lưu DB

Kiosk → POST /api/v1/kiosk/receipts/render
  Body: { orderId, paymentData }
Backend → Render template với engine → trả { html, meta }
Kiosk → Load HTML vào WebView (giống WebViewReceiptScreen.tsx)
```

| Ưu | Nhược |
|----|-------|
| Linh hoạt 100% | Phụ thuộc backend + network |
| Mỗi tenant 1 template riêng | Mất mạng → không in được |
| Đổi layout không cần release app | Backend cần admin UI để khách edit template |

### Quan trọng: Kiến trúc kỹ thuật trong app KHÔNG thay đổi

Dù chọn mức nào, flow trong kiosk vẫn:

```
[Có HTML] → WebView render → captureRef → PNG → in
```

Cái khác duy nhất là **nguồn HTML**. Tương lai chuyển từ Phase 1 → Phase 2 (Mức 3) **chỉ cần sửa 1 chỗ duy nhất** trong `useReceiptPrinter()`:

```ts
// Phase 1
const html = buildReceiptHtml(receiptData);

// Phase 2 (khi cần customize)
const html = await fetchReceiptHtmlFromBackend(receiptData);
```

### Câu hỏi cần làm rõ với khách hàng/PM trước khi vào Phase 2

1. Khách hàng yêu cầu customize ở mức nào? (Logo + text? Hay layout hoàn toàn?)
2. Có nhiều tenant không? (1 chuỗi cửa hàng dùng chung? Hay nhiều khách hàng độc lập, mỗi người 1 template?)
3. Customize qua đâu? Có UI admin cho khách tự chỉnh không? Hay dev/sale chỉnh giúp?
4. Kiosk có yêu cầu in được khi mất mạng không?

### Lộ trình đề xuất

1. **Phase 1 (plan này)**: Implement với HTML local, branding hard-code/config qua Settings. **Đáp ứng được Mức 1 đơn giản**.
2. **Phase 2** (khi có yêu cầu cụ thể): Tuỳ scope → chọn Mức 1/2/3 phù hợp.

---

## Note: Bluetooth (yêu cầu tương lai)

Máy in **MC-Print3 (model MCP31LB)** hỗ trợ cả LAN và Bluetooth. Hiện Phase 1 chỉ dùng LAN (đã hoạt động). Khi cần chuyển sang Bluetooth:

### Code thay đổi tối thiểu

```ts
// Hiện tại
settings.interfaceType = InterfaceType.Lan;
settings.identifier = '192.168.1.232';

// Bluetooth
settings.interfaceType = InterfaceType.Bluetooth;
settings.identifier = 'BT_ADDRESS hoặc tên máy in';
```

Toàn bộ API in (`StarXpandCommand`, `print()`, v.v.) **giữ nguyên 100%**.

### Lưu ý theo platform

#### Android — đơn giản
- Cần xin runtime permission `BLUETOOTH_CONNECT` (Android 12+)
- Ghép đôi máy in qua Settings của tablet trước
- Không có rào cản đặc biệt

#### iOS — có rào cản lớn
- Classic Bluetooth yêu cầu **Apple MFi Program approval** (quy trình phức tạp với Apple)
- Thêm `UISupportedExternalAccessoryProtocols` với `jp.star-m.starpro` vào Info.plist
- Alternative: dùng **BluetoothLE** (không cần MFi) nhưng phụ thuộc hardware revision

### Tính năng tìm kiếm máy in (Discovery)

`star-io10` có sẵn `StarDeviceDiscoveryManager` để quét tìm máy in qua cả LAN lẫn Bluetooth. Có thể nâng cấp Settings screen thêm nút "Tìm máy in" thay vì nhập IP thủ công.

### So sánh nhanh

| | LAN | Bluetooth |
|--|-----|-----------|
| Tốc độ kết nối | Tức thì | ~1-3s handshake |
| Tốc độ in | Nhanh | Hơi chậm hơn |
| Tầm xa | Không giới hạn (qua mạng) | ~10m |
| Setup | Cần biết IP | Cần ghép đôi cấp OS trước |
| iOS rào cản | Không | Cần MFi (classic BT) |

---

## References

- ``receipt_print/src/screens/WebViewReceiptScreen.tsx`` — reference implementation
- ``receipt-api/resources/views/receipts/thermal.blade.php`` — template gốc cần port
- ``receipt_print/src/utils/printer.ts`` — printer wrapper reference
- ``app/kiosk/src/lib/printer.ts`` — printer utilities hiện có của kiosk
- [`app/kiosk/docs/settings-screen-plan.md`](../settings-screen-plan.md) — plan trước về Settings screen + printer config

---

## Tổng kết implementation

> Triển khai ngày **2026-05-17**. Phần này ghi nhận kết quả thực tế + các điểm khác biệt so với plan ban đầu.

### Files đã tạo/sửa

#### Backend (chỉ thêm field, không endpoint mới)
- [`backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php`](../../../../backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php) — `orders()` thêm `tax_amount`, `service_charge`; `pay()` thêm `method`
- [`backend/tests/Feature/Kiosk/KioskOrdersTest.php`](../../../../backend/tests/Feature/Kiosk/KioskOrdersTest.php) + [`backend/tests/Feature/Kiosk/KioskPaymentsTest.php`](../../../../backend/tests/Feature/Kiosk/KioskPaymentsTest.php) — assert keys mới
- ✅ **25 tests passed**

#### Frontend — Files mới
- ``src/lib/receipt-types.ts`` — interface `ReceiptData`, `ReceiptItem`, `ReceiptStore`, `ReceiptSplitInfo`
- ``src/lib/receipt-template.ts`` — `buildReceiptHtml(data): string`, port từ Blade template
- ``src/lib/receipt-data-builder.ts`` — `buildReceiptData({ order, payment, store, splitInfo })`
- ``src/components/hidden-receipt-canvas.tsx`` — WebView ẩn off-screen + measure height
- ``src/hooks/use-receipt-printer.ts`` — orchestration: render → capture → in

#### Frontend — Files sửa
- [`src/providers/auth-provider.tsx`](../../src/providers/auth-provider.tsx) — `DeviceInfo.branch` thêm `address`, `phone`, `currency`, `timezone`, `locale`
- [`src/types/kiosk.ts`](../../src/types/kiosk.ts) — `Order.tax_amount`, `PaymentResult.method`
- [`app/success.tsx`](../../app/success.tsx) — auto-print sau 800ms + nút "In lại" + status banner
- [`app/split/success.tsx`](../../app/split/success.tsx) — tương tự, mỗi người 1 hoá đơn
- [`app/custom/success.tsx`](../../app/custom/success.tsx) — tương tự, custom split
- [`src/i18n/{vi,en,ja}.json`](../../src/i18n/) — thêm 3 keys: `success_print_success`, `success_print_error`, `success_reprint`

#### Dependency
- Cài thêm `react-native-view-shot` (`react-native-webview` đã có sẵn từ trước)

### Khác biệt so với plan ban đầu

| Plan ban đầu | Thực tế | Lý do |
|--------------|---------|-------|
| Tạo `src/providers/receipt-provider.tsx` | ❌ Không tạo | `PaymentFlowProvider.state.order` đã có sẵn data + `reset()` đã clear khi về advertise → không cần provider riêng |
| Sửa `app/_layout.tsx` thêm provider | ❌ Không sửa | Không có provider mới để thêm |
| Sửa 4 payment screens set receipt data | ❌ Không sửa | Payment screens đã navigate với URL params + đã set `state.order` qua `PaymentFlowProvider`; success screens build receipt data inline từ các nguồn này |
| `pixelRatio: 1.5` trong `captureRef` | ⚠️ Đổi sang `width: 576` | API `react-native-view-shot` v4+ bỏ `pixelRatio`, dùng `width`/`height` trực tiếp |
| Component tách riêng cho ReceiptProvider lifecycle | ❌ Bỏ | Lifecycle tự nhiên: `PaymentFlowProvider.reset()` (đã có sẵn trong `goHome`) clear hết state.order + state.payments. Auto-print chỉ chạy 1 lần qua `hasAutoPrintedRef` |

Plan **đơn giản hơn dự kiến** vì kiosk đã có sẵn nhiều primitive cần thiết.

### Behavior thực tế

| Hành vi | Triển khai |
|---------|-----------|
| Auto-print | Sau 800ms khi vào success.tsx (đợi animation tick xanh) |
| Manual reprint | Nút "In hoá đơn" → "In lại" sau khi đã in xong, gọi lại cùng function |
| Auto-print không lặp | `hasAutoPrintedRef` (full payment) và `lastPrintedRefRef` (split/custom theo `reference_no`) |
| Error UI | Banner đỏ với error code + nút "In lại" |
| Success UI | Banner xanh "Đã in hoá đơn" |
| Disabled khi đang in | Nút "In lại" disable trong status `rendering` / `printing` |
| Data clear | Tự động qua `PaymentFlowProvider.reset()` khi navigate về `/advertise` |
| Split payment | Mỗi người 1 hoá đơn, có dòng "Hoá đơn N/M — Chia đều" |
| Custom split | Tương tự, mode "Tuỳ chỉnh" |
| Offline support | ✅ In được không cần network (HTML local) |

### Quyết định đã chốt cho 5 câu hỏi mở

1. **Timing auto-print**: 800ms delay (như đề xuất)
2. **Retry**: Không auto-retry, chỉ show error + nút manual (như đề xuất)
3. **Số bản in**: 1 bản (như đề xuất)
4. **Split payment**: Mỗi người 1 hoá đơn riêng (như đề xuất)
5. **Discount percent**: Bỏ "%", chỉ hiển thị số tiền giảm (như đề xuất)

### Cần làm trước khi chạy thử

Phải build native lại vì có dependency mới (`react-native-view-shot`):

```sh
cd app/kiosk
npx expo run:ios --device      # hoặc
npx expo run:android --device
```

Không dùng được Expo Go nữa (cả `star-io10` và `view-shot` đều là native modules).

### TODO không nằm trong scope Phase 1

- Cấu hình logo (text hard-code "FAMGIA STORE" + lấy `name`/`address`/`phone` từ `/kiosk/me`)
- Customize hoá đơn qua admin UI (xem [Note: Customize hoá đơn](#note-customize-hoá-đơn-yêu-cầu-tương-lai))
- Chuyển sang Bluetooth (xem [Note: Bluetooth](#note-bluetooth-yêu-cầu-tương-lai))
- QR code trên hoá đơn (template hiện không có; có thể thêm sau bằng SVG inline)
- Tích hợp test E2E thực tế với máy in (chỉ test được khi có hardware)
