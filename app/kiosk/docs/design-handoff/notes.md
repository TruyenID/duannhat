# Kiosk App — Design Handoff Notes

## Tổng quan

App thanh toán tự phục vụ (self-service payment terminal) cho nhà hàng/quán cafe.
Khách hàng scan QR trên bàn → xem order → chọn phương thức thanh toán → hoàn tất.

- **Brand**: Betoya
- **Device**: iPad tablet (landscape only)
- **Frame size Figma**: 1194 x 834 px
- **Theme**: Light mode only
- **Ngôn ngữ**: Japanese (mặc định), English, Vietnamese

---

## Screen Inventory (14 màn hình + error states)

### Flow chính

| # | Tên | Mô tả | Layout |
|---|-----|--------|--------|
| 1 | Login | Nhập 6-digit pairing code để kết nối device | Full-screen, centered form |
| 2 | Advertise | Màn hình idle/chờ khách, hiển thị brand + tên chi nhánh | Full-screen, tap anywhere để vào scan |
| 3 | QR Scan | Camera scanner đọc QR code trên bàn | Full-screen camera view |
| 4 | Select Table | Chọn bàn thủ công (fallback khi QR không hoạt động) | Grid layout theo zone |
| 5 | Checkout | Xem order + chọn phương thức thanh toán | 2-column (40/60) |
| 6 | Payment - Card | Thanh toán bằng thẻ (giao tiếp với terminal) | 2-column (40/60) |
| 7 | Payment - QR | Thanh toán bằng QR wallet (hiển thị mã QR) | 2-column (40/60) |
| 8 | Payment - E-Money | Thanh toán bằng thẻ IC (e-money) | 2-column (40/60) |
| 9 | Payment - Cash | Thanh toán tiền mặt | 2-column (40/60) |
| 10 | Success | Hoàn tất, hiển thị receipt + nút in | Full-screen, centered |
| 11 | Settings | Cấu hình printer, terminal, logout (cần passcode) | Full-screen, form layout |

### Payment Error States (cần thiết kế riêng)

| # | Tên | Trigger | UI |
|---|-----|---------|-----|
| E1 | Terminal Error | Thẻ bị từ chối, timeout, mất kết nối terminal | Icon ⚠️ + error message + [Cancel] [Retry] |
| E2 | Terminal Not Configured | Device chưa setup terminal trong Settings | Icon 💳 + message + [Back] |
| E3 | Network/API Error | Gọi API thanh toán thất bại | Inline red text dưới action area (không block UI) |
| E4 | Order Not Found | Order đã bị huỷ hoặc không tồn tại | Full-screen error + [Back] button |

### States cần thiết kế cho mỗi màn

- **Default** — trạng thái bình thường
- **Loading** — skeleton/spinner khi đang fetch data
- **Error** — lỗi mạng, lỗi thanh toán, timeout
- **Empty** — không có data (ví dụ: order trống)
- **Success** — hoàn tất thao tác

---

## Layout Rules

### 2-Column Layout (Checkout + Payment screens)

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  ┌──────────────┐   ┌────────────────────────────┐  │
│  │              │   │                            │  │
│  │  Order       │   │   Payment Action           │  │
│  │  Summary     │   │   (method picker /         │  │
│  │              │   │    terminal status /        │  │
│  │  40% width   │   │    QR code display)        │  │
│  │              │   │                            │  │
│  │              │   │   60% width                │  │
│  │              │   │                            │  │
│  └──────────────┘   └────────────────────────────┘  │
│                                                     │
└─────────────────────────────────────────────────────┘
```

- Order summary bên trái luôn hiển thị trong payment flow
- Bên phải thay đổi tùy theo payment method

### Full-Screen Layout (Advertise, Scan, Success)

- Không có navigation bar
- Content centered cả ngang và dọc
- Touch target toàn màn hình (advertise)

---

## Interaction Notes

| Interaction | Chi tiết |
|-------------|----------|
| Advertise → Scan | Tap anywhere trên màn hình |
| Secret gesture | Tap 5 lần trong 3 giây → vào Settings |
| Settings access | Cần nhập passcode 5 số |
| Auto-redirect | Sau payment success, tự quay về Advertise sau 10 giây |
| Idle timeout | Không thao tác lâu → tự quay về Advertise |
| Language switcher | Có trên Login screen, cho phép chọn ja/en/vi |

---

## Payment Methods (2x2 Grid trên Checkout)

```
┌───────────────┐  ┌───────────────┐
│               │  │               │
│   💳 Card     │  │   📱 QR      │
│               │  │   Wallet     │
└───────────────┘  └───────────────┘
┌───────────────┐  ┌───────────────┐
│               │  │               │
│   💰 E-Money  │  │   💵 Cash    │
│               │  │               │
└───────────────┘  └───────────────┘
```

Mỗi ô là button lớn, dễ tap trên tablet.

---

## Table Status Colors

Dùng trong màn Select Table:

| Status | Color | Hex | Ý nghĩa |
|--------|-------|-----|----------|
| Available | Green | #10B981 | Bàn trống, có thể chọn |
| Occupied | Amber | #F59E0B | Đang có khách |
| Reserved | Blue | #3B82F6 | Đã đặt trước |
| Cleaning | Purple | #9B8EC4 | Đang dọn |
| Blocked | Gray | #8B8B8B | Không sử dụng |

---

## Typography Guidelines

- **Font chính**: Noto Sans JP (dùng trong Figma, tương đương Hiragino Sans trên device)
- **Hierarchy**:
  - Heading: 24-32px, SemiBold/Bold
  - Body: 16-18px, Regular
  - Caption: 12-14px, Regular, muted color
- **Lưu ý**: Text phải readable từ khoảng cách 50-80cm (khách đứng trước kiosk)

---

## Design Principles

1. **Kiosk-first**: Touch targets lớn (min 44px, khuyến nghị 48px+), không có hover states
2. **Clarity over aesthetics**: Khách cần hiểu ngay phải làm gì, không cần đẹp phức tạp
3. **Error recovery**: Mọi lỗi đều có nút quay lại hoặc tự redirect
4. **Multi-language**: Layout không bị vỡ khi switch ngôn ngữ (Japanese ngắn, Vietnamese dài)
5. **Warm palette**: Không dùng cold gray, dùng cream-tinted neutrals (Betoya brand)
6. **Accessibility**: Contrast ratio đủ cho môi trường có ánh sáng mạnh (quán cafe)

---

## Figma Setup Checklist

- [ ] Tạo frame 1194x834 (iPad Air landscape)
- [ ] Setup Color Styles từ `design-tokens.json`
- [ ] Setup Text Styles (Noto Sans JP, các size)
- [ ] Tạo Component Library (Button, Card, Input, Badge, Skeleton)
- [ ] Import screenshots làm reference layer (lock)
- [ ] Thiết kế từng screen theo flow: Login → Advertise → Scan → Checkout → Payment → Success
- [ ] Thiết kế error/loading/empty states
- [ ] Kiểm tra với cả 3 ngôn ngữ (ja/en/vi)
- [ ] Export assets nếu cần (icons, illustrations)
