# Plan 052 — Design

## 1. `print_jobs` ledger (Cloud — journal, không phải gate)

```
print_jobs
├── id                  uuid pk (client-generated cho ws_lan offline — idempotency)
├── organization_id / branch_id
├── printer_id          fk printers nullable (cloudprnt bắt buộc; epos/webprnt theo config)
├── transport           ws_lan | epos_http | webprnt | cloudprnt
├── kind                receipt | kitchen | bar | red_invoice | debt_slip | report | label
├── order_id / payment_id   nullable fk
├── payload             json — TEMPLATE REF + data snapshot (KHÔNG raw bytes;
│                       render per transport lúc deliver: ESC/POS bytes / ePOS XML / WebPRNT)
├── reprint_no          int (đồng bộ với payments.metadata.print_history — giữ nguyên cơ chế cũ)
├── requested_by_id     actor user nullable + requested_via (pos|handy|auto|kiosk)
├── reprint_reason      string nullable (bắt buộc khi reprint chứng từ tiền — gate §4)
├── status              queued → delivering → printed | failed | needs_attention | expired
├── attempts / last_error
├── acked_at / printed_reported_at
└── created_at / updated_at
INDEX (branch_id, status), (printer_id, status), (order_id)
```

- **Nguồn sự thật cho "đã in chưa" ở tầng HQ/Cloud**; tầng quán vẫn là máy
  móc hiện có (kitchen `printed_at` per item, `print_history` per payment —
  không đổi, ledger là lớp trên).
- `status=needs_attention`: ACK-lost (gửi rồi, không có xác nhận) — KHÔNG
  auto-retry với chứng từ tiền, operator quyết (P-03).

## 1b. Nguyên tắc sở hữu queue (ruling owner 2026-07-28)

**Queue vận hành (retry/dispatch/ordering) sống ở TẦNG GẦN MÁY IN NHẤT;
Cloud chỉ sync sự thật "đã in hay chưa".** Ledger vì thế có 2 chế độ per
transport:

| transport | Queue owner | Chế độ ledger |
|---|---|---|
| ws_lan | **Workstation** (queue local hôm nay, tự retry/dispatch — Cloud KHÔNG BAO GIỜ schedule/retry hộ) | **journal mode**: row là FACT bất biến đến-sau (sinh ra đã có kết cục + giờ in thật); Cloud không chuyển trạng thái row ws_lan |
| epos/webprnt | Operator tại chỗ (pos-web là ống ephemeral) | Cloud giữ job+payload (browser không giữ được), ghi ACK — queue tối giản |
| cloudprnt | **Cloud** — máy in poll thẳng Cloud nên Cloud CHÍNH LÀ tầng gần nhất (ngoại lệ hợp lệ duy nhất) | queue mode đầy đủ |
| `app_bt` (TƯƠNG LAI — pos-app native + máy in Bluetooth, vd xe lưu động; browser không làm được: Web Bluetooth không SPP, cần StarXpand/ePOS native SDK) | **Chính cái app** = micro-workstation/Proxy PWG: queue local SQLite, BT SDK drive máy, tự retry, nguồn số Bản in #N | journal mode y hệt ws_lan; BT SDK status → UPOS enum; offline-sales hợp lệ vì app native giữ key trong Secure Enclave (expo-secure-store) → đúng mô hình evidence #1092, trust TỐT HƠN browser |

Hệ quả: retry matrix (P-05) chỉ áp ở tầng SỞ HỮU queue — Cloud chạy matrix
cho cloudprnt; ws_lan giữ nguyên logic retry của workstation. Nhiệm vụ MỚI
duy nhất của workstation = sync UP kết quả in (P-07). Không có state
tranh chấp Cloud↔ws vì row journal-mode là append-fact.

## 2. Giao thức per transport

