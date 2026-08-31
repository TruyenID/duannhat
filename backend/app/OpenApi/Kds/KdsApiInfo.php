<?php

namespace App\OpenApi\Kds;

use OpenApi\Attributes as OA;

/**
 * #1510 — khối `info` cho tài liệu KDS (phía CLOUD).
 *
 * Trước bản này không bucket nào quét `Api/V1/Kds`, nên 8 operation ở đó chưa
 * từng xuất hiện trong tài liệu công bố nào.
 *
 * Khối Info này KHÔNG phải thủ tục: thiếu nó thì `l5-swagger:generate` chết bằng
 * "Required Info not found" và **không sinh file nào cả** — trong khi
 * `SwaggerCoversEveryApiNamespaceTest` vẫn xanh, vì nó đọc CẤU HÌNH chứ không
 * đọc kết quả sinh. Bản đầu của #1510 đã đi qua đúng cái khe đó: test xanh,
 * `kds-api-docs.json` không tồn tại.
 *
 * ## ĐỪNG viết at-OA trong docblock này
 *
 * swagger-php đọc CẢ annotation kiểu doc-comment, nên một câu văn giải thích có
 * chứa chuỗi at-OA-Info sẽ được đăng ký thành một Info THẬT — file này khi đó
 * khai hai Info và generator từ chối với "Only one Info allowed … multiple found
 * in: Using <file> line N / Skipped <file> line N", cùng một file, cùng một dòng.
 * Thông điệp đó hướng người đọc đi tìm file thứ hai không tồn tại; tôi mất bốn
 * lượt bisect vì nó. Muốn nhắc tới tên attribute thì viết không kèm dấu at.
 *
 * KHÔNG nhầm với PWA bếp `godx-kds/`: cái này mô tả thứ Cloud cung cấp CHO nó.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — KDS (Cloud side)',
    description: <<<'DESC'
    ## TempoFast — KDS ↔ Cloud

    Hợp đồng giữa Cloud và màn hình bếp (`godx-kds`), dưới tiền tố
    `/api/v1/kds/...`.

    Xác thực bằng **device token** cấp qua `POST /api/v1/devices/pair` — endpoint
    CHUNG cho mọi thiết bị, nên nó được ghi cả ở đây lẫn ở tài liệu Workstation.

    ### Lưu ý vận hành
    KDS gọi Cloud qua `resolveBaseUrl()`: **ưu tiên workstation trên LAN**, Cloud
    chỉ là đường lui. Nên tài liệu này mô tả đường lui và nguồn chân lý, không
    phải đường đi thường ngày trong một quán có workstation.

    Bump món **không có hàng đợi offline** — một lượt bump thất bại sẽ lăn ngược
    cập nhật lạc quan và báo lỗi cho đầu bếp. Đó là thiết kế cố ý: một lượt bump
    đồng bộ muộn một giờ sẽ dời phiếu sau khi món đã nguội.
    DESC,
)]
class KdsApiInfo {}
