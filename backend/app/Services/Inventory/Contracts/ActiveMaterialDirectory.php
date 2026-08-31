<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * #962 — Catalog hỏi Inventory "trong đám id này, nguyên liệu nào còn
 * hoạt động".
 *
 * Dùng bởi `ProductSkuService::checkUsage`: xoá một biến thể thì admin phải thấy
 * NGUYÊN LIỆU CHA nào đang dùng nó (đi qua `Recipe.ingredients[].variant_id`).
 * Công thức là của Catalog; `materials` là của Inventory.
 *
 * ## Vì sao trả model chứ không phải DTO
 *
 * Kết quả của `checkUsage` được controller serialize THẲNG ra JSON
 * (`'data' => $this->queries->skuUsage($sku)`), và admin-web đọc payload đó. Một
 * DTO ở đây sẽ đổi hình dạng response — thay đổi API cho một việc thuần nội bộ.
 * Nên cổng trả về chính collection cũ, và ranh giới nó giữ là: truy vấn
 * `materials` (kể cả điều kiện `is_active`) ở lại Inventory.
 *
 * Kiểu trong chữ ký là `Illuminate\...\Model` — lớp cơ sở framework, không phải
 * model của module — nên hợp đồng vẫn thoả luật "chỉ phụ thuộc hai kernel".
 */
interface ActiveMaterialDirectory
{
    /**
     * @param  list<string>  $materialIds
     * @return Collection<int, Model>
     */
    public function activeByIds(array $materialIds): Collection;
}
