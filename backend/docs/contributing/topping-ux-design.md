# Topping UX — design recommendations from the floor

> Voice: 30 năm chạy nhà hàng, mở từ quán phở 30 chỗ đến chuỗi 12 cửa hàng. Mọi
> recommendation dưới đây đều xuất phát từ pain point thật trên ca đêm Sài Gòn
> hoặc trên grill line London — không phải lý thuyết.

Bối cảnh: schemas hiện có (sau PR #145, #158, #159, plus P3 fields trong commit
này) đã đủ data layer để build UX hoàn chỉnh. Document này nói KHÔNG về schema —
chỉ nói về cách data đó nên hiển thị / nhập / in ra cho 4 đối tượng dùng:

1. **HQ admin** (config menu, không trực tiếp phục vụ)
2. **Customer** (đặt món qua customer-web hoặc kiosk)
3. **Kitchen** (bếp, đọc ticket)
4. **Cashier / wait staff** (POS, sửa order)

Mỗi đối tượng có một mental model khác nhau cho cùng một topping group.
Lẫn lộn giữa các mental model là bug UX phổ biến nhất tôi thấy ở mọi POS
chain ngoài kia.

---

## 1. HQ admin — topping group editor

### 1.1 Layout cần BẮT ĐẦU bằng câu hỏi sống còn

Khi admin click "New topping group", màn hình mở đầu KHÔNG phải là form 12 ô
trắng. Nó phải hỏi 1 câu duy nhất, MASSIVE size:

> **Nhóm này dùng để THÊM hay BỎ?**
>
> [+ THÊM nguyên liệu]   [✕ BỎ nguyên liệu]

`+ THÊM` (modifier_type=add) — sauces, extras, "thêm trứng", "thêm phomai"
`✕ BỎ` (modifier_type=remove) — "không hành", "không cay", "ít đường"

Lý do: chọn xong, NỬA sau của form thay đổi hoàn toàn. ADD form có ô extra_price,
REMOVE form thì giấu luôn ô đó (vì price luôn 0). Hiển thị 1 form duy nhất cho cả
2 mode rồi disable field tùy chọn → admin click nhầm liên tục, kế toán hỏi
"sao không hành mà còn tính 5k?".

### 1.2 Selection mode phải dùng từ ngữ con người

`selection_type=single` → label "Khách chọn ĐÚNG 1" (radio icon)
`selection_type=multiple` → label "Khách chọn NHIỀU" (checkbox icon)

KHÔNG hiển thị giá trị raw "single" / "multiple". Sau 30 năm tôi quan sát
một nguyên tắc: nếu admin phải đọc tên enum và tự dịch nghĩa, họ sẽ pick
sai. Bug âm ỉ nhiều tháng mới phát hiện.

### 1.3 Quy tắc giá phải có preview LIVE

Ô `price_strategy=free_up_to_n` cần một preview ngay bên dưới:

```
Khách chọn 5 toppings:
  Cheese  +20.000
  Bacon   +25.000  ← ĐƯỢC FREE (cheapest in top 3)
  Avocado +15.000  ← ĐƯỢC FREE (cheapest in top 3)
  Trứng    +8.000  ← ĐƯỢC FREE (cheapest in top 3)
  Nấm     +12.000

Tổng phụ thu: 32.000đ  (= 20.000 + 12.000)
```

Admin nhìn preview này 5 giây hiểu hơn đọc 3 paragraph docs. Trong đời tôi
đã thấy ít nhất 4 chuỗi cài sai `free_quantity` xuống 1 thay vì 3 → cả tháng
chạy promo "3 toppings free" thành "1 free" → khách review tệ → mất tin.

### 1.4 Schedule editor — design tối quan trọng

Schedule UI phổ biến nhưng làm SAI:

❌ **Bad**: 7 hàng × 2 ô (open/close per ngày). Đẹp database, nhưng admin
phải sửa 7 lần khi muốn đổi cùng 1 thứ.

✅ **Good**: Day picker (chip group "T2 T3 T4 T5 T6 T7 CN" — toggle), rồi
1 cặp time picker single duy nhất. Mặc định all 7 ngày tick — đa số nhóm
bán cả tuần.

