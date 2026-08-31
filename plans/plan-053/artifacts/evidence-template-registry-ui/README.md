# Evidence — Print template registry admin UI (plan-053 §7, #2093)

Ảnh chụp trình duyệt thật (Playwright headless Chromium, backend docker `:5400`, admin-web `:5430`), **2026-08-13**. Ảnh nằm NGAY TRONG thư mục này — bản trước của README này mô tả 37 ảnh "ở scratchpad của phiên làm việc" và toàn bộ đã bốc hơi cùng phiên đó, nên lần này bằng chứng được commit.

| # | File | Chứng minh |
|---|---|---|
| U1 | `U1-01-hq-template-list-system-default` | Danh sách template theo brand; cả 3 kind đang "システム標準を使用中" |
| U1 | `U1-02-hq-editor-before-system-default` | Editor trước khi sửa — badge hệ-thống-chuẩn, chưa có version nào |
| U1 | `U1-03-preview-before-no-footer` | Preview do **renderer thật phía server** vẽ, KHÔNG có dòng footer |
| U1 | `U1-04-hq-footer-edited-and-delegated` | `footer_text` điền cả ja/en/vi + tick "店舗に許可" (uỷ quyền cho shop) |
| U1 | `U1-05-preview-after-footer-visible` | Cùng preview đó, nay có dòng footer ở cuối phiếu |
| U1 | `U1-06-publish-dialog` | Hộp thoại phát hành: ghi chú + hiệu lực từ |
| U1 | `U1-07-publish-blocked-unprintable-char` | **Publish bị chặn** — xem mục dưới |
| U1 | `U1-08-version-history-v1-v2-published` | Tab 履歴・差分: v1 và v2 kèm ghi chú, người phát hành, mốc thời gian |
| U2 | `U2-01-shop-footer-editable-neighbours-locked` | Màn shop: `footer_text` sửa được (3 ô locale trắng, toggle bật) trong khi `greeting` ngay dưới bị xám và loạt block trên nó mang nhãn `locked` / 施錠 |
| U2 | `U2-02-shop-locked-field-tooltip` | Con trỏ trên field bị khoá — field disabled một mình không phải lời giải thích |

## Cách tái lập

```sh
docker compose up -d                       # backend :5400
# .env docker:      DEV_LOGIN=true
# .env umbrella:    SSO_DEV_BYPASS=true     (compose truyền vào container, .env của app KHÔNG thắng)
# web/admin/.env.local: TEMPO_BACKEND_URL=http://localhost:5400
cd web/admin && pnpm dev                   # :5430
curl -X POST localhost:5400/api/dev/test-login -d '{"email":"admin@famgia.com"}'
# → token `dev:<console_user_id>`, đặt vào cookie `token` rồi lái Playwright
```

Ba file env trên đều **local-only, không commit** (`docs/guide/local-config.md`).

Bẫy đã trả giá khi dựng: `next.config.ts` lấy đích proxy từ `TEMPO_BACKEND_URL`, mặc định `https://dxs-product.test` — đặt mỗi `NEXT_PUBLIC_API_URL` thì trang **vẫn** gọi Herd và chết ở `UNABLE_TO_VERIFY_LEAF_SIGNATURE`, hiện ra là "サーバーエラー" chứ không nói gì về cert.

## Hai điều phát hiện trong lúc chụp

**1. Render-trial gate nằm ở PUBLISH, không ở PREVIEW.** Chuỗi footer đầu tiên dùng em-dash `—` (U+2014). Preview vẽ nó bình thường (`U1-05`), nhưng publish trả **422 `RENDER_TRIAL_UNPRINTABLE_CHARACTER`**:

> `—` (U+2014) cannot be printed — the thermal printer codepage (Shift_JIS) has no glyph, so it would print as a blank or a black block.

Cổng chạy đúng và thông điệp nêu đúng ký tự + đúng path (`blocks.footer_text.i18n.ja`). Nhưng người sửa chỉ biết sau khi bấm phát hành; preview không hề cảnh báo. Đây là **quan sát, chưa mở issue** — người quyết định có đáng đưa cảnh báo lên preview hay không.

**2. Publish v2 KHÔNG tự thu hồi v1.** `U1-08` cho thấy cả hai mang badge 発行済 và mỗi dòng có nút 運用停止 riêng. Mô tả trong #2093 viết history sẽ hiện "v2 published + v1 retired" — thực tế thu hồi là **hành động rời**. Ảnh và tên file ở đây theo thực tế đo được, không theo mô tả.

Diff v1→v2 hiện "この2版に差分はありません" vì v2 chỉ đổi `shop_editable` (uỷ quyền), không đổi định nghĩa block — đúng như thiết kế: uỷ quyền không phải một phần của định nghĩa in.

## Còn thiếu — U3 · U4

Cần **tờ giấy thật** ra khỏi máy in nhiệt: publish → máy trạm nhận trong ≤1 tick → phiếu mang footer mới (U3), và rollback → phiếu trở lại như cũ (U4). Không giả lập được: thứ đo được mà không cần máy in là **byte gửi tới cổng máy in** (công thức ở `docs/guide/printing.md` §13), và nó không phủ nhiệt/độ đậm, dao cắt, codepage ROM của máy cụ thể, tràn khổ 58mm trên giấy thật.

U5 (preview 2 khổ × 3 locale) đã xong từ phiên trước qua endpoint preview.
