# UI evidence (Playwright)

- `01-materials-route.png` — Playwright (chromium) điều hướng tới `http://localhost:5430/hq/beto-coffee/materials`.
- Kết quả: admin-web **redirect sang SSO login** (`/login?redirect=/hq/beto-coffee/materials`).

## Vì sao không có screenshot trang materials đã đăng nhập
Admin-web đăng nhập **chỉ qua SSO** (Omnify console, `SSO_SERVICE_SLUG=tempo-dev`) — không có form email/password.
Tự động hoá login cần SSO console chạy + credential trên console đó (không có sẵn ở môi trường này).
Do đó UISmoke dừng ở trang login. **Tầng API mà UI gọi đã được verify đầy đủ bằng curl** (xem `../results-TC001-025.md`).

## Cách Playwright chính danh trong repo
Pest 4 browser tests (`backend/tests/Browser/**`) drive Playwright thật + auth server-side (`actingAs`).
Chúng **skip** ở đây vì chưa cài Playwright browser binary cho PHP-side. Cài rồi chạy:
```
cd backend && npx playwright install chromium
php vendor/bin/pest tests/Browser/Hq/MaterialLot/HqMaterialLotsTest.php
```
