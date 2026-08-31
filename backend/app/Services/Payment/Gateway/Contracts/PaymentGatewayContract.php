<?php

namespace App\Services\Payment\Gateway\Contracts;

use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;

interface PaymentGatewayContract
{
    public function capabilities(GatewayConnectionData $connection): CapabilitySet;

    /**
     * #2938 — sự kiện webhook này thuộc connection nào?
     *
     * ## Phép DUY NHẤT của hợp đồng này KHÔNG nhận `GatewayConnectionData`
     *
     * Và đó là cả lý do nó tồn tại. Phải biết connection TRƯỚC mới biết dùng
     * webhook secret nào để xác minh chữ ký, nên ở thời điểm này chưa có
     * instance nào gắn với connection để hỏi — phép này bắt buộc KHÔNG TRẠNG
     * THÁI. Nó chỉ được đọc payload (dữ liệu CHƯA xác minh) và trả về **cách
     * tra**, không trả về kết luận và tuyệt đối không chạm DB.
     *
     * ## Phép DUY NHẤT được trả `null` thay vì ném
     *
     * `null` = "adapter này không nhận ra payload". Đó là câu trả lời hợp lệ,
     * không phải lỗi: chỗ gọi (`WebhookConnectionResolver`)
     * coi nó là TỪ CHỐI (fail-closed) và trả 400. Một adapter chưa có hợp đồng
     * nhà cung cấp (SBPS, #1796) trả `null` là đúng — nó không được "từ chối"
     * bằng ngoại lệ ở đây, vì như thế biến một webhook rác thành 5xx.
     *
     * @param  array<string, mixed>  $payload  thân webhook đã json_decode, CHƯA xác minh
     */
    public function identifyConnection(array $payload): ?ConnectionLocator;

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult;

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult;

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult;

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult;

    public function refund(RefundPaymentCommand $command): GatewayRefundResult;

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult;

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent;
}
