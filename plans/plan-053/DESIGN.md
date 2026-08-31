# Plan 053 — Design

## 1. Mô hình phân cấp: brand → shop (mẫu tender types #1156)

```
LỚP 0  SYSTEM DEFAULT   definition gốc per kind, ship trong code/seed
                        (bảo hiểm: brand chưa cấu hình gì vẫn in được)
LỚP 1  BRAND            HQ soạn/publish per kind — áp cho mọi shop của brand
LỚP 2  SHOP OVERRIDE    shop chỉnh TRONG KHUÔN CHO PHÉP (allow-list của brand)
```

**Resolve (giống `TenderTypeResolver`)**: shop override active → brand
published → system default. Một kind chỉ có MỘT definition hiệu lực tại một
thời điểm cho một branch.

**Khuôn cho phép** (brand quyết, per kind):
```
"shop_editable": ["logo", "header_text", "footer_text", "show_qr_block",
                  "show_staff_name", "greeting"]
```
Ngoài danh sách này shop không sửa được. Brand có thể khoá hoàn toàn
(`shop_editable: []`).

**Khoá cứng cấp hệ thống** (không ai sửa, kể cả HQ — chỉ toggle khi luật cho):
`registration_number`, `tax_breakdown`, `grand_total`, `invoice_number`,
`reprint_marker` (「Bản in #N」), `red_invoice_marker`, `issued_at`.
Định nghĩa trong code (`config/print_blocks.php`: block → `locked|toggleable|free`),
validator từ chối publish nếu definition đụng vào block locked.

## 2. Schema

```
print_templates
├── id                uuid
├── kind              enum 13 loại (receipt|kitchen|runner|delta_qr|remaining|
│                     vat_invoice|red_invoice|void_notice|debt_slip|
│                     shift_open|shift_report|chain_report|table_paid|diagnostic)
├── scope             system | brand | shop
├── brand_id          fk nullable (scope=brand|shop)
├── branch_id         fk nullable (scope=shop)
├── version           int, tăng dần per (kind, scope, brand, branch)
├── status            draft | published | retired
├── definition        json (IR — §3)
├── shop_editable     json array (chỉ scope=brand: khuôn cho shop)
├── effective_from    datetime nullable (null = ngay khi publish)
├── parent_version_id fk nullable (version trước — audit chain)
├── notes             string (mô tả thay đổi, hiện trong lịch sử)
├── created_by_id / published_by_id / published_at
└── created_at / updated_at / deleted_at
UNIQUE (kind, scope, brand_id, branch_id, version)
INDEX  (kind, scope, brand_id, branch_id, status)
```

- Chỉ **MỘT** row `status=draft` per (kind, scope, brand, branch) — sửa tiếp
  thì sửa draft đó; publish → thành published, draft mới tách ra khi cần.
- Published cũ KHÔNG bị xoá (retired), giữ vĩnh viễn để reprint theo version.

## 3. Definition format (IR)

~~Dựa trên **OFSC ReceiptLine** cho phần layout text~~ + wrapper block-structure
của Tempo (để khoá compliance và toggle):

> ⚠️ **Ý ĐỊNH NÀY CHƯA BAO GIỜ HẠ CÁNH (ghi lại ở #2061).** Không có mã nào đọc
> hay phát cú pháp ReceiptLine; layout text do renderer của Tempo (PHP `Layout`
> ↔ Go `printRenderCtx`) tự dựng. Thứ duy nhất từng tồn tại là khoá hằng
> `receiptline_dialect: "ofsc-1.0"` trong envelope — chỉ có người ghi, không ai
> đọc, không validator nào kiểm — và nó **đã bị gỡ** khỏi cả PHP, Go, TS và
> `print_templates_default.json`. IR này là **định dạng nhà**. Khối `jsonc` dưới
> đây giữ nguyên hình dạng gốc của bản thiết kế TRỪ khoá đó.

```jsonc
{
  "schema": "tempo.print.v1",
  "paper": { "columns_58mm": 32, "columns_80mm": 48 },
  "blocks": [
    { "id": "logo", "type": "image", "source": "brand_logo", "align": "center",
      "enabled": true },
    { "id": "header_text", "type": "text", "i18n": { "ja": "…", "en": "…", "vi": "…" } },
    { "id": "store_info", "type": "params", "fields": ["store_name","address","phone"] },
    { "id": "registration_number", "type": "locked", "enabled": true },   // #1152
    { "id": "items", "type": "line_items", "columns": ["name","qty","amount"] },
    { "id": "tax_breakdown", "type": "locked" },                          // per-rate
    { "id": "grand_total", "type": "locked" },
    { "id": "reprint_marker", "type": "locked" },                          // Bản in #N
    { "id": "qr_block", "type": "qr", "source": "order_url", "enabled": false },
    { "id": "footer_text", "type": "text", "i18n": {…} }
  ]
}
```

- `type: locked` = renderer tự dựng nội dung từ data engine; definition chỉ
  được đặt vị trí + `enabled` (nếu block đó `toggleable`).
- KHÔNG có biểu thức tính toán trong definition (nguyên tắc #1).
- i18n nằm TRONG definition (ja/en/vi) — không phụ thuộc file locale của app,
  vì HQ tự viết lời cảm ơn theo ý mình.
- `source` tham chiếu data có sẵn (brand_logo, order_url…) — allow-list, không
  cho URL tuỳ ý (P-bảo mật, xem EDGE-CASES).

## 4. Vòng đời & publish

```
draft ──(validate)──▶ published ──(version mới publish)──▶ retired
   ▲                      │
   └── sửa tiếp           └── không bao giờ UPDATE
```

**Validate lúc publish** (không bao giờ lúc in — TR-14):
1. Schema hợp lệ, mọi block id nằm trong catalog `config/print_blocks.php`
2. Không đụng block `locked` (ngoài `enabled` nếu `toggleable`)
3. Đủ block bắt buộc per kind (vd receipt phải có items + grand_total +
   registration_number + reprint_marker)
4. Shop override: mọi field sửa phải nằm trong `shop_editable` của brand
5. i18n: đủ 3 locale hoặc khai `fallback: true` (thiếu ja thì fallback en)
6. **Render thử cả 2 khổ giấy + 3 locale + chế độ raster** — lỗi render =
   không publish được
7. Ảnh logo: kích thước/định dạng hợp lệ, đã convert được sang raster

**effective_from**: null = hiệu lực ngay; có giá trị = workstation cache
trước, tự chuyển tại thời điểm đó (theo **business time của branch** #1091,
không phải giờ HQ).

## 5. Sync DOWN + cache

- Endpoint: `GET /api/v1/workstation/print-templates?since=` — trả các
  definition **đã resolve cho branch này** (system→brand→shop đã gộp), kèm
  `version`, `effective_from`, `checksum`.
- Workstation lưu vào SQLite (`print_templates` local), pull trong tick 60s
  cùng nhịp menu/branch hiện có.
- **Chọn version lúc in**: version mới nhất có `effective_from <= now(branch)`;
  không có gì → system default nhúng trong binary.
- Mất Cloud: dùng cache; cache rỗng (máy mới, chưa từng online) → system
  default → vẫn in được (TR-05).

## 6. Preview ở admin

- Render **SVG** từ cùng definition (ReceiptLine hỗ trợ SVG) — HQ thấy tờ
  phiếu trước khi publish, có chọn khổ 58/80mm, locale, và **dữ liệu mẫu**
  (order giả lập nhiều dòng, nhiều rate thuế, có/không discount).
- Preview dùng CHÍNH renderer sẽ dùng khi in (Phase 1: preview server-side
  bằng renderer PHP-SVG; Phase 2 dùng lại chính renderer PHP cho ESC/POS) —
  không viết renderer thứ ba chỉ để preview.

## 7. Renderer contract (chống drift Go ↔ PHP)

```
render(definition, data, profile, locale, paper) -> bytes|xml|svg
```
- `data` = struct đã tính sẵn (order, items, per-rate tax buckets, totals,
  payment info, reprint_no) — renderer KHÔNG tính gì.
- `profile` = capability profile máy in (plan-052 §3b) — quyết native vs
  raster, cut, cột.
- **Golden fixtures ở CẢ 2 repo** (mẫu `offline_signing_golden.json` #1092):
  mỗi (kind × locale × paper × text_mode) một fixture với definition + data
  cố định → output kỳ vọng. CI hai repo cùng đọc.
- Font raster: cùng font family + version + thuật toán dựng bitmap ở hai
  renderer (khai trong fixture header), nếu không phiếu lệch từng pixel.

## 8. Ngoài scope

Editor WYSIWYG kéo thả (Phase 1 dùng form + preview; WYSIWYG là plan sau) ·
template cho label printer (TSPL/ZPL) · digital receipt gửi khách (SVG có
sẵn, nhưng luồng phát hành là plan riêng) · A/B template.
