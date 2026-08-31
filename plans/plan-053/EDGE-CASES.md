# Plan 053 — Edge cases (TR-01…TR-40)

> **[HARD]** chặn/throw · **[CLAMP]** tự về biên · **[DEFINED]** hành vi rõ,
> có test. Mọi case đều phải có test tương ứng trong TESTS.md.

## A. Phân cấp & resolve

- **TR-01 [DEFINED] Brand chưa publish gì**: resolve → system default; in
  bình thường; admin hiện badge "đang dùng mẫu hệ thống".
- **TR-02 [DEFINED] Shop override + brand ra version mới**: override của shop
  GIỮ NGUYÊN các field nó sửa, các field khác theo brand mới (merge theo
  field, không phải thay cả tờ) — quyết định này phải rõ trong UI: "shop
  đang override 3 mục: logo, footer, greeting".
- **TR-03 [HARD] Shop sửa field ngoài `shop_editable`**: 422 lúc publish
  (validate), và UI không render được ô đó (defense in depth).
- **TR-04 [DEFINED] Brand thu hẹp `shop_editable` sau khi shop đã override**
  field vừa bị bỏ: override field đó **ngừng hiệu lực** (fallback brand),
  không xoá dữ liệu (nếu brand mở lại thì override sống lại); admin cảnh báo
  cho shop.
- **TR-05 [DEFINED] Máy mới chưa từng online**: cache rỗng → system default
  nhúng binary → vẫn in được.
- **TR-06 [HARD] Kind không tồn tại / definition sai kind**: 422 publish.
- **TR-07 [DEFINED] Branch chuyển sang brand khác** (M&A, hiếm): resolve theo
  brand hiện tại; job cũ vẫn giữ template_version cũ (fact bất biến).

## B. Vòng đời version

- **TR-08 [HARD] Sửa version đã published**: cấm tuyệt đối (immutable) — mọi
  API update trên published trả 409; sửa = tạo draft mới từ nó.
- **TR-09 [DEFINED] Hai người cùng sửa draft**: optimistic lock theo
  `updated_at`/version-of-draft → người sau nhận 409 "draft đã đổi", reload
  và merge tay (không auto-merge JSON).
- **TR-10 [DEFINED] Publish khi đã có version mới hơn** (race): so
  `parent_version_id` — nếu parent không phải published hiện tại → 409, buộc
  rebase.
- **TR-11 [DEFINED] `effective_from` trong quá khứ**: hợp lệ = hiệu lực ngay
  (không backdate phiếu đã in — bản in cũ giữ version của nó).
