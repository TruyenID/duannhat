# app/handy — nhận order tại bàn trên máy PDA (Expo / React Native)

Nhân viên phục vụ cầm máy đi tới bàn: chọn bàn → mở đơn → thêm món → gửi bếp.
**Thanh toán không làm ở đây** — việc đó ở quầy (pos-web / workstation).

Nằm trong monorepo TempoFast tại `app/handy/`. Luật khi sửa code: `AGENTS.md`
cùng thư mục. Thiết kế + hợp đồng API đầy đủ: `docs/DESIGN.md`.

## Máy đích — quyết định mọi thứ về layout

| | |
|---|---|
| Loại | POS Handy / PDA cầm tay, Android |
| Màn hình | 5.5 inch, ~360dp rộng |
| Hướng | **Portrait, bắt buộc** |
| Đặc biệt | máy in nhiệt tích hợp ở đỉnh máy |

360dp là ràng buộc thật, không phải gợi ý: mọi màn phải dùng được bằng **một
tay, ngón cái**, trong lúc đi.

## Chạy

```sh
cd app/handy
pnpm install       # app này có lockfile RIÊNG — `pnpm install` ở gốc KHÔNG cài nó
pnpm start         # expo start
pnpm android       # expo run:android
pnpm lint
```

## So với pos-web

| | pos-web | handy |
|---|---|---|
| Xem bàn & đơn | ✅ | ✅ |
| Tạo đơn + thêm món | ✅ | ✅ |
| Thanh toán | ✅ | ❌ (ở quầy) |
| Split bill · void đơn | ✅ | ❌ |
| In phiếu tạm | — | ✅ (máy in nhiệt tích hợp) |

## Nguồn chuẩn cho API

**Không tự đặt type hay endpoint.** Mọi thứ lấy thẳng từ pos-web —
`web/pos/src/services/` và `web/pos/src/app/pos/types.ts` — vì hai app nói
chuyện với cùng một backend và lệch hợp đồng là lỗi chỉ lộ ra ở quán.

## Expo

Đọc tài liệu **đúng phiên bản** ở <https://docs.expo.dev/versions/v56.0.0/>
trước khi viết code: bản này có breaking change so với thứ mô hình đã học.