```
┌─ Khung giờ phục vụ ─────────────────┐
│ [T2] [T3] [T4] [T5] [T6] [T7] [CN]  │  ← chip toggle
│                                      │
│ Từ  [11:00 ▾]  Đến  [14:00 ▾]       │
│                                      │
│ ⓘ "Trống cả 2 = phục vụ cả ngày"   │
└──────────────────────────────────────┘
```

Quan trọng: nếu admin pick "từ 22:00 đến 02:00" — KHÔNG warning, KHÔNG block.
Đây là use case THẬT của bar / late-night. UX nhiều hệ thống tôi từng dùng
reject pattern này → bar manager chửi rồi quay sang spreadsheet.

### 1.5 Cảnh báo "khách không thấy" khi schedule đang active

Khi nhóm có schedule mà thời điểm hiện tại NGOÀI window, admin list page hiển
thị badge:

> 🌙 Đang ngoài giờ phục vụ — khách KHÔNG thấy nhóm này lúc 14:23 hôm nay

Đây là dòng UI cứu mạng. Đã cứu tôi vô số lần — admin cứ tưởng nhóm bị tắt
→ mò vào sửa is_active toggle → break luôn cả schedule.

### 1.6 Attaching a group to a product — per-product min/max override

Đây là **trung tâm UX** mà nhiều POS làm tệ. Khi admin gắn nhóm "Sốt" (default
0–3) vào BURGER, COMBO MEAL, FRIES — mỗi sản phẩm có nhu cầu khác:

| Product | Behavior expected | Override |
|---------|-------------------|----------|
| Burger | Default (0–3 sốt tuỳ chọn) | NULL / NULL |
| Combo Meal | Phải chọn đúng 1 sốt | min=1 / max=1 |
| Fries | Chỉ kèm 1 sốt nếu muốn | min=NULL / max=1 |

UX form trên product edit page khi admin attach group:

```
┌─ Sốt (default 0–3, chọn nhiều) ──────────────┐
│  Áp dụng cho sản phẩm này:                    │
│                                                │
│  [✓] Dùng cài đặt mặc định (0–3 sốt)          │
│                                                │
│  ── HOẶC ghi đè cho riêng món này: ──        │
│  ▢ Tối thiểu  [  __  ]  (mặc định: 0)         │
│  ▢ Tối đa     [  __  ]  (mặc định: 3)         │
│                                                │
│  ⓘ Để trống = dùng mặc định nhóm              │
└────────────────────────────────────────────────┘
```

3 quy tắc UX quan trọng:

1. **Default to "use defaults"** — checkbox "Dùng cài đặt mặc định" mặc định
   tick. 80% case admin không cần override. Tick = ẩn 2 ô input bên dưới.

2. **Hiển thị giá trị mặc định ngay trong placeholder** — admin biết nhóm mặc
   định 0–3 mà không cần điều hướng sang trang group edit. Tránh tab-switching
   = mất 5 giây / lần × 50 lần / ngày = 4 phút lãng phí / shift.

3. **Validation real-time** — nếu group có `selection_type=single`, tự động
   ép max_override=1 (disable input + hint). Cross-field rule như form group
   edit nhưng áp dụng vào attachment level.

Phase 2 customer-web đọc effective values:
```
effective_min = attachment.min_select_override ?? group.min_select
effective_max = attachment.max_select_override ?? group.max_select
```

Nhờ vậy 1 nhóm "Sốt" duy nhất phục vụ được 3 product với behavior khác nhau.
Trước đây phải clone group 3 lần — schema drift, giá topping inconsistent
giữa các bản clone, admin sửa giá quên 1 bản → khách phàn nàn "trên menu báo
giá khác".

---

## 2. Customer-web — topping picker

### 2.1 Mental model: **chọn món → tuỳ chọn → giỏ**

Customer KHÔNG nghĩ về "topping group" hay "modifier". Họ nghĩ:

> "Burger này có gì thay đổi được không?"

UI dùng group label (e.g., "Sốt", "Rau", "Topping bổ sung") ở header section,
items bên dưới. Một section per group.

### 2.2 ADD vs REMOVE phải khác biệt rõ

- **ADD group** (sauces, extras): mỗi item có dòng giá `+15.000` rõ ràng bên
  phải. Tick = thêm. Quantity stepper cho `max_qty_per_item > 1`.

