<?php

declare(strict_types=1);

namespace App\Services\Device\Contracts;

/**
 * #1666 (#962) — cổng PlatformIntegration công bố cho Payments: **thiết bị này là ai**.
 *
 * Payments chạm `App\Models\Device` ở đúng hai chỗ, và cả hai đều KHÔNG cần model:
 *
 *   - màn "ngắt kết nối cổng thanh toán" liệt kê tên thiết bị bị ảnh hưởng;
 *   - kiểm một device token có được xem cấu hình thanh toán của chi nhánh không.
 *
 * Cắt theo **cái Payments thật sự đọc**, không mirror model: id + tên, và một vị
 * ngữ "còn sống trong đúng org/branch này". Vòng đời thiết bị — pair, cấp token,
 * thu hồi — vẫn nằm nguyên ở PlatformIntegration, và `config/modules.php` đã ghi
 * rõ Device CỐ Ý không phải hạ tầng dùng chung.
 *
 * Chữ ký toàn primitive không phải cho đẹp: từ #1598 `PublishedContracts` chỉ được
 * phụ thuộc hai kernel, nên một cổng mang `Device` trong chữ ký sẽ ĐỎ ngay tại
 * deptrac và không khai vào danh sách công bố được.
 */
interface DeviceDirectory
{
    /**
     * Id + tên hiển thị của các thiết bị, theo ĐÚNG thứ tự id được hỏi.
     *
     * Id không phân giải được (thiết bị đã xoá mềm, hoặc id lạ) bị **bỏ khỏi kết
     * quả** chứ không trả về một phần tử rỗng — giữ nguyên hành vi `->filter()`
     * của chỗ gọi cũ, vốn dùng số phần tử để đếm "bao nhiêu thiết bị bị ảnh hưởng".
     *
     * @param  list<string>  $deviceIds
     * @return list<array{id: string, name: string|null}>
     */
    public function identitiesByIds(array $deviceIds): array;

    /**
     * Thiết bị này có đang HOẠT ĐỘNG và thuộc đúng org + chi nhánh này không.
     *
     * Ba điều kiện AND với nhau, và `false` khi không tìm thấy thiết bị — nghĩa
     * là fail-closed: một id lạ không bao giờ mở được cấu hình thanh toán.
     */
    public function isActiveInBranch(string $deviceId, string $organizationId, string $branchId): bool;
}
