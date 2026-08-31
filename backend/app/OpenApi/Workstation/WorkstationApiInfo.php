<?php

namespace App\OpenApi\Workstation;

use OpenApi\Attributes as OA;

/**
 * #1499 — khối `info` cho tài liệu Workstation (phía CLOUD).
 *
 * Trước bản này không bucket nào quét `Api/V1/Workstation`, nên 124 attribute
 * `#[OA\...]` ở đó chưa từng xuất hiện trong tài liệu công bố nào — chúng là
 * trang trí, trong khi `tal docs-check` vẫn nhắc "chạm controller thì regen
 * swagger" mỗi lần ai sửa chúng. Lời nhắc đó không bao giờ đúng được cho
 * namespace này.
 *
 * KHÔNG nhầm với Swagger của chính app workstation ở `localhost:8080/docs`: cái
 * đó mô tả API LAN mà bản Go phục vụ. Cái này mô tả thứ Cloud cung cấp CHO nó.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Workstation (Cloud side)',
    description: <<<'DESC'
    ## TempoFast — Workstation ↔ Cloud

    Hợp đồng giữa Cloud và máy trạm đặt tại quán, dưới tiền tố
    `/api/v1/workstation/...`. Nguồn chân lý của danh sách route là
    `backend/routes/api/workstation.php`.

    Mọi endpoint xác thực bằng **device token** (cấp qua `POST /api/v1/devices/pair`
    — endpoint CHUNG, không có bản riêng cho workstation).

    ### Nhóm
    | Nhóm | Nội dung |
    |---|---|
    | Catalog pull-DOWN | `menu`, `menu/handy`, `menu-catalog`, `branch`, `lots`, `sync-manifest` |
    | Replica pull-DOWN | `payment-methods`, `customers`, `printers`, `staff`, `coupons`… |
    | Orders sync-UP | `orders`, `orders/replay-offline` (#1097 có chữ ký), vòng đời đơn |
    | Payments | `payments`, xác nhận/huỷ, `payments/{payment}/attribution` |
    | Till / ca thu ngân | `till`, phiên, sự kiện tiền mặt, đóng ca |

    `sync/pull`, `sync/push`, `menu/changes`, `heartbeat`, `config` **chưa bao giờ
    được cài đặt** — workstation pull trực tiếp và tự theo dõi kết nối (#1323).
    DESC,
    contact: new OA\Contact(name: 'TempoFast'),
)]
#[OA\Server(
    url: 'http://localhost:5400',
    description: 'Local Development',
)]
#[OA\SecurityScheme(
    securityScheme: 'deviceToken',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Token',
    description: 'Device token cấp bởi POST /api/v1/devices/pair',
)]
/**
 * Khai LẶP schema `Customer` — cùng khuôn `HQApiInfo`/`ShopApiInfo` đã dùng.
 *
 * `Api/V1/Workstation/CustomerController` tham chiếu `#/components/schemas/Customer`,
 * và mỗi bucket l5-swagger là một tài liệu ĐỘC LẬP: nó chỉ thấy những gì chính
 * nó quét. Không khai ở đây thì generate chết với *"$ref … not found"* — đúng
 * lỗi lộ ra ngay lần đầu bucket này được dựng, và là bằng chứng rằng 124
 * attribute OA trong namespace đó **chưa từng được kiểm** bởi bất cứ lượt
 * generate nào.
 */
#[OA\Schema(
    schema: 'Customer',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'organization_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class WorkstationApiInfo {}
