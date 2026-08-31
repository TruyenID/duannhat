<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * Hai cột của `devices` mà việc xác minh đơn offline cần: đơn được phát lại có
 * đúng chi nhánh và đúng tổ chức của thiết bị đã ký hay không (#962, #1092).
 */
final class SigningDeviceIdentity
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $branchId,
        public readonly ?string $organizationId,
    ) {}
}
