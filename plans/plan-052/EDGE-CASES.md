# Plan 052 — Edge cases (P-01…P-18)

> Nhãn: **[HARD]** chặn/throw · **[DEFINED]** hành vi định nghĩa rõ, có test.

## Job lifecycle & ACK

- **P-01 [DEFINED] Double-poll / double-GET** (cloudprnt lấy cùng job 2 lần —
  mạng chập): job idempotent theo id; GET lần 2 khi `delivering` trả lại CÙNG
  payload (máy in Star tự dedupe theo jobToken); confirm chỉ áp một lần.
- **P-02 [DEFINED] Confirm DELETE lặp** (retry của máy in): idempotent —
  status đã terminal thì 200 no-op, không đổi attempts.
- **P-03 [DEFINED] ACK-lost** (in RỒI nhưng confirm không tới): job →
  `needs_attention` sau timeout per transport. **Chứng từ tiền KHÔNG BAO GIỜ
  auto-retry** từ trạng thái này (nguy cơ 2 bản gốc) — operator xem, bấm
  reprint có chủ đích (ăn Bản in #N). Đơn bếp: auto-retry được (P-05).
- **P-04 [DEFINED] Kẹt giấy/mở nắp GIỮA job** (UPOS CoverOpen/PaperEnd từ
  status poll hoặc result-POST): job `failed` + lý do chuẩn hoá UPOS enum;
  máy in báo status ở poll kế tiếp → printers.last_status cập nhật, UI quán
  hiện cảnh báo.
- **P-05 [DEFINED] Retry matrix per kind**: kitchen/bar/label → auto-retry N
  lần backoff; receipt/red_invoice/debt_slip → không bao giờ auto; report →
  auto 1 lần. Bảng nằm trong registry code, có test khoá.
- **P-06 [DEFINED] Job expired** (máy tắt cả ngày, job cũ vô nghĩa): TTL per
  kind (kitchen 15' — món nguội in làm gì; receipt 24h), quá hạn →
  `expired`, không bao giờ in muộn một ticket bếp của ca trước.

## Offline & journal (ws_lan)

- **P-07 [DEFINED] Workstation offline in local rồi sync journal muộn**:
  client-generated job id → replay idempotent (mẫu #1092), ledger nhận row
  `printed` quá khứ với timestamp thật của bản in (không phải giờ sync).
- **P-08 [HARD] Ledger không bao giờ là gate của ws_lan**: đường in local
  không chờ/không hỏi Cloud — arch test khoá: handler in của workstation
  không import HTTP client Cloud trong critical path.
- **P-09 [DEFINED] Journal sync trùng** (retry sync UP): UNIQUE job id ở DB —
  bài học idempotency-ở-constraint của plan-048/050.

## Reprint & chứng từ

- **P-10 [DEFINED] Reprint thiếu reason**: **VẪN IN** (ruling owner
  2026-07-28 — không bao giờ chặn in). Ledger ghi `reason: null` +
  `warned_without_reason: true` + actor + `reprint_no`. POS hiện cảnh báo
  trước khi in, bỏ qua được. Role KHÔNG chặn, chỉ đổi mức nhắc.
- **P-10b [HARD] Bản in ≥2 phải mang dấu 再印刷 #N**: block `reprint_marker`
  là locked (plan-053), không ai tắt/đổi được — đây là cơ chế chống-hai-bản-gốc
  thật sự, thay cho việc chặn.
- **P-11 [DEFINED] Reprint khi bản gốc còn `delivering`**: cho phép (operator
  đứng cạnh máy biết rõ hơn hệ thống — giấy kẹt nửa tờ), cả hai bản đều ghi
  ledger, bản sau reprint_no+1; KHÔNG khoá chờ terminal state.
- **P-12 [DEFINED] 「Bản in #N」 giữ nguyên cơ chế atomic hiện có**
  (`AppendPrintHistory` BEGIN IMMEDIATE) — ledger chỉ thêm lớp actor/kênh;
  hai nguồn số N không được rẽ nhánh: ws_lan lấy N từ workstation (nguồn cũ),
  transport trực tiếp lấy N từ Cloud — mỗi payment chỉ MỘT nguồn cấp N
  (theo transport của máy in receipt của quán), test khoá.

## Transport trực tiếp (epos_http / webprnt)

- **P-13 [DEFINED] Mixed content bị browser chặn**: job `needs_attention` +
  reason `mixed_content_blocked`, banner cảnh báo (ruling: vấn đề của quán —
  hệ thống chỉ nêu 3 đường thoát, không proxy).
- **P-14 [DEFINED] Browser đóng giữa chừng** (đã POST tới máy in, chưa kịp
  ACK về Cloud): giống P-03 ACK-lost — needs_attention, không auto-retry
  chứng từ.
- **P-15 [DEFINED] pos-web render payload ở đâu**: KHÔNG — server render
  (ePOS XML/WebPRNT payload từ cùng template formatter), pos-web chỉ là ống
  chuyển. Template không nhân bản sang TS (chống drift giữa hai bản cài đặt).

## Bảo mật & vận hành

- **P-16 [HARD] cloudprnt token**: per-printer, random ≥32 bytes, rotate
  được, revoke = 401 fail-closed ngay poll kế; token chỉ hiện một lần lúc
  tạo (mẫu secret peripheral).
- **P-17 [DEFINED] Payload PII**: ledger giữ template ref + data snapshot tối
  thiểu (không số thẻ, không địa chỉ đầy đủ khi kind=kitchen); retention xoá
  payload sau N ngày, giữ meta row cho audit.
- **P-18 [DEFINED] Registry dedup migration**: nếu tồn tại peripheral rows
  type printer (production chưa từng tạo — verify trước), migration báo danh
  sách và dừng, KHÔNG silent-delete; xoá là lệnh ops riêng có --force.

## Local hub / 2 builds / API parity (#1169 — T3.5/T3.6/T3.7)

- **P-19 [DEFINED] Version skew bundle ↔ API workstation**: tablet cache
  bundle cũ trong khi binary ws đã lên API mới (hoặc ngược lại sau rollback).
  Bundle mang build hash; ws expose `GET /pos/version` (bundle hash + api
  rev); app poll nhẹ lúc idle → lệch thì banner "phiên bản mới" + hard
  reload. KHÔNG silent-break: request có header bundle hash, ws log mismatch.
- **P-20 [DEFINED] Cache tablet**: assets hashed cache-forever; `index.html`
  + `/pos/version` serve với `Cache-Control: no-store` từ ws — tablet mở lại
  luôn lấy entry mới nhất, offline vẫn mở được nhờ asset cache cũ (app cũ
  chạy được là ưu tiên, ép update khi online lại — P-19).
- **P-21 [HARD] SPA fallback không được nuốt API**: `/pos/*` fallback về
  index.html nhưng `/api/*`, `/ws`, `/docs` đứng trước theo route order —
  test khoá: gọi API path không tồn tại phải ra 404 JSON, không ra HTML.
- **P-22 [DEFINED] Auth bootstrap trên same-origin**: mở `http://ws/pos` lần
  đầu chưa có token → flow pairing/login hiện có chạy nguyên (same-origin
  còn ĐƠN GIẢN hoá cookie/token scope); resolver mode same-origin =
  `location.origin`, không đọc localStorage pair URL (và ẩn luôn ô pair
  trong Settings ở build ws).
- **P-23 [DEFINED] Tải nhiều tablet**: ws đã phục vụ WS hub + API cho tablet;
  static thêm không đáng kể (embed, không disk I/O) — benchmark M2 đo kèm;
  giới hạn upload/log để tablet lỗi không kéo sập hub.
- **P-24 [HARD] Chống drift giữa 2 build**: CI build CẢ `build:cloud` +
  `build:workstation` mỗi commit + smoke boot cả hai; lint/arch rule cấm
  `import.meta.env.VITE_TARGET` ngoài entry/resolver config (không if-rừng
  trong logic nghiệp vụ — ruling owner).
- **P-25 [HARD] Manifest không được stale**: `pos-api-manifest.json`
  generated + COMMIT; CI regenerate rồi diff — khác là đỏ (mẫu omnify:diff).
  Sửa service layer mà quên manifest = không merge được.
- **P-26 [DEFINED] Mức khớp shape của parity test**: method + path + request
  fields bắt buộc + đúng các response fields pos-web THỰC ĐỌC (từ typed
  service layer) — không assert full schema backend (brittle vô ích); thêm
  field mới bên backend không đỏ, thiếu field pos-web cần mới đỏ.
- **P-27 [DEFINED] Route cloud-only trên build ws** (vd Stripe web payment
  intent): manifest đánh dấu `cloud_only` → parity ws bỏ qua route đó nhưng
  BẮT BUỘC feature gate ở UI build ws khi offline (online thì proxy forward
  bình thường); không có trạng thái "bấm nút → chết im".
- **P-28 [DEFINED] Bundle hỏng/rollback**: v1 bundle sống chết cùng binary —
  rollback = rollback binary ws (một artefact, một cơ chế); v2 auto-pull
  phải kèm checksum + giữ bundle N-1 để tự lùi khi boot fail. Ghi rõ v1
  trước, không over-engineer.

## Capability profile (§3b)

- **P-29 [DEFINED] Máy chưa có profile**: rơi về `escpos_generic` (native
  text, gs_v_full, no kick, 32/48 cột) — in được ngay, không chặn; UI gợi ý
  ops chọn/tinh chỉnh profile sau lần in đầu.
- **P-30 [DEFINED] Kanji trên máy không có ROM**: `charset.kanji=false` ⇒
  renderer tự chuyển khối chứa ký tự ngoài codepage sang raster; số/tiền giữ
  native (tốc độ) — test golden: cùng phiếu, 2 chế độ, nội dung đọc được như
  nhau.
- **P-31 [DEFINED] Đổi profile giữa ca**: áp cho bản in KẾ TIẾP; job đang
  delivering giữ profile lúc tạo (giống template_version #1171).
- **P-32 [DEFINED] Quirk `reconnect_between_jobs`**: dialer mở kết nối mới
  mỗi job thay vì giữ; nếu vẫn fail → retry matrix theo kind (P-05), không
  đặc cách.
- **P-33 [HARD] Không giả vờ biết đã in**: máy `error_detect=none` ⇒ ledger
  ghi `printed(confidence=sent_only)`, dashboard/alert phân biệt rõ với
  `printed(confirmed)`; cấm map sent→confirmed ở bất kỳ transport nào.
- **P-34 [DEFINED] Preflight status trước chứng từ tiền**: máy mức B/C →
  query status trước khi bắn receipt/invoice; cover open / paper end ⇒ chặn
  và báo NGAY, không bắn vào máy đang lỗi rồi mới biết.
- **P-35 [DEFINED] Hết giấy GIỮA job**: mức B (ASB bất đồng bộ) → job
  `failed:paper_end`, phần đã in dở coi như hỏng, operator thay giấy rồi
  reprint (ăn Bản in #N — không auto vì là chứng từ, P-05).
- **P-36 [DEFINED] `cut.mode=none`**: feed bù rồi dừng, KHÔNG gửi lệnh cut
  mù (máy lạ có thể in ra ký tự rác).
- **P-37 [DEFINED] Két không kick được** (`drawer_kick.supported=false`): UI
  ẩn nút mở két + cảnh báo khi quán dùng tender cash — không nuốt hành động
  im lặng.
- **P-38 [DEFINED] Health method theo tầng sở hữu queue**: workstation probe
  máy của nó; Cloud chỉ probe cloudprnt bằng `poll_silence` (máy sau NAT,
  không dial ngược được).
- **P-39 [HARD] Nhánh cloud-only không được bật khi chưa có renderer Cloud**:
  transport epos/webprnt/cloudprnt bị từ chối ở tầng cấu hình khi #1171
  Phase 2 chưa sẵn (fail-closed, thông báo rõ) — không để quán chọn được một
  transport mà hệ không dựng nổi payload.
- **P-40 [DEFINED] Wizard trên máy chưa biết gì**: chạy được với profile
  `escpos_generic`; mỗi câu trả lời của ops ghi vào profile ngay (partial
  cũng lưu), bỏ dở vẫn dùng được phần đã xác nhận.
- **P-41 [DEFINED] Wizard vs máy đang bận**: tờ diagnostic đi qua ledger như
  job kind=`diagnostic` (không phải chứng từ) — auto-retry được, không ăn số
  Bản in #N, không lẫn vào audit hoá đơn.
