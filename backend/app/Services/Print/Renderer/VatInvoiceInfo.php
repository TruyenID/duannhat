<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Carbon\CarbonImmutable;
use Tests\Feature\Print\PrintPayloadContractParityTest;

/**
 * plan-053 T5.1d (#1909) — hoá đơn GTGT / 適格簡易請求書 như tầng in nhìn thấy.
 *
 * Đối ứng của `VatInvoiceInfo` bên Go, **19 trường**, ghim từng trường bởi
 * {@see PrintPayloadContractParityTest} qua khoá
 * `payload_fields` của `print_contract_golden.json`.
 *
 * ── Ba chỗ dễ port sai, ghi ở đây vì không suy được từ tên trường ─────────
 *
 * **`currencyPrefix` thắng cấu hình đang sống của quán.** Bên Go `vatCurrency()`
 * ưu tiên đơn vị tiền đã đóng băng lên hoá đơn lúc phát hành. In lại hoá đơn
 * tháng trước mà lấy tiền tệ hiện tại là **âm thầm đổi mệnh giá một chứng từ đã
 * nộp thuế**. Rỗng mới rơi về cấu hình.
 *
 * **`sellerRegistrationNumber` là điều kiện của NHÃN chứng từ, không phải một
 * dòng chữ.** #1734: trên chứng từ Nhật, nhãn 適格簡易請求書 là một TUYÊN BỐ
 * PHÁP LÝ — nó chỉ đúng khi có 登録番号. Emitter ghi đè cả nhãn do brand soạn ở
 * đúng ca đó. Brand không được phép dán nhãn ấy lên một tờ giấy không đủ điều
 * kiện.
 *
 * **`locale` nằm TRÊN payload, và nó KHÔNG phải trục quyết định chứng từ Nhật.**
 * Cờ `japaneseDoc` đến từ KIND (#1493), không từ locale — một quán Việt để giao
 * diện tiếng Nhật vẫn không được in ra chứng từ Nhật. Trường này chỉ để emitter
 * chọn chữ, không để chọn loại chứng từ.
 */
final class VatInvoiceInfo
{
    /**
     * @param  list<VatInvoiceLine>  $items
     * @param  list<VatInvoiceTaxLine>  $taxBreakdown
     */
    public function __construct(
        public readonly string $invoiceNo = '',
        public readonly ?CarbonImmutable $issuedAt = null,
        public readonly string $taxCode = '',
        public readonly string $companyName = '',
        public readonly string $buyerName = '',
        public readonly string $billingAddress = '',
        public readonly int $subtotal = 0,
        public readonly int $taxAmount = 0,
        public readonly int $total = 0,
        public readonly int $taxRatePercent = 0,
        /** Đơn vị tiền ĐÃ ĐÓNG BĂNG lúc phát hành. Rỗng ⇒ rơi về cấu hình. */
        public readonly string $currencyPrefix = '',
        public readonly array $items = [],
        public readonly string $paymentMethod = '',
        public readonly string $status = '',
        public readonly int $reprintNumber = 0,
        public readonly array $taxBreakdown = [],
        /** Thiếu số này ⇒ KHÔNG được dán nhãn 適格簡易請求書 (#1734). */
        public readonly string $sellerRegistrationNumber = '',
        /** Chọn CHỮ, không chọn loại chứng từ — xem doc class. */
        public readonly string $locale = '',
        public readonly bool $isAmountSplit = false,
    ) {}
}
