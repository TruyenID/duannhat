<?php

declare(strict_types=1);

namespace App\Services\Device\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * #962 — Notifications hỏi PlatformIntegration "những thiết bị nào khớp
 * quy tắc audience này", thay vì `AudienceResolvers\DeviceResolver` tự truy vấn
 * `App\Models\Device`.
 *
 * ## Vì sao cổng này trả về MODEL chứ không phải DTO
 *
 * Mọi cổng khác của epic đều trả primitive/DTO, và luật `PublishedContracts` chỉ
 * được phụ thuộc hai kernel là thứ cưỡng chế điều đó. Ở đây thì KHÔNG THỂ: đầu
 * ra của resolver được nhét vào khoá `notifiable` và đi thẳng vào hệ thống
 * notification của Laravel — nó phải là một instance `Notifiable` thật, không
 * phải một bản sao dữ liệu. Một DTO ở đây sẽ chỉ bị caller dùng để nạp lại đúng
 * model đó, tức tầng gián tiếp không mua được gì.
 *
 * Cổng vẫn hợp lệ vì kiểu trong chữ ký là `Illuminate\...\Model` — lớp cơ sở của
 * framework, không phải model CỦA MỘT MODULE. Ranh giới thật mà nó giữ: quy tắc
 * lọc (branch / loại thiết bị / id) và bảng `devices` ở lại PlatformIntegration;
 * Notifications không còn biết cột nào tên gì.
 */
interface NotifiableDeviceDirectory
{
    /**
     * Thiết bị khớp bộ lọc audience. Bộ lọc rỗng ⇒ mọi thiết bị.
     *
     * @param  array{branch_id?: ?string, device_types?: list<string>, device_ids?: list<string>}  $filter
     * @return Collection<int, Model>
     */
    public function matching(array $filter): Collection;
}
