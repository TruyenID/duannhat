<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use DateTimeInterface;

/**
 * #1597 — cổng Pricing công bố cho Ordering: **khuyến mãi nào đang áp cho món
 * này, ở chi nhánh này, lúc này**.
 *
 * Ordering gọi `MenuPromotionService` ở bốn chỗ và nhận về **model** của
 * Pricing. Chiều gọi là đúng (ADR 0001: Ordering tiêu thụ kết quả tính giá),
 * nhưng cái trả về thì không — nên nó vẫn bị đếm là nợ, và mọi trường khác của
 * model đều nằm trong tầm với.
 *
 * Hai method vì hai câu hỏi khác nhau, và **cố ý không gộp**:
 *
 * - `activeFor()` — phân giải theo phạm vi (chi nhánh · sản phẩm · danh mục) tại
 *   một thời điểm. Trả ba trường Ordering dùng.
 * - `snapshotFor()` — ảnh chụp **bất biến** để đóng dấu lên dòng đơn: tên đa ngữ
 *   + phần trăm + kiểu chồng lấn, dạng chuỗi. Nó phải giữ nguyên hình dạng mảng
 *   mà Ordering **đang ghi xuống DB**, nên trả mảng chứ không trả VO — đổi hình
 *   dạng là đổi dữ liệu đã lưu của các đơn cũ.
 */
interface MenuPromotionResolver
{
    /**
     * @param  list<string>  $categoryIds
     */
    public function activeFor(
        string $branchId,
        string $productId,
        array $categoryIds = [],
        ?DateTimeInterface $at = null,
    ): ?ActiveMenuPromotion;

    /**
     * Ảnh chụp bất biến để đóng dấu lên dòng đơn.
     *
     * `name` là map `locale => tên`; `discount_percent` và `stacking_mode` là
     * **chuỗi** — đúng như bản cũ ghi, vì chúng đi thẳng vào cột JSON snapshot.
     *
     * @return array{name: array<string, string>, discount_percent: string, stacking_mode: string}|null
     */
    public function snapshotFor(?string $promotionId): ?array;
}
