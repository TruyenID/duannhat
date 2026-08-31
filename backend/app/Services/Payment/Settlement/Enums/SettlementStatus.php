<?php

namespace App\Services\Payment\Settlement\Enums;

/**
 * Plan-050 — settlement row lifecycle.
 *
 * pending_payout → reconciled is the happy path. `orphan` = gateway has the
 * money-event, we have no matching payment (kept forever for audit, S-05).
 * `mismatch` = the row's numbers contradict something we can verify
 * (currency S-17, net assert on import S-15) — never silently corrected.
 */
enum SettlementStatus: string
{
    case PendingPayout = 'pending_payout';
    case Reconciled = 'reconciled';
    case Orphan = 'orphan';
    case Mismatch = 'mismatch';

    /**
     * #2864 — tiền chứng minh được là KHÔNG PHẢI của Tempo.
     *
     * Tài khoản Stripe production dùng CHUNG với trang đặt món WooCommerce
     * riêng của quán, nên Stripe đẩy cả sự kiện của họ sang đây. Đo 2026-08-14:
     * **202 hàng, ¥366.643 gross, ¥13.194 phí**, tăng ~35–50 hàng/ngày kể từ
     * 2026-08-10.
     *
     * Vì sao KHÔNG dùng lại `orphan`: theo định nghĩa ngay trên, `orphan` là
     * **của ta nhưng chưa khớp** — nó được giữ mãi CHÍNH VÌ một ngày nào đó
     * payment có thể tới muộn (offline replay #1092) rồi tự nhận lại dòng đó
     * (S-05/S-19). Tiền của merchant khác thì không bao giờ có payment nào tới
     * nhận. Gộp hai thứ vào một trạng thái làm hỏng cả hai: cảnh báo
     * `orphan_overdue` kêu về tiền không phải của mình, còn dòng thật của mình
     * thì chìm trong đó.
     *
     * Vì sao KHÔNG xoá: đây là bản ghi đối soát của một giai đoạn có thật
     * (2026-08-10 → khi bản vá ingest #2867 lên production). Xoá đi là mất bằng
     * chứng để trả lời "khoản nào là của ai".
     */
    case Foreign = 'foreign';

    /**
     * #2981 — kế toán PHÍ của chính Stripe, không phải tiền của bất kỳ ai bán.
     *
     * Loại thứ ba, và nó không gộp được vào hai loại kia. Đo trên production
     * 2026-08-16, dòng duy nhất thuộc loại này:
     *
     *     txn_1U1OVOCUZcB5vP8By7q0Y9gM   ¥178
     *     type=adjustment  reporting_category=fee  source=NULL
     *     "JCT adjustment for invoice number ZCB5VP8B-2026-07."
     *
     * JCT là 消費税 trên **hoá đơn phí mà Stripe gửi cho merchant**. Không có
     * đơn hàng nào ở bất kỳ đầu nào — không của Tempo, không của trang
     * WooCommerce dùng chung tài khoản.
     *
     * Vì sao KHÔNG dùng `orphan`: `orphan` được giữ mãi vì một payment có thể
     * tới muộn rồi nhận lại dòng đó (S-05/S-19, offline replay #1092). Một dòng
     * điều chỉnh thuế trên hoá đơn phí thì **không bao giờ có payment nào tới
     * nhận** — nó sẽ nằm trong hàng đợi cảnh báo vĩnh viễn. Đó không phải giả
     * thuyết: cảnh báo `settlement.orphan_overdue` đã kêu về đúng dòng này mỗi
     * đêm từ 2026-08-10, và cái cứu nó khỏi bị người ta để ý là một lỗi KHÁC —
     * cảnh báo không tới được ai. Vá lỗi kia (#2893) tức là từ đêm sau nó bắt
     * đầu kêu vào mặt bốn người thật, mỗi đêm, về ¥178 không ai làm gì được.
     *
     * Vì sao KHÔNG dùng `foreign`: `foreign` nghĩa là **doanh thu của merchant
     * khác** trên cùng tài khoản Stripe. Dòng này không phải doanh thu của ai;
     * gán nhãn đó là nói sai về nguồn tiền, và con số `foreign` là thứ đang
     * được dùng để trả lời "khoản nào của ai".
     *
     * Vì sao KHÔNG xoá: nó là một khoản dịch chuyển tiền có thật, ảnh hưởng
     * tổng payout. Sản phẩm ĐÃ RELEASE (ruling #2872) — đây là chứng từ.
     */
    case FeeAdjustment = 'fee_adjustment';
}
