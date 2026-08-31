# Plan 025 — E2E Customer Flow Report

**Date**: _(điền khi test)_
**Tester**: _(điền)_
**Build**: _(branch / commit)_
**Status**: ⏳ PENDING — chưa implement, chưa test

---

> ⚠️ Template chờ điền. Mô tả luồng end-to-end khách hàng cho feature review.

## Luồng E2E: Đặt món → Thanh toán → Đánh giá → Rating lên menu

```
[1] Quét QR / vào takeaway → menu
[2] Đặt món → đơn tạo
[3] Thanh toán → đơn `paid`
[4] Màn "Đánh giá món" → khách bấm 👍/👎 cho từng món → submit
[5] BE: tạo ProductReview (unique order_item) + cập nhật Product aggregate
[6] Khách khác mở menu → thấy badge 👍% (N) cập nhật theo data thật
```

## Kịch bản kiểm thử E2E

| # | Bước | Kỳ vọng | Kết quả |
|---|---|---|---|
| E1 | Đơn dine-in paid → đánh giá 👍 món A | Review tạo, A.review_up_count +1, total +1 | ⏳ |
| E2 | Mở menu → món A | Badge `👍 100% (1)` | ⏳ |
| E3 | Đơn khác đánh giá 👎 món A | A: up=1, total=2 → `👍 50% (2)` | ⏳ |
| E4 | Đánh giá lại cùng order_item | Bị skip (unique), aggregate không đổi | ⏳ |
| E5 | Đơn chưa paid → cố submit review | 422, không tạo | ⏳ |
| E6 | Món chưa ai đánh giá | Badge ẩn trên menu | ⏳ |
| E7 | Takeaway: paid → đánh giá → lên menu | Tương tự dine-in | ⏳ |

## Số liệu / quan sát
_(điền: thời gian phản hồi, aggregate có drift không, ...)_

## Bug surfaced
_(liệt kê khi test)_

## Kết luận
_(điền)_
