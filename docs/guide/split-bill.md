# Chia bill (split bill)

Ruling chủ dự án 2026-08-15, và nó là luật gốc của cả tài liệu này:

> **Chia bill chỉ là chia HÌNH THỨC THANH TOÁN. Cấm tuyệt đối đổi order.**

Nghĩa đen: một đơn được chia vẫn là **một** `customer_order`, với **cùng** các
dòng món, **cùng** `total_amount`, **cùng** thuế. Thứ duy nhất nhân lên là số
dòng trong `order_payments`.

---

## 1. Từ vựng — ba chế độ, một bộ tên

| giá trị | nghĩa | đi kèm |
|---|---|---|
| `even` | chia đều theo đầu người | `split_count` / `split_people_count` |
| `by_items` | chia theo món ai ăn | `item_allocations: [{item_id, units}]` |
| `by_amount` | mỗi người tự khai một số tiền | `amount` |

Nguồn chân lý: `backend/app/Services/Order/Enums/OrderSplitMode.php`. **Mọi**
tầng và **mọi** app dùng đúng ba giá trị này — backend · pos-web · kiosk ·
customer-web · workstation.

Đúng ba, không phải bốn: Square · Toast · Lightspeed · Clover đều dừng ở
*split evenly* / *split by item* / *split by amount*. Ba câu hỏi đó vét hết cách
một bàn khách chia tiền. Thêm cái thứ tư là một **quyết định sản phẩm**, không
phải một lượt refactor — và `SplitModeVocabularyIsSingleTest` bắt nó dừng lại ở
đó để có người quyết.

### Trước #2860: bảy cách viết cho ba khái niệm

Ghi lại vì cơ chế sinh ra nó sẽ lặp lại nếu không ai biết:

| tầng | từ vựng cũ |
|---|---|
| đơn `customer_orders.split_mode` | `by_people` · `by_items` · `custom` |
| thanh toán `order_payments.metadata.split_mode` | `equal` · `by_items` · `by_amount` |
| kiosk (nội bộ) | `full` · `split_even` · `custom` · `by_items` |
| kiosk (validate riêng, tập thứ ba!) | `equal` · `by_people` · `by_items` · `custom` |
| nhận nhưng không ai phát | `even` |

Giao nhau giữa hai tầng chính: **đúng một giá trị** (`by_items`).

Cơ chế: mỗi lần có người viết một đầu mới, họ **gõ tay** một tập giá trị vào
`validate()` hoặc vào một `match`. Không gì đỏ. Ba tập cùng tồn tại nhiều tháng.

Nên rào không canh "tên hiện tại có đúng không" (một lượt sửa là xong) mà canh
**"có ai đang định nghĩa từ vựng ở nơi không phải enum không"**.

### Tên cũ còn sống ở đúng một chỗ

`OrderSplitMode::WIRE_ALIASES`, và **chỉ theo chiều VÀO**:

```
equal · by_people · split_even → even
custom                         → by_amount
```

