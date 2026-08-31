# Kiosk App — User Flows

## Flow tổng quan

```
┌──────────┐         ┌────────────┐  1 tap   ┌──────────┐
│          │         │            │────────▶│          │
│  Login   │────────▶│ Advertise  │         │ QR Scan  │
│(pairing) │  pair   │  (idle)    │         │          │
│          │  OK     │            │         │          │
└──────────┘         └──┬──▲──────┘         └────┬─────┘
                        │  │                     │
                 5 taps │  │                     │
                 (3s)   │  │                     │
                        ▼  │                     │
                 ┌─────────┴──┐                  │
                 │  Settings  │                  │
                 │ (passcode) │                  │
                 └────────────┘                  │
                           │                     │
                           │              ┌──────┴──────┐
                           │              │             │
                           │         QR OK│        QR fail
                           │              │             │
                           │              │      ┌──────▼──────┐
                           │              │      │ Select Table │
                           │              │      │  (manual)    │
                           │              │      └──────┬──────┘
                           │              │             │
                           │              └──────┬──────┘
                           │                     │
                           │              ┌──────▼──────┐
                           │              │  Checkout   │
                           │              │ (order +    │
                           │              │  method)    │
                           │              └──────┬──────┘
                           │                     │
                           │         ┌───────────┼───────────┬───────────┐
                           │         │           │           │           │
                           │    ┌────▼───┐  ┌────▼───┐ ┌────▼────┐ ┌────▼───┐
                           │    │  Card  │  │   QR   │ │ E-Money │ │  Cash  │
                           │    │        │  │ Wallet │ │         │ │        │
                           │    └───┬────┘  └───┬────┘ └────┬────┘ └───┬────┘
                           │        │           │           │           │
                           │        ├───────────┼───────────┼───────────┤
                           │        │           │           │           │
                           │   ┌────▼───────────▼───────────▼───────────▼────┐
                           │   │                                             │
                           │   │         Payment thành công?                 │
                           │   │                                             │
                           │   └──────────┬─────────────────┬────────────────┘
                           │              │                  │
                           │           YES│               NO │
                           │              │                  │
                           │       ┌──────▼──────┐   ┌──────▼──────┐
                           │       │   Success   │   │Payment Error│
                           │       │  (receipt)  │   │  (retry /   │
                           │       └──────┬──────┘   │   cancel)   │
                           │              │          └──────┬──────┘
                           │   10s auto   │                 │
                           └──────────────┘          cancel │
                                                           │
                                                    ┌──────▼──────┐
                                                    │  Checkout   │
                                                    │  (quay lại) │
                                                    └─────────────┘
```

---

## Flow chi tiết: Advertise (Idle Screen)

```
┌──────────────────────────────────────────────────────────────────────┐
│  ADVERTISE — Màn hình chờ khách                                     │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │  Background: Near Black (#1A1A1A)                              │  │
│  │                                                                │  ���
│  │  [Branch Name]  ← uppercase, tracking-widest, white           │  │
│  │                                                                │  │
│  │  "セルフレジでお会計"  ← 5xl, extrabold, white                  │  │
│  │  (headline quảng cáo)                                          │  │
│  │                                                                │  │
│  │  "画面をタップしてください"  ← base, white/80%                   │  │
│  │  (hướng dẫn tap)                                               │  │
│  │                                                                │  │
│  │  ─── Toàn bộ vùng này là Pressable ───                        │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  ──────────── divider (white/20%) ────────────                       │
│  [日本語]  [English]  [Tiếng Việt]  ← language switcher              │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Tap Gesture Logic

```
Tap 1 lần
    │
    ├─── chờ 700ms ───▶ Không có tap thứ 2 ───▶ Chuyển sang /scan
    │
    └─── Tap thứ 2 trong 700ms ───▶ Bắt đầu "secret mode"
                                          │
                                          ├─── Đạt 5 taps trong 3s ───▶ Chuyển sang /settings
                                          │
                                          └─── Không đạt 5 taps trong 3s ───▶ Chuyển sang /scan
