# Cấu hình JSON của template in — tra cứu

Tài liệu này là **phần tra cứu định dạng**. Kiến trúc (ba tầng, vòng đời version,
sync DOWN, quyền, API) nằm ở [`print-templates.md`](print-templates.md); đường đi
từ template tới tờ giấy nằm ở [`printing.md`](printing.md).

Mọi con số dưới đây **trích từ config**, không chép tay:
`backend/config/print_blocks.php` (catalog = nguồn chân lý) và
`backend/config/print_templates.php` (props mặc định + chữ).

> Muốn xem một bản thật: `php artisan print-templates:export-defaults` ghi cả 14
> kind ra JSON. Bản đã export nằm sẵn ở
> `workstation/internal/service/testdata/cloud_print_templates_default.json`.

---

## 1. Envelope

```json
{
  "schema": "tempo.print.v1",
  "paper": { "columns_58mm": 32, "columns_80mm": 48 },
  "kind": "receipt",
  "blocks": [ ... ]
}
```

| khoá | bắt buộc | ghi chú |
|---|---|---|
| `schema` | ✔ | phải đúng `tempo.print.v1`, sai ⇒ `SCHEMA_MISMATCH` |
| `paper` | ✔ | object; sai kiểu ⇒ `PAPER_MALFORMED` |
| `kind` | ✔ | một trong 14 kind |
| `blocks` | ✔ | **danh sách CÓ THỨ TỰ**; object ⇒ `BLOCKS_MALFORMED` |

