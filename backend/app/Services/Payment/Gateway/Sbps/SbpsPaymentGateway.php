<?php

namespace App\Services\Payment\Gateway\Sbps;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentGatewayProvider;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;

/**
 * #1796 — SB Payment Service: ĐĂNG KÝ ĐƯỢC, nhưng TỪ CHỐI mọi thao tác.
 *
 * ## Đây là một chỗ trống CÓ TÊN, không phải một adapter dở dang
 *
 * `plans/plan-047/CAPABILITIES.md` (mục 77) ghi thẳng: SBPS *"disabled until
 * exact merchant IF specification and test evidence are attached"*. Chưa có
 * hợp đồng + IF spec thì viết thân adapter là **đoán định dạng đường truyền của
 * một bên thứ ba** — đúng cái lỗi mà #1155 (file 精算 của một provider khác) đang trả giá, và
 * ở đây hậu quả nặng hơn vì nó nằm trên đường tiền.
 *
 * Nên lớp này cố ý KHÔNG có thân. Nó tồn tại để đổi trạng thái từ *"chọn SBPS
 * thì hệ thống im lặng không biết provider đó"* sang *"chọn SBPS thì bị từ chối
 * ngay, kèm lý do và thứ còn thiếu"*. Khác biệt đó có giá trị vận hành: một
 * người dựng kết nối SBPS trên admin sẽ biết ngay tại sao, thay vì phát hiện
 * lúc một khách đang đứng chờ ở quầy.
 *
 * ## Vì sao TỪ CHỐI ngay ở `capabilities()`
 *
 * Đó là chỗ orchestrator hỏi trước khi làm bất cứ điều gì. Trả về một
 * `CapabilitySet` "tạm" ở đây sẽ để lượt thanh toán đi tiếp vài bước rồi mới
 * chết — và tệ hơn, sẽ là một lời khai rằng chúng ta biết SBPS hỗ trợ những gì,
 * trong khi câu đó chỉ đúng khi có IF spec trong tay.
 *
 * ## Mốc 2026-09-30 — thứ phải giữ trong đầu người viết tiếp
 *
 * SBPS kết thúc partial-sale và partial-refund hiện hành vào 2026-09-30, thay
 * bằng cơ chế *amount change*. `CAPABILITIES.md` mục 87–88 đã tách sẵn hai hàng
 * trước/sau mốc đó, và `CapabilitySet` đã có `effectiveFrom`/`effectiveTo` +
 * `appliesAt()` để mô hình hoá — nghĩa là khung KHÔNG thiếu gì, chỉ thiếu dữ
 * liệu hợp đồng.
 *
 * Mốc ấy trong repo là **giả định thận trọng**, không phải giờ tắt do SBPS xác
 * nhận. Có bằng chứng hợp đồng thì thay bằng mốc chính xác trước khi viết thân.
 *
 * ## Việc còn lại khi hợp đồng về
 *
 * Thay từng `refuse()` bằng lời gọi thật, dựng hai `CapabilitySet` theo mục
 * 87–88, và chạy lại phép đo trong `docs/explanation/payment-gateway-architecture-proof.md`
 * — nó phải vẫn ra **0** điều kiện rẽ theo tên provider trong lõi.
 */
final class SbpsPaymentGateway implements PaymentGatewayContract
{
    /**
     * Thứ đang thiếu, viết ra để thông điệp lỗi trả lời được câu "vậy cần gì".
     *
     * @var list<string>
     */
    public const MISSING_ARTIFACTS = [
        'hợp đồng SBPS',
        'IF specification (merchant interface)',
        'credentials môi trường test',
        'mốc chính xác thay cho giả định 2026-09-30 (kết thúc partial sale/refund)',
    ];

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        // `GatewayConnectionData` không mang correlation id — nó là dữ liệu
        // kết nối, không phải một lượt gọi. Dùng nhãn cố định.
        $this->refuse('sbps-capabilities');
    }

    /**
     * #2938 — NGOẠI LỆ DUY NHẤT với luật "tám cửa đều từ chối", và có lý do đo
     * được.
     *
     * `identifyConnection` chạy TRƯỚC khi xác minh chữ ký, trên một payload
     * chưa tin được, ở một endpoint công khai. Ném `UnsupportedPaymentGatewayProvider`
     * ở đây sẽ đi qua nhánh `\Throwable` của `PaymentProviderWebhookController`
     * và biến mọi rác gửi tới `POST /webhooks/payment/sbps` thành **500** —
     * tức tự khai "lỗi của ta" cho traffic giả mạo, và làm dashboard không phân
     * biệt nổi sự cố thật.
     *
     * `null` mới là câu trả lời đúng: "adapter này không nhận ra payload" ⇒
     * resolver từ chối fail-closed ⇒ 400. Không có connection SBPS nào tồn tại
     * (chưa hợp đồng, chưa IF spec) nên đây cũng là sự thật, không phải một
     * nhánh tạm.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        return null;
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        $this->refuse($command->request->correlationId);
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        $this->refuse($command->correlationId);
    }

    /**
     * Một chỗ từ chối duy nhất — tám method không được phép lệch nhau về lý do.
     */
    private function refuse(string $correlationId): never
    {
        // Danh sách provider dùng được đọc từ CẤU HÌNH, không hard-code: hai
        // bản sao của cùng một danh sách sẽ lệch nhau, và bản lệch nằm trong
        // thông điệp lỗi — nơi không ai kiểm.
        $configured = array_values(array_filter(array_map(
            static fn (string $code): ?PaymentGatewayProviderCodeEnum => PaymentGatewayProviderCodeEnum::tryFrom($code),
            array_keys((array) config('payments.gateway_drivers', [])),
        )));

        throw new UnsupportedPaymentGatewayProvider(
            PaymentGatewayProviderCodeEnum::Sbps,
            $configured,
            $correlationId,
        );
    }
}