```

| Hành động | Điều kiện | Kết quả |
|-----------|-----------|---------|
| 1 tap duy nhất | Không tap thêm trong 700ms | → QR Scan |
| 5 taps liên tục | Trong vòng 3 giây | → Settings (secret) |
| 2-4 taps | Hết 3 giây mà chưa đủ 5 | → QR Scan (fallback) |

**Lưu ý cho designer**:
- Không cần UI indicator cho secret gesture (ẩn hoàn toàn, chỉ staff biết).
- **Image Carousel**: Màn hình Advertise cần thiết kế dạng slideshow ảnh tự động trượt (auto-swipe). Ảnh quảng cáo/khuyến mãi sẽ lấy từ backend, hiển thị full-screen và tự động chuyển slide. Designer cần thiết kế:
  - Layout ảnh full-screen (phía sau text overlay)
  - Dot indicators hoặc progress bar cho biết đang ở slide nào
  - Transition animation giữa các slides (fade hoặc slide horizontal)
  - Đảm bảo text (headline, branch name) vẫn đọc được trên nền ảnh (overlay gradient hoặc semi-transparent background)

---

## Flow chi tiết: Login

```
┌─────────────────────────────────────────────────┐
│                  LOGIN                           │
├─────────────────────────────────────────────────┤
│                                                 │
│  [Language Switcher: ja | en | vi]              │
│                                                 │
│  ┌─────────────────────────────┐               │
│  │  Nhập 6-digit pairing code │               │
│  │  [ _ _ _ _ _ _ ]           │               │
│  └─────────────────────────────┘               │
│                                                 │
│  [Pair Device]                                  │
│                                                 │
│  States:                                        │
│  • Empty: chờ nhập code                         │
│  • Loading: đang verify                         │
│  • Error: code sai / hết hạn                   │
│                                                 │
└─────────────────────────────────────────────────┘
```

---

## Flow chi tiết: Payment (Card/QR/E-Money)

```
┌──────────────────────────────────────────────────────────────────────┐
│  PAYMENT FLOW (Card example - QR và E-Money tương tự)               │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌─── Order Summary (40%) ───┐  ┌─── Payment Area (60%) ──────────┐ │
│  │                           │  │                                  │ │
│  │  Item 1       ¥1,200     │  │  STATE 1: Waiting                │ │
│  │  Item 2         ¥800     │  │  ┌────────────────────────────┐  │ │
│  │  ────────────────────    │  │  │  💳 Đưa thẻ vào terminal  │  │ │
│  │  Subtotal     ¥2,000     │  │  │                            │  │ │
│  │  Tax (10%)      ¥200     │  │  │  [animation: pulse ring]   │  │ │
│  │  ────────────────────    │  │  │                            │  │ │
│  │  TOTAL        ¥2,200     │  │  │  Status: "Processing..."   │  │ │
│  │                           │  │  └────────────────────────────┘  │ │
│  │                           │  │                                  │ │
│  └───────────────────────────┘  │  STATE 2: Error ⚠️              │ │
│                                  │  ┌────────────────────────────┐  │ │
│                                  │  │  ⚠️ エラーが発生しました   │  │ │
│                                  │  │  "Card declined" / timeout │  │ │
│                                  │  │                            │  │ │
│                                  │  │  [Cancel]  [Retry]         │  │ │
│                                  │  └────────────────────────────┘  │ │
│                                  │                                  │ │
│                                  │  STATE 3: Not Configured         │ │
│                                  │  ┌────────────────────────────┐  │ │
│                                  │  │  💳 Terminal not setup     │  │ │
│                                  │  │                            │  │ │
│                                  │  │  [Back]                    │  │ │
│                                  │  └────────────────────────────┘  │ │
│                                  └──────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Flow chi tiết: Payment Error States

Mỗi payment method có 3 error states cần thiết kế:

### 1. Terminal Error (Card / QR / E-Money)

```
Trigger: Terminal trả về error (thẻ bị từ chối, timeout, kết nối mất)

┌──────────────────────────────────┐
│          ⚠️                      │
│                                  │
│  [Error message from terminal]   │
│  VD: "カードが読み取れません"       │
│      "Card could not be read"    │
│      "Không đọc được thẻ"        │
│                                  │
│  ┌──────────┐  ┌──────────┐     │
│  │  Cancel  │  │  Retry   │     │
│  │(outline) │  │(primary) │     │
│  └──────────┘  └──────────┘     │
│                                  │
└──────────────────────────────────┘

Actions:
• Cancel → gọi fail() API → quay về Checkout
• Retry → reset terminal → thử lại payment
```

