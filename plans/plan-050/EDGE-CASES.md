# Plan 050 — Edge cases (S-01…S-24)

> Nhãn: **[HARD]** chặn/throw · **[CLAMP]** tự về biên ·
> **[DEFINED]** hành vi định nghĩa rõ, có test.

## Import & idempotency

- **S-01 [HARD] Import trùng file**: `file_hash` unique → batch thứ hai
  no-op, exit code nói rõ "already imported". Không bao giờ double row.
- **S-02 [HARD] Import trùng dòng khác file** (report tháng chứa lại dòng
  đã có trong report 早期振込): UNIQUE (provider, external_ref) → dòng trùng
  skip + đếm vào `matched_count`, KHÔNG lỗi cả batch.
- **S-03 [DEFINED] File thiếu cột / format đổi**: importer validate header
  trước; sai → batch `failed`, không partial-import (all-or-nothing per
  file, vì resume nửa file rất khó chứng minh đúng).
- **S-04 [DEFINED] Dòng match payment của CONNECTION KHÁC** (import nhầm
  file của merchant khác): merchant_payment_id match nhưng connection lệch →
  row `orphan` + cảnh báo to, không gắn bừa.
- **S-05 [DEFINED] Orphan row** (gateway có, mình không có): giữ row
  `orphan` vĩnh viễn (audit), alert; nếu sau này payment sync muộn tới
  (offline replay #1092) → lệnh reconcile re-match orphan tự động.

## Refund / dispute lệch kỳ

- **S-06 [DEFINED] Refund nằm payout khác charge gốc**: 2 settlement rows
  độc lập, 2 payout khác nhau — model per-row xử lý tự nhiên; report theo
  kỳ hiển thị net âm là bình thường, KHÔNG cố "kéo về kỳ gốc" (nhất quán
  triết lý #1123 cross-period: tháng bán bất biến).
- **S-07 [DEFINED] Stripe không hoàn phí gốc khi refund**: row refund có
  gross âm, fee = 0 (theo statement). KHÔNG tự suy "hoàn phí tỉ lệ".
- **S-08 [DEFINED] Dispute rút tiền + phí**: 2 rows (`dispute_withdrawal`
  gross âm, `dispute_fee` fee dương); WON → `dispute_reversal` row mới —
  KHÔNG sửa row cũ (append-only, khớp #1123 reinstatement).
- **S-09 [DEFINED] Refund partial nhiều lần**: mỗi lần một row, Σ không vượt
  charge gốc — check mềm (warning) vì gateway là sự thật, mình chỉ verify.

## Payout bất thường

- **S-10 [DEFINED] Payout `failed`** (tài khoản bank lỗi): status failed,
  settlements gắn vào QUAY về `pending_payout` (gateway sẽ gộp vào payout
  sau) — không mất dấu.
- **S-11 [DEFINED] Payout hold / balance âm** (refund nhiều hơn doanh thu kỳ):
  payout net âm hợp lệ (Stripe debit) — bigint signed, không abs().
- **S-12 [HARD] Σ settlements ≠ payout net**: payout `mismatch` + alert,
  KHÔNG tự "cân" bằng row manual. Row manual chỉ được tạo tay có
  `imported_by_id` + lý do metadata.
- **S-13 [DEFINED] Payout chứa txn loại mình chưa map** (Stripe topup,
  adjustment lạ): row `kind=manual, source=api` + metadata raw type, alert
  soft — payout vẫn reconcile được, kind mới thêm sau.
- **S-14 [DEFINED] 早期振込 PayPay** (nhiều payout/tháng): chỉ là nhiều batch
  + nhiều payout rows — model không giả định chu kỳ cố định.

## Tiền & thuế

- **S-15 [HARD] Assert net**: `net = gross − fee − fee_tax` sai trên dữ liệu
  import → batch failed dòng đó thành orphan-mismatch, không lưu số mâu
  thuẫn.
- **S-16 [DEFINED] fee_tax per provider**: thẻ JP 非課税 (0), PayPay 10% —
  lấy từ cột report/API, KHÔNG hardcode; test có fixture cả hai.
- **S-17 [DEFINED] Currency lệch** (payment JPY, settlement báo khác): row
  mismatch + alert — không convert.
- **S-18 [DEFINED] Estimate drift**: |estimate − thật| > ngưỡng % →
  notification nhắc update fee_estimate catalog (hợp đồng rate đổi).

## Vòng đời & đồng bộ

- **S-19 [DEFINED] Payment offline replay muộn** (#1092): settlement row đến
  TRƯỚC payment row → orphan → reconcile re-match sau (S-05). Không chặn
  import vì thiếu payment.
- **S-20 [DEFINED] Connection revoked giữa kỳ**: settlement/payout của
  connection revoked vẫn import bình thường (tiền cũ vẫn về) — revoke chỉ
  chặn giao dịch MỚI.
- **S-21 [DEFINED] Multi-connection cùng provider** (HQ + franchise):
  external_ref unique per provider vẫn đúng (Stripe balance txn id global
  unique); match luôn qua connection_id của payment, file import khai
  connection tường minh (S-04).
- **S-22 [DEFINED] Ngày settlement vs business time**: `provider_settled_at`
  theo lịch gateway (JST) — report settlement KHÔNG dùng BusinessClock branch
  (#1091 §5 DESIGN); test freeze clock 3 timezone cho phần aging (aging tính
  bằng ngày trôi qua, không phụ thuộc zone).
- **S-23 [DEFINED] Re-run reconcile idempotent**: chạy 2 lần liên tiếp không
  đổi trạng thái nào (double-invoke test).
- **S-24 [HARD] Xoá/sửa settlement đã reconciled**: cấm (settled-guard mẫu
  append-only) — sai thì tạo row manual đảo.
