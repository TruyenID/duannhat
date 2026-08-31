---
plan: 053
title: Print template registry — quản trị template tập trung ở Cloud, sync DOWN, versioned
slug: print-template-registry
issue: 1171
status: draft
branch: feature/plan-053-template-registry
created: 2026-07-28
updated: 2026-07-28
parent: plan-052
---

# Plan 053 — Print template registry

**Một câu**: HQ (brand) hoặc shop sửa template phiếu in **trên Cloud** →
preview → publish thành **version bất biến** → **sync DOWN** về mọi
workstation → in ra phiếu mới, **không cần release phần mềm**.

Hôm nay: 13 template hard-code trong Go (workstation), đổi một dòng = sửa
code + build + release; Cloud chỉ quản THAM SỐ (tên quán, khổ giấy,
currency, locale, 登録番号), không quản LAYOUT.

## Hai phase, ranh giới cứng

| Phase | Nội dung | Chặn ai |
|---|---|---|
| **Phase 1** (plan này, M1–M4) | definition-as-data + versioning + phân cấp HQ/shop + preview + sync DOWN + renderer **Go** đọc definition | Không chặn ai — quán có workstation hưởng ngay |
| **Phase 2** (M5, tách được) | renderer **PHP** ở Cloud + golden parity Go↔PHP (kể cả raster) | Là ĐIỀU KIỆN CỨNG của plan-052 M3/M4 (quán cloud-only) |

## Nguyên tắc bất di bất dịch

1. **Template chỉ TRÌNH BÀY, không bao giờ TÍNH**. Tiền/thuế đến từ engine
   (`PerRateTaxBuckets`, allocations #1154) — definition không có biểu thức
   số học. Sai nguyên tắc này = phiếu và sổ lệch nhau.
2. **Khối compliance khoá cứng**: 登録番号, khối per-rate tax, tổng tiền,
   số hoá đơn, 「Bản in #N」, nhãn 赤伝 — shop/HQ chỉ được BẬT/TẮT nếu luật
   cho phép, không được sửa nội dung/thứ tự.
3. **Published = bất biến**. Sửa = tạo version mới. Không có UPDATE trên
   version đã publish.
4. **In lại dùng ĐÚNG version của bản in gốc** (`print_jobs.template_version`
   — plan-052) — 再発行 trung thực, 赤伝 đối chiếu được với hoá đơn gốc.
5. **Offline không bao giờ chặn in**: workstation in bằng version đã cache;
   không có Cloud = dùng bản cũ, hợp lệ.
6. **Sync DOWN đúng cơ chế đang chạy** (`shop_settings`/`printers`), không
   phát minh transport mới.

## Documents

- [DESIGN.md](DESIGN.md) — schema, phân cấp HQ/shop, vòng đời version,
  definition format, sync, preview, renderer contract
- [EDGE-CASES.md](EDGE-CASES.md) — TR-01…TR-40, mọi tình huống đã bàn
- Khảo sát chuẩn: xem `plans/plan-052/STANDARDS.md` — **UnifiedPOS (UPOS/OMG)**,
  **Star CloudPRNT**, **Epson Server Direct Print (TM-i)**, **PWG 5100.18 IPP
  INFRA**, **CUPS/IPP**, và bài học ngược từ **Google Cloud Print** (khai tử
  2020).

  > ⚠️ Dòng này từng ghi *"OFSC ReceiptLine, UPOS, ePOS/WebPRNT, ARTS Digital
  > Receipt"* (sửa ở #2061). Trong bốn cái tên đó **chỉ UPOS được khảo sát thật**;
  > **ReceiptLine · WebPRNT · ARTS Digital Receipt CHƯA BAO GIỜ có mặt** trong
  > STANDARDS.md — không phải bị gỡ, mà chưa từng được viết. (Chuỗi "ARTS" duy
  > nhất trong file là *"UPOS/OMG, gốc NRF-ARTS"*, nói về tổ tiên của UPOS chứ
  > không phải Digital Receipt.) Đây là loại sai đắt nhất: nó **trả lời "có" cho
  > câu hỏi "chỗ này đã khảo sát chưa"**, nên người sau đọc xong tưởng bốn chuẩn
  > ấy đã được cân nhắc và bỏ qua.

Liên quan: #1171 (tracking) · plan-052 #1166 (ledger/transport; §3c thứ tự
phụ thuộc) · #1152 (登録番号 = param chuẩn) · #1092 (mẫu immutable revision +
golden parity) · #1154 (nguồn số tiền).