### 2. Terminal Not Configured (Card / E-Money)

```
Trigger: Device chưa setup terminal trong Settings

┌──────────────────────────────────┐
│          💳                      │
│                                  │
│  "端末が設定されていません"          │
│  "Terminal not configured"       │
│                                  │
│  ┌──────────┐                    │
│  │  Back    │                    │
│  │(outline) │                    │
│  └──────────┘                    │
│                                  │
└──────────────────────────────────┘

Action: Back → quay về Checkout
```

### 3. API/Network Error (tất cả methods)

```
Trigger: submit payment API thất bại (network, server error)

┌──────────────────────────────────┐
│                                  │
│  [Error text in destructive red] │
│  VD: "ネットワークエラー"           │
│                                  │
│  Hiển thị inline dưới action     │
│  area, không phải full-screen    │
│                                  │
└──────────────────────────────────┘

Không block UI, user vẫn có thể retry hoặc cancel.
```

---

## Flow chi tiết: Success → Auto-redirect

```
┌──────────────────────────────────────────────┐
│                SUCCESS                        │
├──────────────────────────────────────────────┤
│                                              │
│              ✓ (checkmark)                   │
│                                              │
│     "お支払いが完了しました"                    │
│     "Payment completed"                      │
│                                              │
│     Reference: #PAY-20260505-001             │
│     Method: Card                             │
│     Amount: ¥2,200                           │
│                                              │
│     [Print Receipt]                          │
│                                              │
│     ── Auto-redirect in 10s ──               │
│     (countdown bar hoặc text)                │
│                                              │
└──────────────────────────────────────────────┘

Flow:
• 10 giây → tự redirect về Advertise
• Tap "Print Receipt" → in receipt qua thermal printer
• Nếu printer không kết nối → hiển thị warning nhưng vẫn redirect
```

---

## Flow chi tiết: Settings (Protected)

```
┌─────────────────┐         ┌─────────────────────────────┐
│  Passcode Gate  │  OK     │  Settings Panel             │
│                 │────────▶│                             │
│  [_ _ _ _ _]   │         │  • Printer IP: [______]    │
│  5-digit PIN    │         │    [Test Connection]        │
│                 │         │                             │
│  Wrong → shake  │         │  • Terminal config          │
│  + error text   │         │    [Test Terminal]          │
│                 │         │                             │
└─────────────────┘         │  • Language: [ja▼]         │
                            │                             │
                            │  [Logout]                   │
                            └─────────────────────────────┘
```

---

## Idle Timer Flow

```
User không thao tác
        │
        ▼ (configurable timeout)
┌───────────────────┐
│  Có đang ở giữa   │
│  payment flow?    │
└───────┬───────────┘
        │
   YES  │         NO
   ─────┤    ─────────────────┐
        │                     │
        ▼                     ▼
  Không redirect       Redirect → Advertise
  (chờ payment         (reset state)
   hoàn tất)
```

---

## Screen States Matrix

| Screen | Default | Loading | Error | Empty | Success |
|--------|---------|---------|-------|-------|---------|
| Login | Form | Spinner on button | "Code invalid" red text | - | Redirect |
| Advertise | Brand display | - | - | - | - |
| QR Scan | Camera active | - | "Cannot read QR" + fallback button | - | Redirect to checkout |
| Select Table | Zone grid | Skeleton | API error + back button | "No tables" | Redirect to checkout |
| Checkout | Order + methods | Skeleton (full) | "Order not found" + back | - | - |
| Payment Card | Waiting animation | Processing status | **Terminal error + Retry/Cancel** | Not configured | Redirect to success |
| Payment QR | QR display | Processing status | **Terminal error + Retry/Cancel** | - | Redirect to success |
| Payment E-Money | Waiting animation | Processing status | **Terminal error + Retry/Cancel** | Not configured | Redirect to success |
| Payment Cash | Confirm button | Submitting | **API error inline** | - | Redirect to success |
| Success | Receipt info | - | Print failed warning | - | Auto-redirect 10s |
| Settings | Config form | Test connection | Test failed | - | Toast success |
