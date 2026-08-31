<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use Carbon\CarbonImmutable;

/**
 * Cổng Ordering → PlatformIntegration cho DANH TÍNH ký offline (#962, #1092).
 *
 * `OfflineOrderEvidenceVerifier` phải biết ba điều trước khi tin một đồng nào:
 * khoá ký có thật không, nó thuộc thiết bị nào, và thiết bị đó thuộc chi nhánh /
 * tổ chức nào. Cả ba đều là bảng của PlatformIntegration
 * (`device_signing_keys`, `devices`).
 *
 * **KHÔNG có gì ở đây chạm vào bề mặt ký.** Byte đem ký do
 * `App\Services\Order\Offline\OfflineOrderSigningMessage` dựng và được chốt bởi
 * golden fixture chung với workstation (Go); cổng này chỉ TRA CỨU. Khoá công
 * khai được trả về nguyên văn để verifier tự kiểm chữ ký như cũ.
 *
 * `wasValidAt` không nằm ở value object mà nằm ở đây, và được TRẢ SẴN theo đúng
 * thời điểm ký: luật hiệu lực (thu hồi = hỏng cho MỌI thời điểm, kể cả quá khứ;
 * cửa sổ ân hạn tính theo hạn của chính khoá cũ) là luật của
 * PlatformIntegration. Chép nó sang một VO ở tầng hợp đồng là tạo bản thứ hai
 * để trôi.
 */
interface OfflineSigningIdentity
{
    /**
     * Khoá ký theo id, kèm kết luận "khoá này có hiệu lực tại $signedAt không".
     *
     * @return SigningKeyEvidence|null null = id không tồn tại
     */
    public function findSigningKey(string $keyId, CarbonImmutable $signedAt): ?SigningKeyEvidence;

    /** Thiết bị theo id. Null = không tồn tại. */
    public function findSigningDevice(string $deviceId): ?SigningDeviceIdentity;
}
