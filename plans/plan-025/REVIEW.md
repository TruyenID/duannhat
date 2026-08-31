# Plan-025 Code Review

**Branch**: _(chưa tạo)_
**Reviewer**: _(điền khi review)_
**Date**: _(điền khi review)_
**Verdict**: ⏳ PENDING — plan chưa implement, chưa có code để review

---

> ⚠️ File này là **template chờ điền sau khi implement**. Không có kết quả review nào được điền sẵn
> vì code chưa tồn tại. Điền các mục dưới sau khi hoàn tất Phase 1-6.

## Summary
_(Tóm tắt sau khi implement: feature complete? critical blockers?)_

## Item Verification

| Item | Status | Notes |
|------|--------|-------|
| Schema `ReviewSentiment` + `ProductReview` | ⏳ | |
| Product aggregate (`review_up_count`, `review_total_count`) | ⏳ | |
| `ProductReviewService.submit` (tx + lock + idempotent) | ⏳ | |
| API `GET /reviewable` + `POST /reviews` | ⏳ | |
| CustomerMenuService nối `rating`/`review_count` | ⏳ | |
| FE OrderReviewSheet (dine-in + takeaway) | ⏳ | |
| FE menu cards gỡ mock + gate data thật | ⏳ | |

## Issues
### Critical
_(none yet)_
### Warnings
_(none yet)_
### Informational
_(none yet)_

## Edge-case Verification
- [ ] Order chưa paid → submit bị chặn (422)
- [ ] Double-vote cùng order_item → skip, aggregate không tăng lần 2
- [ ] Item voided → không cho review
- [ ] Product deleted → menu không crash, không hiển thị rating
- [ ] review_count = 0 → badge ẩn

## Test Coverage Assessment
_(điền sau khi viết test)_

## Files Changed (for PR reviewers)
_(điền khi tạo PR)_
