---
plan: 052
title: Print pipeline v2 — multi-transport theo máy in + print-jobs ledger + reprint authorization
slug: print-pipeline-v2
issue: 1166
status: shipped
branch: feature/plan-052-print-pipeline-v2
created: 2026-07-28
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); tracker closed. TASKS.md
  checkboxes are NOT the completion signal — plan-028 sits at 0/123 and plan-051
  at 0/15 while both shipped (#1842). Verified by: no feature branch remains,
  plus a closed tracker or the plan's subject being present in the tree.
---

# Plan 052 — Print pipeline v2

In ấn hiện tại **bắt buộc workstation** (browser không mở được raw TCP tới máy
ESC/POS): quán cloud-only KHÔNG in được gì — nút in ẩn, silent no-op
(`workstation-print-service.ts`: "never falls back to Cloud"). Plan này mở
**4 transport chọn theo năng lực máy in**, thêm **print-jobs ledger tập trung**
(Cloud biết job nào fail ở quán nào — hiện tại mù), **ACK/retry**, **reprint
authorization**, và dọn trùng lặp printer giữa 2 registry.

## Product rulings (chốt với owner 2026-07-28)

1. **Mixed content (HTTPS page → HTTP máy in) là VẤN ĐỀ CỦA QUÁN, không phải
   của hệ thống.** Hệ thống chỉ CẢNH BÁO (banner giải thích vì sao browser
   chặn) + nêu 3 đường thoát: cài workstation, mua máy hỗ trợ HTTPS (Epson
   TM-i / Star cài cert), hoặc dòng CloudPRNT. KHÔNG build proxy/hack, KHÔNG
   nhận support burden cho lựa chọn phần cứng của quán.
2. Quán cùng LAN với máy in **hỗ trợ in trực tiếp từ browser** (Epson
   ePOS-Print, Star WebPRNT) thì phải in được không cần workstation.
3. Máy TCP-9100 thuần vẫn đi đường workstation như cũ — không bỏ đường nào.

## 4 transport (chọn per máy ở trang プリンター)

| transport | Máy | Đường đi | ACK |
|---|---|---|---|
| `ws_lan` (hiện tại) | ESC/POS TCP/USB thuần | workstation dispatch, offline-first GIỮ NGUYÊN | workstation journal → sync UP |
| `epos_http` | Epson TM-i series | pos-web POST thẳng ePOS XML tới máy | response máy in → pos-web ghi ledger |
| `webprnt` | Star WebPRNT/mC-Print | pos-web POST thẳng (Star JS) | như trên |
| `cloudprnt` | Star CloudPRNT | **máy in tự poll Cloud**, không cần LAN/PC | built-in của protocol |

## Ràng buộc cứng

- **Offline-first của đường workstation là bất khả xâm phạm**: ledger là
  JOURNAL eventually-consistent, KHÔNG BAO GIỜ là gate — mất Cloud quán vẫn
  in như hôm nay, journal sync UP sau.
- **Chứng từ tiền (receipt/invoice/赤伝) không bao giờ auto-retry** — nguy cơ
  double-print 2 bản gốc; chỉ operator bấm lại (và ăn Bản in #N). Đơn bếp thì
  auto-retry được.
- Reprint chứng từ tiền cần quyền (mẫu #1124): non-manager bị chặn hoặc bắt
  reason; mọi reprint ghi actor vào ledger.
- Dedup registry: gỡ `receipt_printer/kitchen_printer/bar_printer` khỏi
  `PeripheralDeviceService::ALLOWED_TYPES` — máy in một cửa duy nhất
  (`printers`); 周辺機器 chỉ còn thiết bị thanh toán.

## Documents

- [DESIGN.md](DESIGN.md) — schema ledger, giao thức per transport, offline
  journal, reprint gate
- [EDGE-CASES.md](EDGE-CASES.md) — P-01…P-41 (ACK-lost, double-poll, kẹt giấy
  giữa job, journal dedupe, version-skew bundle, parity manifest…)
- [RISKS.md](RISKS.md) — double-print chứng từ 🔴, phá offline-first 🔴
- `TASKS.md` / `TESTS.md`

Liên quan: #1169 (ws serve pos-web — TIÊN QUYẾT quán nhiều máy, T3.5–T3.7),
#1170 (cloud PWA offline shell — ranh giới không-offline-sales),
#1156/#1157 (accepts/UI), bảng `printers` (plan-038 lineage),
`payments.metadata.print_history` (giữ, ledger là lớp trên).