Đây **không** phải nhánh tương thích ngược mà [#2188](../../CLAUDE.md) cấm.
#2188 nói về *dữ liệu cũ* và tính năng *chưa release*. Cái ở đây là **lệch phiên
bản fleet trên thiết bị đã phát hành**: workstation chạy trên hai máy Windows
**không tự cập nhật**, kiosk là app native trên tablet. Siết validator mà không
nhận tên cũ là chặn đứng đồng bộ bán hàng của chính những máy đó.

Điều kiện gỡ là một **phép đo**, không phải một cái hẹn:

```sh
php artisan devices:fleet-versions --type=workstation --min-version=<ver> --require-min
php artisan devices:fleet-versions --type=kiosk       --min-version=<ver> --require-min
```

**Cả hai** phải xanh — #2865: kiosk cũng phát `equal`, và nó là app native trên
tablet, cũng không tự cập nhật. Gỡ alias khi mới có workstation xanh sẽ làm mọi
kiosk chưa nâng cấp nhận 422 ở đúng bước thanh toán.

Xanh nghĩa là không còn máy nào phát tên cũ. Lúc đó xoá `WIRE_ALIASES` **và**
chính hàm `fromWire()` — đừng để nó nằm lại thành di tích.

---

## 2. Nó KHÔNG đụng vào gì (phần cốt lõi của ruling)

Đo được, không phải tuyên bố:

| | |
|---|---|
| `customer_order_items` | **không chạm** |
| `total_amount` · `tax_amount` · `subtotal` | **không chạm** |
| `status` của đơn | **không chạm** |
| `SplitByItemsCalculator::compute()` | **chỉ đọc** |
| `WritesCustomerOrders::setSplitModeFields()` | ghi **đúng hai cột** |

Hai cột đó là `split_mode` + `split_people_count` trên `customer_orders`, và
chúng là **tờ nháp chung giữa các khách**: khách thứ hai mở máy phải thấy cùng
con số khách thứ nhất vừa chọn. Không phải một thay đổi kế toán.

### Chúng là TRÌNH BÀY, không phải QUYẾT ĐỊNH

`POST /api/v1/customer/orders/{id}/split-mode` **công khai có chủ đích** — không
`auth:customer`; chú thích tại route ghi rõ thêm vào sẽ làm hỏng luồng guest
counter-pay. Nên hai cột đó là thứ **khách đặt được**.

Điều đó từng gây một lỗ tiền thật ([#2856](https://github.com/godx-jp/godx-tempo/issues/2856)):
một vế `$order->split_mode === null` đứng trong rào *"walk-in phải trả đủ"*, nên
khách gọi `/split-mode` một lần là đơn thoát rào. Rào ấy tồn tại vì khoản thiếu
trên đơn không có `customer_id` thì không có khoá nào tra lại —
`GET /customers/{id}/outstanding` là cơ chế duy nhất làm nợ nổi lại.

Luật rút ra, và `SplitModeIsPresentationOnlyTest` cưỡng chế nó:

> Giá trị do khách đặt **không được gác một luật tiền**. Tín hiệu đúng cho "khoản
> này là một phần của chia bill" là `order_payments.metadata.split_mode` — theo
> **từng giao dịch**, do POS gửi.

---

## 3. Ba chỗ lưu, cùng một từ vựng

| chỗ | ai ghi | nghĩa |
|---|---|---|
| `customer_orders.split_mode` | `/customer/orders/{id}/split-mode` | chế độ **cả bàn** thoả thuận |
| `order_payments.metadata.split_mode` | POS · kiosk · workstation | chế độ của **từng khoản trả** |
| `order_payments.metadata.split_type` | đường Stripe / PayPay | y hệt, tên trường khác vì lịch sử |

Chỗ thứ ba là chỗ **dễ bỏ sót nhất**: nó không tên `split_mode` nên grep theo tên
cột không thấy. Cả hai migration của #2860 đều xử lý nó tường minh.

---

## 4. Đường đi theo từng client

### pos-web (thu ngân ở quầy)

`SplitBillDialog` → ba tab (`split-bill-even-tab` · `split-bill-by-items-tab` ·
`split-bill-by-amount-tab`) → mỗi lần xác nhận là **một** `POST
/api/v1/shops/{shop}/orders/{id}/payments` mang `metadata.split_mode`.

`SplitMode` ở `web/pos/src/app/pos/types.ts` phải khớp enum backend. Lệch thì
`validate()` trả **422** — đó là chỗ nó vỡ, không phải ở TypeScript.

### customer-web (khách quét QR)

`payment-view.tsx` (dine-in) và `split-bill-sheet.tsx` (order-success) → `POST
/customer/orders/{id}/split-mode` đặt chế độ **cả bàn**, rồi từng khách trả phần
mình. `/split-status` là chỗ khách thứ hai đọc ra con số khách thứ nhất đã chọn.

ADR-1: luồng **counter-pay** (chưa mint Stripe intent) **từ chối** `by_amount` —
không có màn khai "tôi trả X, bạn trả Y" ở đó; khách mang QR ra quầy và thu ngân
xử lý. Pay-online giữ `by_amount` vì payment-view có ô nhập.

### kiosk (máy tự phục vụ)

`SplitMode` union ở `app/kiosk/src/types/kiosk.ts`. `full` **không phải** một chế
độ chia — nó là "trả hết, không chia", nên không gửi `split_mode` nào.

`by_amount` ở kiosk trả `undefined` metadata có chủ ý: kiosk **chưa có** màn khai
số tiền từng người. Đó là thiếu **màn hình**, không phải thiếu từ vựng.

### workstation (máy trạm ngoài quán)

Đọc `split_mode` từ blob `payments.metadata` trong SQLite để quyết **kiểu phiếu
in**: `by_items` chỉ hiện món của người trả; `even`/`by_amount` hiện đủ món, chân
phiếu in phần chia.

Máy trạm **không** có cột `orders.split_mode` — chế độ cả bàn chỉ sống ở Cloud.

Nhánh suy đoán cuối (`splitMode` rỗng nhưng `splitCount > 1` ⇒ chia đều) là **cố
ý**: đơn cũ không ghi `splitMode`, bỏ nhánh này thì chúng in như phiếu thường và
mất dòng "phần của người thứ N".

---

## 5. Đơn bán offline — `split_mode` nằm TRONG chữ ký

Bẫy nặng nhất của cả tính năng.

`split_mode` là một trường của signed bytes
(`OfflineOrderSigningMessage::selectionDigest()`). Chữ ký phủ lên thứ **thiết bị
gửi**, nên digest phải dựng lại từ **đúng chuỗi đó**.

Chuẩn hoá trước khi verify — `equal` → `even` — làm message dựng lại khác message
đã ký ⇒ chữ ký fail ⇒ **mọi đơn bán offline của máy chưa cập nhật bị từ chối**.

Cách xử: `OrderSelectionPayload` mang **hai** trường, luật rõ ràng —

- `$splitModeWire` — *"thiết bị đã ký gì"*, nguyên văn, dùng cho digest;
- `$splitMode` — *"ta hiểu đó là gì"*, canonical, dùng cho mọi thứ khác.

Không phải trôi từ vựng: trong một hệ chứng cứ có chữ ký, hai câu đó vốn khác
nhau. Đơn không đến từ thiết bị thì hai trường luôn trùng.

Hiện production Go **chưa bao giờ ký** `split_mode` (đo ở
`offline_selection_builder.go`: luôn `nil`), nên đây là bẫy chưa nổ. Bài
`#2860 digest ký theo CHUỖI THIẾT BỊ GỬI` giữ cho nó đừng nổ.

### Suy giảm mềm ở chiều RA — đánh đổi đã biết

Chuẩn hoá ở biên VÀO giải quyết chiều thiết bị-gửi-lên. Chiều ngược lại không có
lời giải sạch: một APK kiosk **cũ** đọc `split_mode` từ `KioskOrderResource` và
so với tên cũ, nay nhận canonical ⇒ không khớp ⇒ rơi về màn chọn chế độ, mất
luồng bỏ-qua của #377.

Đó là **suy giảm mềm, có chủ ý**: khách phải chọn lại một lần, không mất tiền và
không sai số. Lựa chọn còn lại là phát tên cũ ra cho client cũ — tức giữ hai từ
vựng trên đường ra vĩnh viễn, đúng thứ tài liệu này tồn tại để chấm dứt.

Ghi kèm: hai chỗ đọc (`KioskOrderResource`, `CustomerOrderSplitStatusController`)
so `=== Even->value` và trả `null`/bỏ qua khi gặp giá trị lạ — **im lặng**, khác
với chiều ghi vốn fail-closed (`fromWire()` ném). Chính sự im lặng đó là cách 6
test kiosk biểu hiện ở #2865. Làm chiều đọc fail-closed là việc đáng làm, nhưng
là một thay đổi riêng: siết nó cùng lượt này sẽ biến "khách chọn lại một lần"
thành "màn hình lỗi".

---

## 6. Migration — hai bên, cùng một ánh xạ

| | |
|---|---|
| Cloud | `backend/database/migrations/2026_08_15_100000_manual_migration_canonicalize_split_mode_vocabulary.php` |
| máy trạm | `workstation/internal/store/migrations/087_split_mode_canonical_vocabulary.sql` |

Cả hai **idempotent** (đường deploy chạy `migrate --force` không người trông) và
cả hai **không có `down()` khôi phục**: ánh xạ nhiều-về-một không có nghịch đảo,
và viết một `down()` đoán bừa sẽ tạo ra dữ liệu trông như thật mà sai.

Vì sao máy trạm cũng cần: cùng lượt này gỡ nhánh `case "even", "equal"` khỏi
đường in. Sau khi gỡ, một thanh toán cũ mang `equal` rơi xuống nhánh suy đoán
`splitCount > 1`, mà `total_bills` không phải blob nào cũng có — khi không có,
phiếu in ra **như hoá đơn thường thay vì phiếu chia**. Dữ liệu đó nằm trên hai
máy Windows không tự cập nhật, nên nó không tự hết.

---

## 7. Rào đang canh những gì

| rào | canh |
|---|---|
| `SplitModeVocabularyIsSingleTest` | tên cũ ngoài normalizer · luật `in:` gõ tay · enum đúng ba case |
| `SplitModeIsPresentationOnlyTest` | mọi chỗ đọc cột cấp đơn phải khai lý do · rào walk-in không đọc cột khách đặt được |
| `SplitModeVocabularyTest` | normalizer · luật validate · migration ba chỗ · idempotent · digest ký theo chuỗi gốc |
| `SetSplitModeTest` (#2860) | thiết bị cũ gửi tên cũ **qua endpoint** vẫn được nhận và lưu canonical |
| `TestMigration087_*` (Go) | migration local, giữ nguyên khoá khác của blob, idempotent |

Mọi rào ở đây đi **cả hai chiều** — khai thiếu đỏ, khai thừa cũng đỏ. Rào chỉ
biết kêu sẽ kêu oan, và rào kêu oan thì không bị tranh luận: nó bị **tắt**.

---

## 8. Thêm một chế độ mới thì phải chạm những đâu

1. `OrderSplitMode` — thêm case (bài "đúng ba chế độ" sẽ đỏ; **đó là cổng để có
   người quyết**, không phải phiền toái cần né).
2. `web/pos/src/app/pos/types.ts` · `app/kiosk/src/types/kiosk.ts` ·
   `payment-view.tsx` — union TS.
3. `workstation` — nhánh `match` ở `print_service.go`, `print_receipt.go`,
   `print_renderer_bill.go`.
4. `PrintLabels::splitModeText()` + `print_kitchen_bill_i18n.go` — nhãn ja/en/vi.
5. Một test **đi qua endpoint** (#2622: `$request->validate()` strip mọi khoá
   không có rule, nên test service-level vẫn xanh trong khi đường thật trả 422).

### Placeholder `SplitModeEqual` cố ý KHÔNG đổi tên

Nó là **namespace khác**: tên biến trong **mẫu in**, thứ quán gõ vào template và
thứ `print_labels_golden.json` ghim chung với workstation. Đổi nó là làm câm một
placeholder trong mọi mẫu đã lưu — mà mẫu in là thứ quán sửa được, nên "đã lưu"
gồm cả những mẫu không ai trong repo này nhìn thấy.

Đổi giá trị trên wire không bắt buộc phải đổi tên biến trong mẫu. Gộp hai việc
lại chỉ để tên trông đều nhau là đánh đổi sai.

---

## 9. Phiếu con CHƯA đủ trường 適格簡易請求書 — trạng thái đo được (#2064)

Đơn NGUYÊN in ra tờ `receipt` thoả **đủ** năm nhóm trường của 消法57条の4②.
Phiếu con của một lần chia bill thì **không**, và nguyên nhân là đúng một cờ
boolean `showTaxBreakdown = !isSplitSubBill` — nó tắt nhiều thứ cùng lúc.

| trường (消法57条の4②) | trên phiếu con | vì sao |
|---|---|---|
| ① 氏名/名称 + **登録番号** | **CÓ** | đã un-gate ở PR #2285 — số đăng ký là **danh tính người bán**, không phải thuộc tính của khối thuế |
| ② 年月日 | **CÓ** | không đi qua cờ này |
| ③ 内容 — phần *nội dung* (dòng món) | **CÓ** | vòng in dòng món **không** bị `suppressOrderRows` chặn; cờ đó chỉ chặn tạm tính / giảm giá / phí phục vụ |
| ③ 内容 — phần *軽減対象である旨* (dấu ※ + chú thích) | **THIẾU** | bị cờ chặn |
| ④ 税率ごとの合計額 · ⑤ 税率ごとの消費税額等 hoặc 適用税率 | **THIẾU** | bị cờ chặn |

⇒ Khách B2B cầm phiếu con về **không khấu trừ được thuế đầu vào**.

### Năm chỗ cờ đó gác — hai đầu phải sửa cùng lượt

| file | dòng | gác gì |
|---|---|---|
| `backend/app/Services/Print/Renderer/BillKindPlans.php` | 1595 · 1620 | tính cờ |
| ↑ | 438 | ③ chú thích ※ |
| ↑ | 1179 | ③ dấu ※ trên từng dòng món |
| ↑ | 1246 | ④⑤ khối theo mức |
| `workstation/internal/service/print_renderer_bill.go` | 115 · 267 · 367 · 416 | cờ · ③ ※ · ④⑤ · ③ chú thích |
| `workstation/internal/service/print_service.go` (đường legacy) | 1225 · 1245 · 1327 | cờ · ③ ※ · ④⑤ + chú thích |

### Hai nửa, và chỉ MỘT nửa bị điều kiện dừng chặn

Chủ dự án ruling 2026-08-07: phiếu con phải mang `登録番号` + khối thuế theo mức
+ dấu ※ + chú thích 軽減税率, **kèm điều kiện dừng**:

> Σ các phiếu con phải khớp phiếu tổng **theo TỪNG mức**, không lệch một đồng.
> Không đạt được thì DỪNG và báo thiết kế, đừng ship.

Điều kiện dừng đó nói về **TIỀN**. Nó chạm ④⑤ (là con số) và **không** chạm ③
(dấu ※ là một *boolean mỗi dòng*, suy từ `taxSummary` — ảnh chụp bất biến của
đơn — và không sinh ra đồng nào). Đây đúng lý lẽ đã tách ① ra ship riêng ở
#2285: một trường không phải thuộc tính của khối thuế thì không chịu bài toán
làm tròn của khối thuế.

④⑤ thì **đang chặn thật**, và chặn ở tầng thiết kế chứ không phải tầng mã: ruling
2026-08-09 giả định Cloud có một khoảnh khắc *"tạo tập phiếu chia"* để phân bổ
largest remainder trên **cả tập**. Khoảnh khắc đó **không tồn tại** — và §2 của
chính tài liệu này là lý do: chia bill không tạo bản ghi nào cho "tập", nó chỉ
làm `order_payments` nhân lên, từng lượt một, khi từng khách trả. Với `by_items`
và `by_amount`, tập chỉ hoàn chỉnh lúc khách **cuối** trả — mà phiếu của những
người trước đã in xong. Chi tiết + ba lối đi: #2677.

Phần đã có: `SplitBillTaxAllocator` (phép toán, mang bất biến, ném khi lệch).
Phần chưa có: chỗ gọi nó, bảng snapshot, feed sync DOWN, Go đọc snapshot.

### Cổng byte-parity KHÔNG phủ phiếu con

Đo trên `workstation/internal/service/testdata/print_input_golden.json`: **126
case, `SplitCount` bằng 0 ở tất cả**. Nên `SlipByteParityTest` — thứ canh
"Cloud in giống máy trạm" — chưa từng render một phiếu con nào.

Hệ quả cụ thể: sửa một trong năm chỗ ở bảng trên chỉ ở **một** đầu sẽ **không
làm đỏ gì cả**, và hai đầu lệch nhau im lặng trên đúng tờ giấy pháp định. Bất cứ
ai đụng vào ③ hay ④⑤ phải thêm case `SplitCount > 1` vào golden **trước**, không
phải sau.