- **REMOVE group** (exclusions): KHÔNG có giá. Item label theo dạng "🚫 Hành",
  "🚫 Cilantro". Tick = bỏ ra. Default unchecked (= "không bỏ"), tick →
  "bỏ ra". Tô đỏ để user thấy đã thay đổi món gốc.

### 2.3 is_default = true ⇒ pre-checked

Default-included items (cà chua, hành lá trong burger) hiển thị **pre-checked
+ disabled-grey** trong ADD group. Customer untick → trạng thái thường + có
border đỏ "đã bỏ ra".

Đây là pattern Square / Toast làm ổn nhất. 90% case khách không sửa gì → cứ
checked. Khi khách untick, gửi event "remove default ___" lên kitchen ticket.

### 2.4 Selection_type = single ⇒ radio button BẮT BUỘC chọn

Khi `selection_type=single` AND `min_select=1`:
- Radio button group, KHÔNG check mặc định
- "Tiếp tục" button DISABLED đến khi user pick một option
- Hint: "Chọn 1 để tiếp tục"

Nếu `min_select=0` (optional): hiển thị nút "Bỏ qua" cạnh radios.

### 2.5 free_up_to_n ⇒ progress bar visible

Khi nhóm có `price_strategy=free_up_to_n`, hiển thị **progress bar** lên trên:

```
✨ Chọn miễn phí 3 topping nữa
[████████░░░░] 1 / 3 free
```

Khi đủ N: chuyển sang `🎉 Thêm 1 topping = 25.000đ` (informative, không nag).

Đây là patternhút khách bậc nhất. Customer thấy "còn được free thêm" → pick
thêm → giỏ to lên.

### 2.6 Availability schedule — KHÔNG hiển thị nếu out-of-window

Đơn giản: nếu shop hiện tại nằm ngoài `available_from..available_to` HOẶC ngày
không thuộc `available_days` → KHÔNG render group này.

KHÔNG show "Nhóm này chỉ có lúc 11-14h, vui lòng quay lại sau" — đó là UX
bad. Customer đói, đã chọn món rồi mới thấy "không bán giờ này" → họ rời app.

Hiển thị graceful: chỉ cho khách thấy những group đang phục vụ. Hết.

---

## 3. Kitchen ticket — UX cứu sống order accuracy

Đây là phần tôi coi NẶNG nhất. 80% complaints về order sai đến từ kitchen
miss-read modifier. Một dòng nhỏ chữ "no onion" giữa danh sách item → bếp
miss → customer trả về món → mất tiền + mất khách.

### 3.1 REMOVE modifiers phải IN MÀU ĐỎ + IN ĐẬM + IN HOA

```
═══════════════════════════════
  ORDER #2024-0142  •  TABLE 5
═══════════════════════════════
  1× BÚN BÒ HUẾ TÔ LỚN
       ❌ KHÔNG HÀNH
       ❌ KHÔNG RAU MÙI
       + Trứng cút (×2)
═══════════════════════════════
```

In đỏ ❌ ngay đầu dòng, ALL CAPS, indent. Bếp đọc lướt vẫn thấy.

ESC/POS printer hỗ trợ red color trên paper trắng (dual-color thermal).
Nếu printer chỉ trắng-đen → dùng inverse (black background, white text)
cho remove items.

### 3.2 ADD modifiers — bullet xanh nhỏ, indent

KHÔNG cùng visual weight với REMOVE. Chef cần ưu tiên xử lý exclusion
trước (vì nếu không bỏ kịp thì phải làm lại tô).

### 3.3 Order item có nhiều modifier → group lại theo món

Sai pattern: liệt kê tất cả modifier cuối ticket cho mỗi món rời rạc. Bếp
phải scroll/cuộn lên xuống → miss.

Đúng pattern: tất cả modifier của 1 món nằm IMMEDIATELY UNDER tên món, indent.
Block visually separated khỏi món khác.

### 3.4 free_up_to_n — KHÔNG show price trên kitchen ticket

Kitchen không cần biết item nào free, item nào trả tiền. Họ chỉ cần làm.
Show price là noise → tăng nhận thức cognitive → miss-read.

(POS receipt cho khách MỚI cần show price.)

---

## 4. Cashier / wait staff — POS modifier picker

### 4.1 Dùng KEYBOARD shortcut ngay từ đầu

Cashier ca cao điểm bấm modifier 200+ lần/giờ. UI mouse-only kiểu Square trên
iPad → chậm 30%. Phải có:

