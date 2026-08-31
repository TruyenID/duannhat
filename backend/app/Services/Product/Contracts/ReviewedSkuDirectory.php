<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #962 — cổng Catalog công bố: **biến thể đã bán trông như thế nào trên thẻ
 * đánh giá**.
 *
 * Bản cũ đi từ dòng đơn sang Catalog bằng quan hệ Eloquent
 * (`productSku.product.galleryFirst`), nên module đọc đơn phải import
 * model `Product`. Cổng cắt theo QUYỀN SỞ HỮU: Ordering trả id biến thể
 * (`App\Services\Order\Contracts\ReviewableOrderLines` — viết trong backtick vì
 * pint biến `{@see \Foo}` thành `use` THẬT), Catalog dịch id đó ra tên/ảnh/tên
 * biến thể, còn CustomerEngagement ghép hai bên lại.
 *
 * Cái giá là **hai lượt truy vấn thay vì một**, và nó có trần: một đơn của một
 * bàn — vài chục dòng món, không phải "toàn bộ dòng món". Đừng chép kết luận
 * này sang một truy vấn không có trần.
 */
interface ReviewedSkuDirectory
{
    /**
     * Tra nhiều biến thể theo id, khoá theo chính id đó.
     *
     * Id không tra được sẽ VẮNG MẶT trong mảng trả về (không có mục `null`) —
     * phía gọi phân biệt "không có SKU" với "có SKU nhưng sản phẩm đã xoá" bằng
     * {@see ReviewedSku::$product}.
     *
     * @param  list<string>  $skuIds
     * @return array<string, ReviewedSku>
     */
    public function byIds(array $skuIds): array;
}
