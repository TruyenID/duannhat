<?php

declare(strict_types=1);

namespace App\Services\Customer\Contracts;

/**
 * #1993 — một khách ở dạng "đủ để gọi tên trên màn hình đòi nợ".
 *
 * `displayName` đã được CustomerEngagement ghép sẵn từ họ + tên. Trả về hai
 * trường rời để người gọi tự ghép nghĩa là mỗi màn hình lại tự quyết định cách
 * gọi tên một con người — POS ghép kiểu này, admin ghép kiểu kia, và không chỗ
 * nào sai đủ để ai đó sửa. `null` nghĩa là **không có tên nào để hiện**, khác
 * với chuỗi rỗng: POS gắn khách bằng SỐ ĐIỆN THOẠI (`findOrCreateByPhone`) nên
 * phần lớn khách nợ không có tên thật, và `phone` mới là thứ nhận diện họ.
 */
final readonly class CustomerDirectoryEntry
{
    public function __construct(
        public string $customerId,
        public ?string $displayName,
        public ?string $phone,
        public ?string $taxCode,
    ) {}
}
