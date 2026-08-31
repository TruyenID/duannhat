# Plan: Customer Backend API

## Mục tiêu

Xây dựng backend đầy đủ cho `customer-web`:
1. Auth riêng cho customer (register/login → lưu vào bảng `customers`)
2. API public phục vụ toàn bộ flow QR → menu → order → thanh toán

---

## Kiến trúc quyết định

### Tại sao không dùng `users` table?
Bảng `users` là cho staff/admin. Trộn customer vào đó gây lỗi phân quyền.

### Tại sao không dùng thẳng bảng `customers` hiện tại?
Bảng `customers` hiện tại thiếu `password`, `email` nullable, và bắt buộc `brand_id`/`branch_id`/`organization_id`. Không thể dùng cho Sanctum auth mà không sửa.

### Giải pháp: Tạo bảng `customer_accounts`
Tách auth ra khỏi CRM record. Một `customer_account` có thể order ở nhiều chi nhánh khác nhau.

```
customer_accounts          customers (CRM hiện có)
──────────────────         ──────────────────────
id (uuid, PK)              id (uuid, PK)
first_name                 first_name / last_name
last_name                  phone / email
email (unique)             brand_id / branch_id / org_id
phone                      ...
password
remember_token
created_at / updated_at
```

`customer_orders.customer_account_id` (FK mới, nullable) → link order với account.

---

## Phase 1 — Backend Auth

### 1.1 Migration

```
php artisan make:migration create_customer_accounts_table
```

Fields: `id` (uuid), `first_name`, `last_name` (nullable), `email` (unique), `phone` (nullable), `password`, `remember_token`, timestamps.

### 1.2 Model `CustomerAccount`

Implement `Authenticatable` + `HasApiTokens` (Sanctum).

```php
// app/Models/CustomerAccount.php
class CustomerAccount extends Authenticatable
{
    use HasApiTokens, HasUuids, SoftDeletes;
}
```

### 1.3 Migration thêm FK vào `customer_orders`

```
php artisan make:migration add_customer_account_id_to_customer_orders_table
```

Thêm `customer_account_id` (uuid, nullable, FK → `customer_accounts.id`).

### 1.4 Routes `/api/v1/customer/auth/*`

```
POST /api/v1/customer/auth/register   → CustomerAuthController@register
POST /api/v1/customer/auth/login      → CustomerAuthController@login
POST /api/v1/customer/auth/logout     → CustomerAuthController@logout  [auth]
GET  /api/v1/customer/auth/me         → CustomerAuthController@me      [auth]
```

### 1.5 Middleware `customer.auth`

Tương tự `sso.auth` nhưng resolve `CustomerAccount` từ Sanctum token.

---

## Phase 2 — Backend Customer API

### 2.1 QR Table Lookup

```
GET /api/v1/customer/tables/{qrToken}
```

- Tìm `Table` theo `qr_token`
- Trả về: `{ table: { id, name, zone, status }, branch: { id, name, slug } }`
- 404 nếu token không tồn tại

### 2.2 Menu

```
GET /api/v1/customer/tables/{qrToken}/menu
```

- Lấy menu active của branch tương ứng với QR token
- Trả về categories + products + SKUs + giá

### 2.3 Order

```
POST /api/v1/customer/tables/{qrToken}/orders        → tạo order mới / thêm vào order đang mở
GET  /api/v1/customer/orders/{id}                    → xem order hiện tại  [auth optional]
POST /api/v1/customer/tables/{qrToken}/call-staff    → gọi nhân viên
```

### 2.4 Thanh toán (xem)

```
GET /api/v1/customer/orders/{id}/summary             → bill tổng kết
```

---

## Phase 3 — Frontend customer-web

| File | Thay đổi |
|---|---|
| `context/auth-context.tsx` | Đổi endpoint sang `/api/v1/customer/auth/*` |
| `app/register/page.tsx` | Đã có form, chỉ cần chỉnh endpoint |
| `app/dine-in/shop/table/[qrToken]/page.tsx` | Gọi `GET /customer/tables/{qrToken}` thay mock |
| `components/dine-in-page.tsx` | Gọi menu API thay `data/menu.ts` |
| `components/checkout-page.tsx` | Gọi order API thay local state |
| `data/tables.ts`, `data/menu.ts`, `data/orders.ts` | Xoá mock, thay bằng API hooks |

---

## Thứ tự thực hiện

```
1. [Backend] Migration customer_accounts + CustomerAccount model
2. [Backend] CustomerAuthController + routes + middleware customer.auth
3. [Backend] CustomerTableController (QR lookup + menu)
4. [Backend] CustomerOrderController (tạo order, xem order)
5. [Frontend] Cập nhật auth-context sang endpoint mới
6. [Frontend] Thay mock data bằng API calls từng phần
```

---

## Ghi chú

- **Guest order**: Không bắt buộc login để đặt order (anonymous), chỉ cần login để xem lịch sử.
- **QR token**: Đã có sẵn trong `tables.qr_token` (field thêm vào migration `000039`).
- **CORS**: Backend `cors.php` dùng `CORS_ALLOWED_ORIGINS` — cần add `http://localhost:3001` vào `.env`.
