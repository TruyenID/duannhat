# Plan: QR Table Ordering ✅ HOÀN THÀNH

## Mục tiêu
Customer quét mã QR trên bàn → vào thẳng trang menu đúng chi nhánh + bàn đó → chọn món → đặt hàng.
Không cần đăng nhập, không cần chọn bàn thủ công. Toàn bộ dùng static data, không gọi backend.

---

## Luồng

```
[Admin in QR dán lên bàn]
        ↓
[Customer quét QR bằng điện thoại]
        ↓
[Mở URL: /t/{qr_token}]
        ↓
[Resolve token từ static data/tables.ts]
        ↓
[Hiển thị menu + banner thông tin bàn]
[orderType = dine_in, bàn đã lock]
        ↓
[Customer chọn món → Checkout → Đặt hàng]
```

---

## Đã thực hiện

| # | File | Nội dung |
|---|---|---|
| ✅ 1 | `data/tables.ts` | Thêm `qr_token` vào 19 bàn + helper `findTableByToken()` |
| ✅ 2 | `context/CartContext.tsx` | Thêm `lockedTable` + `setLockedTable`, persist `sessionStorage` |
| ✅ 3 | `app/t/[token]/page.tsx` | Trang QR landing — resolve token, banner bàn, render MenuPage |
| ✅ 4 | `components/CheckoutPage.tsx` | Bàn lock hiện badge QR cố định, ẩn TableSelector |

---

## Cách test

Thay `{token}` bằng `qr_token` trong `data/tables.ts`:

```
# Bàn hợp lệ
http://localhost:3001/t/qrA3cDeFgHiJkLmNoPqRsTuVwXyZ5678   → Bàn A3, Khu A

# Token không tồn tại
http://localhost:3001/t/tokenkhongtontai                   → Trang "QR không hợp lệ"
```

---

## Ngoài scope (làm sau)

- Auth thật (AuthContext → gọi `/api/v1/auth/login`)
- Gửi đơn thật (CheckoutPage → POST `/shops/{slug}/orders`)
- Trang lịch sử đơn hàng `/orders`
- Real-time trạng thái đơn hàng