- Numeric shortcut: "1 = 第1 topping group", "2 = 第2", v.v.
- Keyboard nav giữa items: arrow keys + space để toggle
- Enter = confirm + back to order

### 4.2 Default state matters

Khi staff mở modifier picker:
- ADD group, no defaults: tất cả unchecked
- ADD group, có is_default=true: mỗi default item **pre-checked**
- REMOVE group: tất cả unchecked

Staff press Enter ngay → state mặc định gửi đi. Tốc độ là tất cả.

### 4.3 Cảnh báo khi vi phạm min_select

Customer order qua customer-web đã đảm bảo min_select. Nhưng staff modify
order qua POS có thể bỏ qua → vi phạm.

POS hiển thị cảnh báo INLINE (không modal) khi count < min_select:

```
⚠ Phải chọn ít nhất 1 sốt — đang chọn 0
```

KHÔNG block save (staff sometimes need to save invalid state — VD: customer
đang quyết định, staff muốn lưu hold), chỉ warn.

### 4.4 Showing combo savings

Khi `free_up_to_n` apply, POS receipt và staff screen show savings:

```
Toppings:
  Cheese        20.000
  Bacon        FREE  (-25.000)
  Avocado       15.000
  ────────────────────
  Tiết kiệm: 25.000đ
```

Customer thấy savings trên receipt → tâm lý sẵn sàng quay lại + tăng
satisfaction. Đó là conversion knob mà POS Vietnam phổ biến nhất bỏ qua.

---

## 5. Anti-patterns đã trải qua

5 anti-pattern tôi đã thấy nhiều POS rơi vào — TRÁNH HẾT:

1. **Modal chồng modal** khi pick topping. Customer click món → modal
   options → modal cảnh báo → modal confirm. Mỗi step rớt 20% conversion.
   Solution: inline expand, không bao giờ dùng modal cho topping picker.

2. **Topping vô danh**. Item label "Topping A1" xuất hiện vì admin lười
   nhập tên. Customer-web hiển thị nguyên. Solution: validation server-side
   refuse rỗng/A1/test trên `is_active=true` items.

3. **Show ALL groups always**. Group đã sold-out hoặc out-of-schedule vẫn
   render greyed. Customer click → "không khả dụng" → frustrate. Solution:
   không render hẳn (đã đề xuất 2.6).

4. **Price text không gọn**. "+ ¥150.00" hiển thị thay vì "+150¥" gọn.
   Trong list dài, mỗi 5 ký tự thừa giảm scan speed 1%. Solution: format
   compact (no `.00`, no leading currency for major locales).

5. **Confirm dialog cho topping changes**. Customer untick một mặc định →
   modal "Bạn có chắc muốn bỏ cilantro?". Mỗi modal chiếm 3 giây. Customer
   bỏ giỏ. Solution: untick = silent, hiển thị badge "đã sửa" nhỏ ở giỏ
   để confirm visually.

---

## 6. Phase rollout đề xuất

| Phase | Scope | Lý do |
|-------|-------|-------|
| **Phase 2.1** (gấp) | Customer-web ADD modifier picker, basic flat pricing, REMOVE picker, kitchen ticket cơ bản | 80% workflow đã có. Triển khai trước. |
| **Phase 2.2** | is_default pre-check + remove-default UX, schedule filtering | Quality-of-life cho nhân sự ca đêm. |
| **Phase 2.3** | free_up_to_n preview + progress bar customer-web | Marketing-facing — cần beta thử nghiệm trên 1 brand trước. |
| **Phase 3.0** | POS keyboard shortcut, kitchen color printer, savings display | Khi staff training data đủ. |

KHÔNG ship hết một lần. Mỗi feature topping cần A/B test 2 tuần với 1 brand
(cùng quy mô, cùng tệp khách) — nếu conversion / order accuracy không cải
thiện → rollback. Tôi đã thấy rất nhiều "feature đẹp" rơi vào silence sau
khi launch — đó là vì bỏ qua step beta.

---

## Liên kết

- Schema reference: `schemas/Backend/Product/ToppingGroup.yaml` + ToppingGroupItem.yaml
- Tracker: issue #151 — P3 closed by chính PR mang doc này
- Tham chiếu chuẩn: Square Catalog API · Toast Modifier Groups · DoorDash Marketplace
