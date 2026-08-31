# Plan 025 — Hướng dẫn Test UI (Manual)

> Hướng dẫn test thủ công phần UI mà plan-025 ship trên `customer-web`:
> 1. **Màn đánh giá món** sau thanh toán (dine-in + takeaway)
> 2. **Hiển thị rating** trên menu cards (data thật, gỡ mock)

## Chuẩn bị
- Backend chạy (`docker compose up -d`, `:5400`) + đã `omnify:gen` + migrate (bảng `product_reviews`).
- customer-web chạy (`pnpm dev:customer`, `:5450`).
- 1 branch có menu + ít nhất 1 đơn dine-in đã `paid` (để có order item review được).
- QR token bàn test (vd Hà Nội: `uLqKpqHnQvC4B801Ec7YcoclJ3pgv74r`).

## Phần 1 — Đánh giá món (dine-in)

| # | Bước | Kỳ vọng |
|---|---|---|
| 1.1 | Quét QR → đặt món → thanh toán đến trạng thái `paid` | Hiện màn paid + nút "Đánh giá món" |
| 1.2 | Bấm "Đánh giá món" | Sheet list đủ các món trong đơn, mỗi món có toggle 👍/👎 |
| 1.3 | Chọn 👍 vài món, 👎 vài món → "Gửi đánh giá" | Toast cảm ơn, sheet đóng |
| 1.4 | Mở lại màn đánh giá (cùng đơn) | Các món đã đánh giá hiện cờ "đã đánh giá", không cho vote lại |
| 1.5 | Bấm "Bỏ qua" thay vì gửi | Đóng sheet, không tạo review |

## Phần 2 — Đánh giá món (takeaway)
| # | Bước | Kỳ vọng |
|---|---|---|
| 2.1 | Đặt takeaway → thanh toán → order-success | Hiện CTA "Đánh giá món" |
| 2.2 | Đánh giá + gửi | Toast cảm ơn |

## Phần 3 — Hiển thị rating trên menu
| # | Bước | Kỳ vọng |
|---|---|---|
| 3.1 | Món **chưa có review** | Badge 👍% **ẩn** (không hiện "0%") |
| 3.2 | Sau khi có review (vd 9 👍 / 10) | Menu card hiện `👍 90% (10)` — số thật, không random |
| 3.3 | Reload nhiều lần | Số review **không nhảy** (đã gỡ `Math.random()`) |
| 3.4 | Cả 3 nơi: menu list, featured carousel, menu card (takeaway) | Hiển thị nhất quán |

## Phần 4 — Layout / regression
| # | Bước | Kỳ vọng |
|---|---|---|
| 4.1 | iPhone 12 Pro (390px), menu dine-in | Không scroll ngang; badge rating + HH badge không vỡ layout |
| 4.2 | `pnpm typecheck` + `pnpm lint` | 0 lỗi mới (fix luôn `Math.random` purity ở menu-list-item) |

> Điền kết quả thực tế vào [UI-TEST-REPORT.md](./UI-TEST-REPORT.md) sau khi test.