- **TR-12 [DEFINED] `effective_from` tương lai + workstation offline suốt
  giai đoạn đó**: đã cache trước thì tự chuyển đúng giờ (business time
  branch #1091); chưa cache thì dùng version cũ cho tới khi online — hợp lệ,
  không lỗi.
- **TR-13 [DEFINED] Retire version đang được cache**: retire chỉ ngăn dùng
  cho bản in MỚI; job đang chạy + reprint bản cũ vẫn đọc được (không xoá).
- **TR-14 [HARD] Validate chỉ lúc publish, KHÔNG lúc in**: renderer khi in
  gặp definition lỗi (dữ liệu hỏng/bit rot) → fallback system default + log
  lỗi to; **không bao giờ để quán không in được vì template lỗi**.

## C. Nội dung & compliance

- **TR-15 [HARD] Definition chứa biểu thức tính toán**: reject publish —
  template chỉ trình bày (nguyên tắc #1).
- **TR-16 [HARD] Đụng block locked** (đổi nội dung/thứ tự tax_breakdown,
  grand_total, registration_number, reprint_marker, red_invoice_marker):
  reject publish.
- **TR-17 [DEFINED] Tắt block `toggleable` mà luật vẫn cần**: catalog khai
  điều kiện (vd `registration_number` toggleable nhưng khi brand có số đăng
  ký thì bắt buộc bật) → validator từ chối; #1152 đã ruling: KHÔNG cảnh báo
  khi thiếu số (免税事業者 hợp pháp), nhưng CÓ số thì phải in.
- **TR-18 [HARD] 赤伝/void notice**: `red_invoice_marker` không thể tắt.
- **TR-19 [DEFINED] Thiếu locale**: fallback chain locale-của-máy → ja → en;
  log warning MỘT LẦN per (template, locale) — mẫu `warnedBrands` TaxResolver.
- **TR-20 [DEFINED] Text quá dài / ký tự lạ**: renderer wrap theo cột của
  profile; ký tự ngoài codepage → theo `text_mode` (raster nếu cần).
- **TR-21 [HARD] `source` ngoài allow-list** (URL ảnh tuỳ ý, biến lạ): reject
  publish — chống SSRF/inject vào máy in.
- **TR-22 [DEFINED] Logo quá lớn / sai tỉ lệ**: validate lúc upload (kích
  thước, DPI, đơn sắc hoá); ảnh vượt chiều rộng giấy → **[CLAMP]** scale về
  vừa cột, không tràn.

## D. Sync & offline

- **TR-23 [DEFINED] Mất mạng khi đang publish**: publish là transaction ở
  Cloud — hoặc xong hoặc không; workstation không biết gì cho tới lần pull
  kế.
- **TR-24 [DEFINED] Pull nửa chừng / checksum lệch**: bỏ bản tải dở, giữ
  cache cũ, retry lần sau — **không bao giờ ghi đè cache bằng dữ liệu chưa
  verify**.
- **TR-25 [DEFINED] Đồng hồ workstation lệch**: `effective_from` so theo
  business time branch từ Cloud khi online; offline dùng giờ máy — sai lệch
  chấp nhận được (chỉ ảnh hưởng thời điểm chuyển version, không ảnh hưởng
  tiền).
- **TR-26 [DEFINED] Nhiều workstation cùng branch** (hiếm): mỗi máy pull độc
  lập, cùng resolve ra một version → in giống nhau; lệch tạm thời trong lúc
  pull là chấp nhận được (job ghi version thực tế đã dùng).
- **TR-27 [DEFINED] Cache đầy/hỏng**: xoá được an toàn — pull lại từ Cloud,
  trong lúc đó dùng system default.

## E. Reprint & audit (giao với plan-052)

- **TR-28 [HARD] Reprint dùng version gốc**: `print_jobs.template_version`
  quyết định, KHÔNG dùng version hiện hành — 再発行 phải trung thực.
- **TR-29 [DEFINED] Version gốc đã retired**: vẫn render được (giữ vĩnh
  viễn); nếu thực sự không còn (dữ liệu lỗi) → in bằng version hiện hành +
  **đánh dấu rõ trên phiếu** "template đã thay đổi" + log; không im lặng.
- **TR-30 [DEFINED] 赤伝 của hoá đơn cũ**: dùng version của HOÁ ĐƠN GỐC, không
  phải version hiện tại (đối chiếu từng dòng).
- **TR-31 [DEFINED] Lịch sử thay đổi**: mọi publish ghi ai/khi nào/notes +
  diff so version trước — admin xem được "phiếu tháng 6 khác tháng 7 chỗ nào".

## F. Preview & renderer parity

- **TR-32 [HARD] Preview khác bản in thật**: cấm renderer thứ ba — preview
  dùng cùng renderer/definition; golden fixture khoá.
- **TR-33 [DEFINED] Preview với data mẫu**: bộ data mẫu chuẩn (nhiều dòng,
  2 rate thuế, discount, split payment, tiếng dài) — không cho preview bằng
  data rỗng rồi tưởng ổn.
- **TR-34 [HARD] Go ↔ PHP lệch** (Phase 2): golden fixture fail → CI đỏ cả
  hai repo; không bật transport cloud khi parity chưa xanh (plan-052 P-39).
- **TR-35 [DEFINED] Font raster khác version giữa 2 renderer**: fixture khai
  font family+version; lệch → fail.
- **TR-36 [DEFINED] Máy `text_mode=raster` + template nhiều khối**: render
  raster theo khối (không cả tờ) để giữ tốc độ; fixture phủ case này.

## G. Quyền & vận hành

- **TR-37 [HARD] Ai được publish**: brand-level cần role HQ (org-admin /
  brand-manager); shop override cần shop-manager. Cashier không thấy menu.
- **TR-38 [DEFINED] Publish nhầm → rollback**: publish lại version cũ (tạo
  version mới có definition = bản cũ, notes tự động "rollback từ vN") —
  KHÔNG un-publish (immutable).
- **TR-39 [DEFINED] Xoá brand/branch**: definition giữ (soft delete) để
  reprint lịch sử vẫn chạy.
- **TR-40 [DEFINED] Migration từ hard-code hiện tại**: sinh system default
  cho 13 kind từ chính formatter Go hôm nay + golden test "definition mới
  render ra byte-identical với formatter cũ" → mới được coi là tương đương
  (không đổi phiếu của ai lúc migrate).