> **`receiptline_dialect` ĐÃ BỊ GỠ (#2061).** Bảng trên từng ghi nó là **bắt
> buộc ✔** với giá trị `ofsc-1.0`. Đó là lời sai từ đầu: **không validator nào
> kiểm nó** (`TemplateValidator::checkEnvelope` chỉ kiểm `schema` · `blocks` ·
> `paper`; parser Go chỉ đọc `def.Schema`), và cả ba phía chỉ **GHI** rồi không
> phía nào **ĐỌC**. Bỏ trường đi thì publish vẫn qua và giấy vẫn ra y hệt.
> plan-053 định "dựa trên OFSC ReceiptLine cho phần layout text"
> (`plans/plan-053/DESIGN.md`) nhưng phần "dựa trên" chưa bao giờ hạ cánh, nên
> khoá này chỉ làm người đọc tưởng có một chuẩn mở phía sau. Envelope này là
> **định dạng nhà**, không phải OFSC ReceiptLine.
>
> Definition cũ còn mang khoá vẫn dùng được: PHP bỏ qua khoá thừa ở envelope,
> `encoding/json` bên Go bỏ qua field lạ. Không cần migrate gì.

`paper` chỉ là **gợi ý bề rộng**. Thứ tự quyết định bề rộng thật (`resolvePrintWidth`):

```
profile máy in  >  cấu hình job  >  paper của template  >  mặc định của kind
```

Cố ý: **một template không bao giờ được làm tờ phiếu rộng hơn khổ giấy.**

---

## 2. Sáu loại block

Mỗi block tối thiểu có `id` và `type`. `id` phải nằm trong catalog **và** thuộc
kind đó, nếu không: `BLOCK_UNKNOWN` / `BLOCK_NOT_IN_KIND`. Trùng id ⇒
`BLOCK_DUPLICATED`. Khai `type` khác catalog ⇒ `BLOCK_TYPE_MISMATCH`.

### `locked` — engine dựng, bạn chỉ bật/tắt

```json
{ "id": "grand_total", "type": "locked" }
```

**24/46 block** thuộc loại này. Không có prop nào để sửa ngoài `enabled` (và với
block bắt buộc thì tắt cũng không được — xem §5).

### `text` — chữ do bạn soạn

```json
{
  "id": "title", "type": "text",
  "enabled": true, "align": "right", "bold": true,
  "i18n": { "ja": "支払済", "en": "PAID", "vi": "DA THANH TOAN" },
  "i18n_narrow": { "vi": "DA TT" },
  "fallback": "ja"
}
```

`i18n_narrow` là biến thể **dưới 42 cột**. Có vì hoá đơn GTGT tự rút gọn nhan đề
trên giấy 58mm (`HOA DON GTGT`), và định nghĩa cần chỗ để nói điều đó.

### `params` — chọn trường có sẵn để in

```json
{ "id": "store_info", "type": "params", "enabled": true,
  "align": "left", "fields": ["store_name", "store_sub_name"] }
```

`fields` phải nằm trong allow-list §4. Ngoài danh sách ⇒ `PARAM_FIELD_NOT_ALLOWED`.

### `line_items` — bảng, cột chọn được, dữ liệu thì không

```json
{ "id": "items", "type": "line_items",
  "columns": ["name", "qty", "unit_price", "amount"] }
```

Đây là **nguyên thuỷ bảng DUY NHẤT**. Không có bảng tự do, không cột tự đặt.
`mutability` là `locked` nhưng `columns` sửa được — ranh giới ghi trong catalog:

> *Chọn cột là trình bày; giá trị cột là dữ liệu engine.*

`columns` chỉ nhận: `name` · `qty` · `unit_price` · `amount` · `tax_mark`.
Giá trị khác ⇒ `PROP_VALUE_NOT_ALLOWED`.

### `qr` — mã QR

```json
{ "id": "qr_block", "type": "qr", "enabled": true,
  "source": "order_url", "align": "center" }
```

`source` từ allow-list §4. Bật sẵn ở `runner` · `delta_qr` · `remaining`.

### `image` — in được

```json
{ "id": "logo", "type": "image", "enabled": false,
  "source": "brand_logo", "align": "center", "max_width_dots": 384 }
```

`mutability: free`. **13/14 kind có khối `logo`** và **cả 13 đều có emitter ở cả
hai phía** — `LogoBlock::emit` (PHP) và `emitLogo` (Go), một hàm dùng chung cho
mọi kind ở mỗi phía để hai bên không lệch byte.

Mục này từng ghi *"CHƯA IN ĐƯỢC, cả Go lẫn PHP đều không có emitter"* kèm lời
khuyên **"đừng bật"**. Đúng lúc viết, **sai từ #1957 / #1949** (đóng 2026-08-06)
— và đây là lệch **chiều ngược** với phần còn lại của tài liệu này: tài liệu nói
ít hơn code làm, nên chính lời khuyên mới là thứ sai. **Bật được.**

Không có ảnh **không phải lỗi**: brand chưa tải logo, máy chưa từng online, ảnh
mất khỏi storage — cả ba đều **không phát byte nào**, phiếu vẫn in, chỉ thiếu
khối. Nên bật `logo` trên một hệ chưa ai tải ảnh lên cho ra byte y hệt hôm nay
(TR-40) — bật rồi mới tải ảnh là thứ tự hợp lệ.

Không khai `max_width_dots` ⇒ **576 dots** (khổ 80mm in được), không phải "vừa
đúng ảnh": đổi ảnh không được phép đổi bố cục.

---

## 3. Ba mức quyền — cả 46 block

| mức | số | ý nghĩa |
|---|---:|---|
| `locked` | **25** | engine sở hữu nội dung, thứ tự VÀ props |
| `toggleable` | 8 | nội dung engine sở hữu, bạn được **ẩn cả khối** |
| `free` | 14 | sửa được props trong `editable_props` |

**`locked` (24)** — `issued_at` `split_banner` `items` `batch_total` `tax_legend`
`subtotal` `discounts` `service_charge` `tax_breakdown` `grand_total` `payments`
`change_due` `remaining` `invoice_number` `reprint_marker` `red_invoice_marker`
`void_marker` `float_count` `denomination_table` `tender_summary`
`variance` `chain_summary` `debt_summary` `paid_summary`

**`toggleable` (8)** — `registration_number` `sales_summary` `non_cash_change`
`discount_summary` `acct_correction` `check_count` `cash_movement` `void_summary`

Bảy trong số đó là **mục của phiếu 精算**: báo cáo đóng ca là chứng từ vận hành
nội bộ, nên brand được ẩn mục mình không chạy — nhưng **nội dung vẫn do engine
sở hữu**.

**`free` (14)** — props sửa được, đúng từng block:

| block | type | props |
|---|---|---|
| `logo` | image | `enabled` `source` `align` `max_width_dots` |
| `store_info` | params | `enabled` `align` `fields` |
| `title` | text | `enabled` `align` `i18n` `i18n_narrow` `fallback` `bold` |
| `header_text` | text | `enabled` `align` `i18n` `fallback` `bold` |
| `order_meta` | params | `enabled` `fields` |
| `customer_header` | params | `enabled` `fields` |
| `order_note` | params | `enabled` |
| `column_header` | text | `enabled` `i18n` `fallback` |
| `shift_meta` | params | `enabled` `fields` |
| `shift_signature` | text | `enabled` `align` `i18n` `fallback` |
| `debt_signature` | text | `enabled` `align` `i18n` `fallback` |
| `qr_block` | qr | `enabled` `source` `align` |
| `footer_text` | text | `enabled` `align` `i18n` `fallback` `bold` |
| `greeting` | text | `enabled` `align` `i18n` `fallback` `bold` |

Sửa prop không có trong hàng tương ứng ⇒ `PROP_NOT_EDITABLE`.

⚠️ Chỉ `title` có `i18n_narrow`. Các block text khác **không** — thêm vào là
`PROP_NOT_EDITABLE`.

---

## 4. Hai allow-list

### `source` — 6 giá trị

```
brand_logo · branch_logo · order_url · invoice_url · payment_url · order_code
```

**KHÔNG BAO GIỜ là URL.** Lý do viết trong config: một URL tuỳ ý trong định nghĩa
khiến **mọi workstation trong hệ thống fetch một địa chỉ do kẻ tấn công chọn
(SSRF) rồi bơm bytes đó vào máy in**. Ngoài danh sách ⇒ `SOURCE_NOT_ALLOWED`.

Hệ quả: **không in được ảnh quảng cáo, không mã coupon dạng ảnh riêng.** Khuyến
mãi hôm nay chỉ đi được bằng **chữ** qua `header_text` / `footer_text` / `greeting`.

### `param_fields` — 23 giá trị

```
store_name  store_sub_name  store_address  store_phone
order_no  order_type  table  guest  staff_name  cover_count
customer_name  customer_phone  pickup_time  customer_tax_code  customer_address
till_name  cashier_name  business_date  opened_at  closed_at
device_name  shift_sequence  chain_sequence
```

Cùng lý do: template chọn **trường nào đã biết**, không bịa binding.

---

## 5. Hai luật tuyệt đối

### TR-15 — định nghĩa TRÌNH BÀY, không bao giờ TÍNH

Mọi placeholder `{{ … }}` bị **từ chối thẳng** (`EXPRESSION_NOT_ALLOWED`).

Renderer bind dữ liệu theo block id + `source`, nên một placeholder chỉ có thể là
mưu toan diễn đạt logic. Cấm sạch khiến luật **không thể trườn**: không có "tập
con an toàn" của ngôn ngữ biểu thức để tranh cãi về sau.

### TR-16 — `locked` bất khả xâm phạm, kể cả THỨ TỰ

Không chỉ nội dung và props: **thứ tự tương đối** giữa các block locked cũng bị
kiểm (`LOCKED_BLOCK_REORDERED`). Sửa nội dung ⇒ `LOCKED_BLOCK_MODIFIED`; tắt block
bắt buộc ⇒ `LOCKED_BLOCK_DISABLED`.

---

## 6. Lỗi thường gặp

### `i18n` rỗng — cái đắt nhất

Đây là lỗ hổng thứ tám của #1181, và nó **âm thầm vứt mọi chỉnh sửa của brand và
shop** trong khi Cloud nhìn vào thấy hoàn toàn khoẻ mạnh:

```json
"i18n": {}     ← PHP json_encode ra  []
```

PHP chỉ có một kiểu mảng, nên `json_encode([])` chọn `[]`. Go từ chối một mảng ở
chỗ nó chờ `map[string]string` ⇒ **hỏng CẢ định nghĩa** ⇒ workstation rơi về mặc
định nhúng (TR-14).

`DefinitionNormalizer` giờ **bỏ hẳn khoá** thay vì gửi map rỗng — vắng mặt và rỗng
có cùng nghĩa ở cả hai phía. Nếu tự dựng JSON: **đừng gửi `i18n` rỗng, bỏ khoá đi.**

### `i18n` thiếu locale

Phải phủ đủ **ja · en · vi**, hoặc khai `fallback` trỏ tới locale có mặt (TR-19).

### Tưởng bật `logo` mà chưa tải ảnh là in ra logo

Bật khối là **đủ để in** (emitter có ở cả hai phía từ #1957 — mục này trước đây
nói ngược lại, xem §2), nhưng vẫn cần một ảnh ở `source`. Chưa có ảnh ⇒ khối
phát **rỗng**: phiếu ra bình thường, chỉ thiếu logo, không lỗi và không log.

### Tưởng `columns` đổi được dữ liệu

`columns` chọn **cột nào hiện**; con số trong cột do engine tính. Không có cách
nào để định nghĩa đổi một con số — đó chính là TR-15.

---

## 7. Override của shop

Brand khai `shop_editable` — allow-list dạng đường dẫn `blockId.prop`. Shop chỉ
sửa được đúng những gì có trong đó:

| mã lỗi | nghĩa |
|---|---|
| `SHOP_EDITABLE_UNKNOWN_BLOCK` | allow-list trỏ block không có thật |
| `SHOP_EDITABLE_LOCKED_BLOCK` | brand cho phép sửa một block `locked` |
| `SHOP_EDITABLE_UNKNOWN_PROP` | prop không tồn tại |
| `SHOP_FIELD_NOT_EDITABLE` | shop sửa thứ ngoài allow-list |

Định nghĩa của brand là **trọn vẹn**; của shop là **lớp phủ**, gộp **theo từng
trường** chứ không thay cả khối.

---

## 8. 24 mã lỗi validate

Tất cả trả về lúc **publish**, không bao giờ lúc in — nguyên tắc của validator:
*một quán không bao giờ được mất khả năng bán hàng vì một định nghĩa hỏng.*

| nhóm | mã |
|---|---|
| envelope | `SCHEMA_MISMATCH` `BLOCKS_MALFORMED` `PAPER_MALFORMED` `BLOCK_MALFORMED` |
| block id | `BLOCK_UNKNOWN` `BLOCK_NOT_IN_KIND` `BLOCK_DUPLICATED` `BLOCK_TYPE_MISMATCH` |
| locked | `LOCKED_BLOCK_MODIFIED` `LOCKED_BLOCK_REORDERED` `LOCKED_BLOCK_DISABLED` |
| bắt buộc | `REQUIRED_BLOCK_MISSING` `REQUIRED_BLOCK_DISABLED` |
| props | `PROP_NOT_EDITABLE` `PROP_VALUE_NOT_ALLOWED` |
| allow-list | `SOURCE_NOT_ALLOWED` `PARAM_FIELD_NOT_ALLOWED` |
| biểu thức | `EXPRESSION_NOT_ALLOWED` |
| ảnh | `IMAGE_WIDTH_INVALID` |
| shop | `SHOP_EDITABLE_INVALID` `SHOP_EDITABLE_UNKNOWN_BLOCK` `SHOP_EDITABLE_LOCKED_BLOCK` `SHOP_EDITABLE_UNKNOWN_PROP` `SHOP_FIELD_NOT_EDITABLE` |

Logo quá khổ thì **kẹp lại chứ không từ chối** (TR-22) — `IMAGE_WIDTH_INVALID`
chỉ dành cho giá trị vô nghĩa.

---

## 9. Trước khi publish

Kiểm #6 của validator **render thử 2 khổ × 3 locale × 2 text mode**. Nó chạy trên
đúng thứ sắp publish, nên một định nghĩa qua được publish thì render được.

Bản thử **không** phát hiện block đã bật mà thiếu emitter — nó render thành công
và chỉ không vẽ gì. Việc đó do một kiểm **riêng** đảm nhận (`checkRenderable`,
#1949), và nó chỉ chặn thứ tác giả **vừa bật**, không chặn nợ thừa hưởng từ bản
mặc định hệ thống — nếu không thì không brand nào publish nổi.

---

## Tham chiếu

- Catalog: `backend/config/print_blocks.php`
- Props mặc định: `backend/config/print_templates.php`
- Validator: `backend/app/Services/Print/TemplateValidator.php`
- Kiến trúc: [`print-templates.md`](print-templates.md)
- Đường tới máy in: [`printing.md`](printing.md)
- Bản export thật: `workstation/internal/service/testdata/cloud_print_templates_default.json`
