<?php

namespace App\OpenApi\Webhooks;

use OpenApi\Attributes as OA;

/**
 * #1510 — khối `info` cho tài liệu Webhooks (chiều VÀO).
 *
 * Xem `App\OpenApi\Kds\KdsApiInfo` để biết vì sao khối này không phải thủ tục.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Webhooks (inbound)',
    description: <<<'DESC'
    ## Chiều VÀO từ bên ngoài

    Đây KHÔNG phải API để client của mình gọi. Đây là hợp đồng mình cam kết
    **nhận** từ Stripe / PayPay / nhà cung cấp mail.

    ### Vì sao tài liệu này công khai được

    Các endpoint dưới đây được bảo vệ bằng **xác minh chữ ký**, không bằng việc
    không ai biết đường dẫn. Hình dạng payload thì chính nhà cung cấp đã công bố
    công khai. Giấu trang này đi chỉ làm khó người trực sự cố lúc 2 giờ sáng,
    không làm khó kẻ tấn công.
    DESC,
)]
class WebhooksApiInfo {}