### ws_lan (giữ nguyên + journal)
Workstation in như hôm nay (local queue, offline-first). Thêm: mỗi lệnh in
ghi journal local → **sync UP** thành print_jobs rows (client-generated id →
replay idempotent, mẫu #1092). Cloud outage = in bình thường, journal dồn.

### epos_http / webprnt (pos-web in thẳng — không workstation)
1. pos-web tạo job qua API Cloud (`POST /pos/print-jobs`) → nhận payload đã
   render sẵn cho transport (server render — formatter/template KHÔNG nhân
   bản sang TS).
2. pos-web POST tới máy in LAN (ePOS XML endpoint / WebPRNT).
3. Response máy in → pos-web báo `POST /pos/print-jobs/{id}/ack|fail`.
4. Browser chặn mixed-content → banner cảnh báo (ruling: vấn đề của quán) +
   job đứng `needs_attention` kèm lý do `mixed_content_blocked`.

### cloudprnt (máy in tự poll — không LAN/PC)
⚠️ **Bản phác dưới đây ĐẢO NGƯỢC giao thức thật — giữ lại để không ai suy ra
lại nó.** Nó viết GET là poll và POST là confirm. Star CloudPRNT làm ngược:

| verb | vai thật |
|---|---|
| `POST` | máy in **poll** + báo trạng thái → server trả `{jobReady, mediaTypes, jobToken, deleteMethod}` |
| `GET`  | máy in **tải bytes** của job |
| `DELETE` | máy in **xác nhận** đã in (`?code=200%20OK`) hoặc báo lỗi |

Dựng theo bản phác cũ thì **không bao giờ nói chuyện được với máy thật**: máy in
mở đầu bằng POST và sẽ không bao giờ nhận được câu trả lời nó chờ. Sai này tồn
tại được vì nó *nghe hợp lý* — GET-để-đọc, POST-để-ghi là phản xạ REST — nên
không đọc tài liệu Star thì không có gì gợn.

Đã cài đúng ở T5.4 (#1171): ba verb trên **cùng một URL**
`/api/v1/print/cloudprnt/{printerToken}`, token per-printer (revoke = 401
fail-closed). Nguồn: Star CloudPRNT Protocol Guide (star-m.jp), mục
*server-polling-post* / *job-request-get* / *job-confirmation-delete*.

Bản phác gốc, để đối chiếu:

> - `GET  /api/v1/print/cloudprnt/{printerToken}` — máy in poll (Star CloudPRNT
>   protocol: trả job pending + media type), per-printer token (rotate được,
>   revoke = 401 fail-closed).
> - `POST /api/v1/print/cloudprnt/{printerToken}` — máy in confirm kết quả →
>   ledger printed/failed. Idempotent theo job id (P-02 double-poll).

## 3. Chọn transport

`printers.transport` (default `ws_lan` — zero behavior change khi migrate) +
`printers.print_token` (cloudprnt). Trang プリンター thêm select + hướng dẫn
per dòng máy. `connection_type/address` giữ nguyên ngữ nghĩa cũ cho ws_lan.


## 3b. Printer capability matrix — cùng một nội dung, in đúng cách theo máy

Máy in khác nhau ⇒ transport + quirk khác nhau, NHƯNG nội dung phiếu là một
(template #1171). Khác biệt gói vào **capability profile per model**, không
rải if trong formatter.

### Ma trận model × transport

| Nhóm máy | ws_lan | epos_http | webprnt | cloudprnt | Ghi chú |
|---|---|---|---|---|---|
| Epson TM-i (TM-m30-i, T88-i…) | ✅ | ✅ | — | — | HTTP server nhúng; SDP = biến thể poll |
| Star mC-Print / TSP WebPRNT | ✅ | — | ✅ | ✅ | Star full-stack |
| Epson/Star bản THƯỜNG (không -i) | ✅ | — | — | — | chỉ raw TCP/USB |
| **ESC/POS generic (Xprinter/Gprinter/Rongta/HPRT/Zjiang…)** | ✅ | — | — | — | **Bắt buộc có workstation**; kiểm codepage JP (§quirks) |
| Máy in nhãn (label) | ✅ (đường label sẵn có) | — | — | — | ngoài scope plan này |

Hệ quả sản phẩm phải nói thẳng trong docs mua máy: **máy rẻ = cần một
PC/mini-PC làm hub; muốn bỏ PC = phải mua Epson TM-i / Star**.

### Capability profile (per model, data — không phải code)

`printers.model_profile` (hoặc bảng `printer_profiles` khi đủ nhiều) khai:

```
{ "transports": ["ws_lan"],
  "charset": { "kanji": false, "codepages": ["CP437","CP858"] },  // → bật raster fallback
  "text_mode": "auto|native|raster",   // raster: render text→bitmap (ESC GS y D đã có)
  "cut": "gs_v_full|gs_v_partial|none",
  "drawer_kick": { "supported": true, "pulse_ms": 120 },
  "columns": { "58mm": 32, "80mm": 48 },
  "quirks": ["reconnect_between_jobs","slow_raster"] }
```

- Formatter/renderer nhận profile → chọn ĐƯỜNG RA, không đổi NỘI DUNG.
- **Fallback an toàn cho máy lạ**: profile mặc định `escpos_generic`
  (native text, cut gs_v_full, không kick, 32/48 cột) — máy chưa từng test
  vẫn in được; ops chỉnh profile khi phát hiện quirk, KHÔNG cần release.
- Profile là DATA sync như config khác ⇒ thêm model mới = thêm row, không
  build lại workstation.

### Quirks đã biết (nuôi tiếp theo pilot thật)

| Quirk | Triệu chứng | Xử lý trong profile |
|---|---|---|
| Không có kanji ROM (nhiều máy TQ nhập ngoài JP) | 漢字 ra ô vuông/rác | `charset.kanji=false` → `text_mode=raster` (font server-side, in ảnh) |
| Cut lệnh khác / lờ cut | không cắt giấy | `cut` variant hoặc `none` (feed thêm dòng) |
| Kick két không nhả | két không mở | `drawer_kick.pulse_ms` hoặc `supported=false` (cảnh báo UI) |
| Rớt TCP khi in liên tiếp | job thứ 2 fail | `quirks:["reconnect_between_jobs"]` — dialer mở lại mỗi job; retry của ws_lan đỡ phần còn lại |
| Raster chậm | in lâu, nghẽn queue | `slow_raster` → chỉ raster khối cần (tên món JP), phần số dùng native |


### Finishing / error-detect / health — khai đủ trong profile

**1. Finishing (cut, feed, kick, buzzer)** — mỗi máy một kiểu, khai data:

```
"finishing": {
  "cut": { "mode": "gs_v_partial|gs_v_full|esc_i|esc_m|none",
           "feed_before_cut": 4,          // dòng feed để nội dung qua khỏi dao
           "auto_cut_per_job": true },    // máy tự cut cuối job hay phải gửi lệnh
  "drawer_kick": { "supported": true, "pin": 2, "on_ms": 120, "off_ms": 240 },
  "buzzer": { "supported": false }
}
```
- `cut.mode=none` (máy không dao / tear-bar): renderer feed thêm rồi dừng —
  KHÔNG gửi lệnh cut mù (một số máy in ra ký tự rác khi gặp lệnh lạ).
- `feed_before_cut` là quirk vật lý: khoảng cách đầu in → dao khác nhau,
  thiếu thì cắt mất dòng cuối. Chỉnh bằng data, không sửa code.
- Kick két: pin/timing khác nhau; `supported=false` ⇒ UI ẩn nút mở két và
  cảnh báo khi quán chọn tender cash (không im lặng nuốt hành động).

**2. Error detect — 3 MỨC năng lực, phải phân biệt rõ (P-33)**

| Mức | Máy | Cách biết lỗi | Ledger |
|---|---|---|---|
| A. Không phản hồi | ESC/POS TCP thuần rẻ | chỉ biết socket ghi được/không | `printed` = "đã gửi xong bytes"; kẹt giấy KHÔNG phát hiện được |
| B. Status-back | ESC/POS có ASB/DLE EOT (`transmit_status`) | đọc byte trạng thái: paper end/near-end, cover open, error, offline | map thẳng UPOS enum |
| C. Protocol-native | ePOS/WebPRNT/CloudPRNT | response/confirm mang status | chính xác nhất |

```
"error_detect": { "level": "none|status_back|protocol",
                  "asb": true,            // Automatic Status Back bật được
                  "dle_eot": true,        // query status realtime
                  "poll_interval_s": 30 }
```
- **Nguyên tắc trung thực**: máy mức A thì ledger ghi
  `printed(confidence=sent_only)` — KHÔNG được giả vờ biết đã in xong. Dashboard
  hiển thị khác nhau, ops không bị ru ngủ.
- Máy mức B: workstation bật ASB → nhận status bất đồng bộ (hết giấy giữa
  chừng vẫn báo được) hoặc query DLE EOT trước job quan trọng
  (`preflight_status` cho receipt/invoice — không cho bắn chứng từ vào máy
  đang mở nắp).

**3. Health check / connection** — cũng theo mức năng lực:

```
"health": { "method": "tcp_dial|dle_eot|http_ping|poll_silence",
            "interval_s": 60, "timeout_ms": 3000,
            "offline_after_misses": 3 }
```
- `tcp_dial`: chỉ chứng minh cổng mở (máy A) — đủ để biết "mất điện/rút mạng".
- `dle_eot`: hỏi thật máy (máy B) — biết cả hết giấy khi đang rảnh.
- `http_ping`: máy ePOS/WebPRNT.
- `poll_silence`: cloudprnt — máy im quá N chu kỳ = coi như offline (không
  dial ngược được vì máy nằm sau NAT của quán).
- Kết quả → `printers.last_status` (UPOS enum) + `last_seen_at`; im lặng quá
  ngưỡng → alert (M2/T2.3). Health chạy ở TẦNG SỞ HỮU QUEUE (§1b):
  workstation tự probe máy của nó; Cloud chỉ probe cloudprnt.

**Chống bùng nổ tổ hợp**: profile là data + 3 enum nhỏ (cut mode /
error level / health method) ⇒ thêm model = thêm row. Renderer chỉ có 3
nhánh error-level và 4 nhánh health-method, test được đầy đủ; KHÔNG có
nhánh per-model trong code.

**Nguyên tắc**: mọi khác biệt máy in nằm trong PROFILE (data) + RENDERER
(chọn đường ra), KHÔNG bao giờ nằm trong template hay logic nghiệp vụ.


## 3c. Thứ tự phụ thuộc CỨNG: Cloud phải render được TRƯỚC nhánh cloud-only

**Phân biệt rõ hai việc thường bị gộp:**

| Việc | Cần gì | Chặn ai |
|---|---|---|
| **Quản template TỪ CLOUD, sync DOWN về workstation** (#1171 Phase 1) | definition-as-data + versioning + CHỈ renderer Go (đã có) | **Không chặn ai** — làm ngay được; workstation cache definition, offline vẫn in bằng version đã pull, y như shop_settings/printers hôm nay |
| **Cloud TỰ RENDER ra payload** (#1171 Phase 2) | thêm renderer PHP + golden parity Go↔PHP | **Chặn M3/M4** |

Vì sao Phase 2 là điều kiện cứng của nhánh cloud-only: quán đó theo định
nghĩa KHÔNG CÓ workstation ⇒ không có Go process nào ở quán để render hộ ⇒
Cloud phải tự sinh bytes/ePOS-XML. Không có đường vòng.

```
#1171 Phase 2 (definition-as-data + renderer PHP ở Cloud + golden parity Go↔PHP)
        │  ĐIỀU KIỆN CỨNG
        ▼
plan-052 M3 (epos_http/webprnt)  +  M4 (cloudprnt)
```

- M1/M2 (ledger, journal ws_lan, reprint gate, profile, wizard) KHÔNG phụ
  thuộc #1171 — làm trước được, đường ws_lan vẫn dùng formatter Go hiện tại.
- Golden parity Go↔PHP là bắt buộc TRƯỚC khi bật transport cloud: cùng
  definition + cùng data ⇒ hai renderer ra phiếu tương đương, nếu không thì
  cùng một quán in hai kiểu tuỳ đường.
- Máy in raster (profile `text_mode=raster`, máy TQ không kanji): renderer
  PHP cũng phải có font server-side + cùng thuật toán dựng bitmap như Go,
  nếu không phiếu cloud-only sẽ khác phiếu workstation từng pixel.

## 4. Reprint policy — CẢNH BÁO, KHÔNG CHẶN (ruling owner 2026-07-28)

**Nguyên tắc tối thượng: hệ thống KHÔNG BAO GIỜ chặn một lệnh in.** Nhân viên
đứng ở quầy, khách đang đợi; nếu máy vừa kẹt giấy thật mà phần mềm từ chối in
lại thì phần mềm đang phá việc kinh doanh — thiệt hại lớn hơn mọi rủi ro nó
định ngăn.

| | Cũ (sai) | ĐÚNG |
|---|---|---|
| Non-manager in lại chứng từ tiền thiếu lý do | **422 chặn** | **Cho in**, hiện cảnh báo, ghi ledger |
| Ai được in lại | gate theo role | **Ai cũng in được**; role chỉ ảnh hưởng mức cảnh báo/nhắc lý do |

**Bù lại bằng 3 thứ BẮT BUỘC — kiểm soát bằng dấu vết, không bằng rào cản:**

1. **ĐẾM**: mỗi lần in đều tăng `reprint_no` (cơ chế `AppendPrintHistory`
   atomic đã có) — biết chính xác tờ này là bản in thứ mấy.
2. **AI IN**: `actor_user_id` + kênh (pos/handy/auto) ghi vào ledger MỌI lần
   in, kể cả bản đầu. Lý do (`reason`) vẫn hỏi nhưng **để trống vẫn in được**;
   ledger ghi `reason: null` và cờ `warned_without_reason`.
3. **IN LẠI PHẢI HIỆN TRÊN GIẤY**: bản in thứ N ≥ 2 BẮT BUỘC mang dấu
   「BAN IN #N」 — block `reprint_marker` là **locked**
   (§plan-053: không ai tắt được, không ai đổi được nội dung). Hai tờ không
   bao giờ trông giống hai bản gốc — đây mới là thứ thật sự chặn gian lận,
   không phải cái 422.

**Vị trí dấu: TỰ DO (ruling owner 2026-07-28).** Luật インボイス không quy định
dấu in lại phải nằm ở đâu — **miễn tờ giấy nói rõ nó là bản in lại là đủ**.
Nên KHÔNG cần đợt phối hợp Cloud↔Go để dời dấu lên đầu phiếu: vị trí hiện tại
(đầu phiếu với vat_invoice/debt_slip, sau tổng với nhóm bill) là hợp lệ.
Chữ dùng ASCII `BAN IN #N` cũng đủ — nhiều máy không có kanji ROM, và TR-40
cấm đổi hình dạng phiếu đã nộp mà không có lý do thật.

**Cảnh báo hiển thị ở đâu**: POS hiện dialog trước khi in ("đây là bản in lại
lần thứ N, sẽ có dấu 再印刷 trên phiếu; lý do?") — bấm bỏ qua vẫn in. Admin
thấy đủ trong ledger + trang Print jobs.

**Không đổi**: chứng từ tiền vẫn KHÔNG BAO GIỜ **auto**-retry (PR1) — đó là
máy tự quyết, khác hẳn con người chủ động bấm in lại.

## 5. Registry dedup

Gỡ 3 printer types khỏi `PeripheralDeviceService::ALLOWED_TYPES` (+ migration
dọn rows type printer nếu có, báo trước khi xoá). `printers` là cửa duy nhất
cho máy in; 周辺機器 = thiết bị thanh toán.

## 6. Connectivity & mixed content — MA TRẬN CHUNG (owner catch 2026-07-28)

Phát hiện quan trọng của owner: **đường workstation hôm nay CŨNG là plain
HTTP** (pos-web pair `http://<IP-LAN>:<port>`, CORS ở `cors.go`) — mixed
content không phải vấn đề riêng của transport trực tiếp, nó là tính chất
chung của mọi kết nối HTTPS-page → LAN-HTTP. Ruling "vấn đề của quán, cảnh
báo là đủ" áp ĐỒNG NHẤT cho cả 4 transport.

| Cách quán chạy | ws_lan | epos_http/webprnt | Ghi chú |
|---|---|---|---|
| Browser POS chạy TRÊN chính PC workstation → pair `http://localhost:<port>` | ✅ | — | localhost là secure context, browser MIỄN TRỪ mixed content — đường thoát rẻ nhất cho setup 1-PC |
| **NHIỀU MÁY (tablet ≠ PC ws): tablet mở pos-web DO WORKSTATION SERVE** (`http://<ws-ip>:<port>/pos`) | ✅ | ✅ (cùng origin http) | **T3.5 — ĐIỀU KIỆN TIÊN QUYẾT, không phải cải tiến**: hiện trường hôm nay pos-web Amplify HTTPS + ws LAN = nút in KHÔNG hoạt động (browser chặn, không có workaround production nào tồn tại — tunnel chỉ là demo). Same-origin giải tận gốc + offline thật; hạ tầng serve có sẵn; khớp plan-cloud-first-workstation |
| pos-web qua HTTP nội bộ | ✅ | ✅ | không mixed content |
| HTTPS + Chrome kiosk policy cho phép LAN insecure | ✅ | ✅ | cấu hình của quán |
| HTTPS + tunnel workstation | ⚠️ CHỈ demo/dev (tunnel chạy trên máy dev; ws-app không bundle cloudflared, WS_APP_TUNNEL_HOSTS default strict) — **vận hành thực tế KHÔNG dùng tunnel** | ❌ | KHÔNG phải đường production |
| HTTPS + thiết bị TLS (máy in cert / ws_tls) | ✅ với T-tls | ✅ máy TM-i/Star HTTPS | |
| cloudprnt | — | — | miễn nhiễm (máy tự poll Cloud) |

- Phát hiện TĨNH không cần probe (page https + target http + host ∉
  {localhost,127.0.0.1} ⇒ chắc chắn bị chặn): cảnh báo NGAY LÚC PAIR (trước
  khi lưu URL) + short-circuit trước fetch lúc in → ledger
  `mixed_content_blocked` — phân biệt rạch ròi với workstation-chết.
- Banner cảnh báo mixed-content (P-13) hiển thị cho CẢ ws_lan khi pair URL
  http từ trang https — cùng một component, đường thoát gợi ý THEO SETUP
  (localhost nếu cùng PC / kiosk policy / TLS thiết bị / cloudprnt).
- **Tuỳ chọn hardening (task riêng, không blocker)**: `ws_tls` — workstation
  tự phát self-signed cert + hướng dẫn cài trust per thiết bị (cùng ritual
  với máy in HTTPS); docs nêu rõ trade-off tunnel (online-only) vs TLS local.

## 7. pos-web: 2 build modes + API parity (rulings owner 2026-07-28)

### 2 chế độ build riêng biệt, MỘT codebase

| | `build:cloud` | `build:workstation` |
|---|---|---|
| base | `/` | `/pos/` |
| Phân phối | Amplify (autoBuild push) | embed vào binary ws (go:embed) |
| Resolver mặc định | cloud-first, pair ws tay (đường cũ) | **same-origin**: ws = chính origin, cloud qua proxy ws |
| Target flag | `VITE_TARGET=cloud` | `VITE_TARGET=workstation` |

Một codebase — mode chỉ đổi base/resolver/manifest, CẤM if-rừng theo target
trong logic nghiệp vụ (khác biệt gói trong resolver + entry config). CI build
CẢ HAI mỗi commit để không mode nào mục.

### API parity cloud ↔ workstation (contract, không phải lời hứa)

pos-web build ws chạy trên MỌI api mà build cloud dùng ⇒ surface của
workstation (local handlers + proxy) phải KHỚP cloud `/api/v1/pos/*`:

1. **Route manifest là hợp đồng**: pos-web service layer export
   `pos-api-manifest.json` (path, method, request/response shape refs) —
   generated, commit cùng repo.
2. **Workstation parity test** đọc manifest → assert từng route: tồn tại
   (local handler HOẶC proxy rule) + shape tương thích. Route cloud mới mà
   ws chưa cover → test ĐỎ ở repo ws ngay khi bump manifest (mẫu các
   *_parity_test.go đã có: split_bill_tax_parity, reconcile parity).
3. **Backend là nguồn shape**: đổi contract ở backend → đổi manifest → đỏ ở
   ws cho tới khi handler/proxy theo kịp. Không bao giờ để pos-web build ws
   gọi một route chết im lặng.

## 8. Out of scope

Label printer workflow mới, KDS thay giấy, template editor, proxy/hack cho
mixed content (ruling), máy in Bluetooth.
