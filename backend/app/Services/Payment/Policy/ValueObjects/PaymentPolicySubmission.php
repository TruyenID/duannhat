<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Models\Branch;
use App\Omnify\Enums\PaymentChannelEnum;
use InvalidArgumentException;

/** Client-supplied payment policy identity for a new tender or prepare. */
final readonly class PaymentPolicySubmission
{
    public function __construct(
        public Branch $branch,
        public PaymentChannelEnum $channel,
        public string $gatewayOptionId,
        public int $policyRevision,
        public ?string $gatewayConnectionId = null,
        public ?string $deviceId = null,
    ) {}

    /**
     * #1643 — nhận `$branchId`, không nhận đơn. Method này chỉ đọc
     * `$order->branch_id`; chỗ gọi duy nhất đã cầm sẵn đơn nên truyền vô hướng
     * KHÔNG tốn thêm truy vấn nào — thu hẹp thuần, không cần cổng.
     */
    public static function fromPaymentData(array $data, string $branchId): ?self
    {
        $optionId = $data['gateway_option_id'] ?? null;
        $revision = $data['policy_revision'] ?? null;

        if (! is_string($optionId) || $optionId === '') {
            return null;
        }

        if (! is_numeric($revision)) {
            throw new InvalidArgumentException('policy_revision is required when gateway_option_id is supplied.');
        }

        $branch = Branch::query()->find($branchId);
        if ($branch === null) {
            throw new InvalidArgumentException('Order branch was not found for policy validation.');
        }

        $transport = is_string($data['orchestrator_transport'] ?? null)
            ? $data['orchestrator_transport']
            : null;

        return new self(
            $branch,
            self::channelFromTransport($transport),
            $optionId,
            (int) $revision,
            is_string($data['gateway_connection_id'] ?? null) ? $data['gateway_connection_id'] : null,
            is_string($data['device_id'] ?? null) ? $data['device_id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function channelFromTransport(?string $transport): PaymentChannelEnum
    {
        return match ($transport) {
            'kiosk' => PaymentChannelEnum::Kiosk,
            'self_regi' => PaymentChannelEnum::SelfRegi,
            'workstation' => PaymentChannelEnum::Workstation,
            'customer_web' => PaymentChannelEnum::CustomerWeb,
            default => PaymentChannelEnum::Pos,
        };
    }
}
