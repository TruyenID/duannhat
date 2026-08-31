# Plan 052 — Khảo sát chuẩn ngành in ấn POS (2026-07-28)

## 1. Bốn nguồn chuẩn tham chiếu

### UnifiedPOS (UPOS/OMG, gốc NRF-ARTS) — NGỮ NGHĨA trạng thái máy in
Chuẩn ngành bán lẻ cho POSPrinter (+ cash drawer, scanner…): định nghĩa
status/error semantics chuẩn hoá — **CoverOpen, PaperEnd, PaperNearEnd**,
per-station events (receipt/slip/journal), thứ tự sự kiện station-specific →
general. Bài học áp dụng: **vocabulary trạng thái của ledger phải map được về
tập UPOS** (cover_open, paper_end, paper_near_end, offline) thay vì string
tuỳ hứng per vendor — mọi transport quy về cùng enum.

### Star CloudPRNT — giao thức printer-polls-server
Vòng đời chuẩn: máy in **POST poll** (kèm status JSON của chính nó, chu kỳ
cấu hình được) → server trả "có job" → máy in **GET** lấy job (media type
negotiation) → in → **DELETE confirm kèm code** (thành công/thất bại đều
confirm). Nghĩa là protocol TỰ CÓ: job queue, ACK vòng kín, status report
định kỳ, retry tự nhiên (poll tiếp). Đây chính là hình dạng bảng
`print_jobs` + state machine của plan này — không phát minh gì mới.

### Epson Server Direct Print (TM-Intelligent/TM-i) — cùng mẫu, vendor thứ hai
Máy in gửi HTTP request định kỳ (~60s, cấu hình) → server trả **ePOS-Print
XML** → in xong máy POST **printing result + printer status** về. Xác nhận
mẫu printer-polls-server là chuẩn de-facto của CẢ HAI vendor thống trị thị
trường máy in nhiệt Nhật → thiết kế cloudprnt transport của plan trừu tượng
đủ cho cả hai (khác nhau ở payload format + endpoint shape, cùng lifecycle).

### PWG 5100.18 IPP INFRA (v1.1, approved 05/2025) — CHUẨN của mô hình sở hữu queue
Cloud Imaging Model định nghĩa đúng hai vai của §1b DESIGN: **Infrastructure
Printer** (cloud) = "Spooling Service that manages Jobs on behalf of the
Proxy" — thiết bị/proxy TỰ FETCH job (= cloudprnt mode); **Proxy** (local,
trước output devices) = "synchronizes the state of its Output Devices, Jobs,
and Documents with the Infrastructure Printer" (= workstation: queue local +
sync UP facts). Ruling owner "queue ở tầng gần máy in nhất, Cloud chỉ sync
đã-in-hay-chưa" trùng 1:1 với chuẩn này — CloudPRNT/Epson SDP là hiện thực
vendor của cùng mẫu fetch.

### CUPS/IPP — vocabulary vòng đời job
Chuẩn in ấn chung (RFC 8011): job states **pending → processing → completed
| aborted | canceled** + state reasons. Ledger dùng cùng ngữ pháp
(`queued → delivering → printed | failed | expired` + `needs_attention` cho
ACK-lost) — người vận hành từng dùng bất kỳ hệ in nào đều đọc hiểu ngay.

### Bài học ngược: Google Cloud Print (khai tử 2020)
Proxy-in-đám-mây thuần chết vì phụ thuộc một trung gian độc quyền. Kiến trúc
đúng của ngành hiện nay (Square/Toast/Airレジ đều vậy): **cloud là job
queue + ledger, việc in là printer-native protocol** (CloudPRNT/SDP) hoặc
local hub (workstation). Plan-052 đi đúng đường này.

## 2. Điểm hội tụ → quyết định thiết kế

| Chuẩn | Quyết định plan-052 |
|---|---|
| UPOS status semantics | Enum trạng thái máy in chuẩn hoá cross-vendor trong ledger + printers.last_status |
| CloudPRNT poll→GET→DELETE-confirm | State machine job + ACK bắt buộc; cloudprnt endpoint theo đúng spec Star (media negotiation, confirm code) |
| Epson SDP result-POST | Cùng abstraction; transport thứ 2 chứng minh không vendor-lock |
| IPP job states | Vocabulary `queued/delivering/printed/failed/expired/needs_attention` |
| GCP post-mortem | Không proxy trung gian; cloud = queue/ledger, in = native protocol hoặc workstation |
| Fiscal/インボイス practice | Reprint chứng từ tiền: đánh dấu bản in (Bản in #N — đã có), authorization + audit trail (mẫu #1124), KHÔNG BAO GIỜ auto-retry chứng từ tiền |

## 3. Vì sao ledger tập trung là chuẩn chứ không phải gold-plating

Mọi hệ trong khảo sát đều có server-side job store làm sự thật: CloudPRNT và
SDP THIẾT KẾ QUANH nó (máy in là client của queue); CUPS là spooler đúng
nghĩa; UPOS tồn tại để app biết máy in đang sống hay chết. Hệ hiện tại của
Tempo (fire-and-forget, "operator gets no signal" khi route miss) là dưới
chuẩn ngành ở đúng một điểm đó — plan này đưa về chuẩn mà không đập đường
workstation offline-first (ledger = journal, không phải gate).

Sources: [PWG 5100.18 IPP INFRA v1.1](https://ftp.pwg.org/pub/pwg/candidates/cs-ippinfra11-20250502-5100.18.pdf) ·
[Star CloudPRNT Protocol Guide](https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-guide.html) ·
[CloudPRNT job confirmation (DELETE)](https://star-m.jp/products/s_print/sdk/StarCloudPRNT/manual/en/protocol-reference/http-method-reference/job-confirmation-delete/index.html) ·
[Epson Server Direct Print](https://download4.epson.biz/sec_pubs/pos/reference_en/technology/server_direct_print.html) ·
[UnifiedPOS spec (OMG)](https://www.omg.org/spec/UPOS/1.15/About-UPOS) ·
[UPOS 1.9 PDF](https://public.dhe.ibm.com/software/retail/poseng/upos/upos19.pdf)
