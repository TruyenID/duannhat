<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\ProviderEvent\StripePlatformAccount;
use App\Services\Payment\Settlement\SettlementAttributionMigrator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * #2969 — bản sửa #2893 có một TIỀN ĐỀ ÂM THẦM. Rào này nói nó ra.
 *
 * # Tiền đề
 *
 * #2893 chuyển quy thuộc settlement Stripe từ một connection tổng hợp sang
 * connection THẬT, để tiền hiện ra với chủ sở hữu. Nhưng phép chọn ấy dựa vào
 * {@see StripePlatformAccount::accountId()} — tức biến `STRIPE_ACCOUNT_ID`.
 *
 * Không đặt biến đó thì `WebhookConnectionResolver` rơi về lưới cuối là hàng
 * tổng hợp, và tiền tiếp tục hạ cánh xuống đúng chỗ #2893 sinh ra để dọn.
 * Chính test của #2893 nói ra điều này:
 *
 *     CHIỀU NGƯỢC LẠI: with STRIPE_ACCOUNT_ID unset the money lands on the
 *     retired connection and the owner sees NOTHING
 *
 * # Vì sao phải là RÀO chứ không phải một dòng nhắc
 *
 * Thiếu biến thì **không gì đỏ**: webhook vẫn 200, settlement vẫn được ghi,
 * bảng HQ vẫn hiện 0 hàng. Người vận hành không phân biệt được *"đã sửa và
 * không có tiền"* với *"chưa cấu hình nên không thấy tiền"* — hai câu đó nhìn
 * y hệt nhau trên màn hình.
 *
 * Cùng lớp lỗi với `UPLOADS_DISK` rỗng (#2184), và cùng khuôn xử lý: một bước
 * `deploy:verify-*` chỉ ĐỌC, chạy mỗi lượt deploy.
 *
 * # Rào biết IM
 *
 * Chỉ kêu khi CẢ HAI cùng đúng: connection tổng hợp còn tồn tại **và** biến
 * rỗng — tức đúng trạng thái tiền đang đi sai *ngay lúc này*.
 *
 * Sau khi ops chạy `payments:migrate-stripe-attribution --apply`, connection ấy
 * được cho nghỉ và rào tự câm **vĩnh viễn**, không cần ai nhớ gỡ. Một rào phải
 * tự hết vai khi vấn đề hết, nếu không nó thành tiếng ồn rồi bị tắt — và lúc bị
 * tắt thì nó cũng thôi canh những thứ khác.
 *
 * Ngược lại, KHÔNG kêu khi biến đã đặt mà hàng cũ chưa dọn: đó là việc ops làm
 * một lần, có `--dry-run` để xem trước, và chặn deploy vì nó là phạt nhầm
 * người — hàng mới đã đi đúng chỗ rồi.
 */
final class VerifyStripeAttributionCommand extends Command
{
    protected $signature = 'deploy:verify-stripe-attribution';

    protected $description = 'Assert STRIPE_ACCOUNT_ID is set while the synthetic Stripe connection still holds money (#2893/#2969)';

    public function handle(): int
    {
        $accountId = StripePlatformAccount::accountId();

        // Hỏi DB SAU khi hỏi config: nếu biến đã đặt thì không cần biết trạng
        // thái connection, và một bước deploy không nên tốn truy vấn thừa.
        // Chỉ so `null`: `accountId()` đã chuẩn hoá — trim, rồi khớp
        // `^acct_[A-Za-z0-9_]+$`, sai dạng thì trả `null`. Nên chuỗi rỗng LẪN
        // giá trị sai dạng (dán nhầm `sk_live_…` chẳng hạn) đều tới đây dưới
        // dạng null. Thêm một vế `!== ''` ở đây là mã chết — thử ngược cho thấy
        // gỡ nó đi không bài nào đỏ, tức nó chưa bao giờ canh gì.
        if ($accountId !== null) {
            $this->info(sprintf('STRIPE_ACCOUNT_ID = [%s] — quy thuộc webhook Stripe có đích thật.', $accountId));

            return self::SUCCESS;
        }

        // Vị từ là CÒN BẬT, không phải CÒN TỒN TẠI.
        //
        // `--apply` cho hàng tổng hợp nghỉ bằng `is_active=false` và **không
        // xoá** — nó là chủ sở hữu lịch sử của các bản ghi tiền, và
        // `payment_settlements.connection_id` còn khoá ngoại vào nó. Bản đầu
        // của rào này hỏi `exists()`, tức nó sẽ kêu VĨNH VIỄN kể cả sau khi ops
        // đã dọn xong — một rào không bao giờ hết vai là một rào sắp bị tắt.
        //
        // Dùng hằng của migrator chứ không của lớp fallback: cùng một giá trị,
        // nhưng tên lớp kia mang định danh mà `định danh bị cấm ở rào #2188` cấm
        // trong mã mới (#2188), và danh sách miễn trừ ở đó chỉ được TEO.
        $stillLive = PaymentGatewayConnection::query()
            ->whereKey(SettlementAttributionMigrator::RETIRED_CONNECTION_ID)
            ->where('is_active', true)
            ->exists();

        if (! $stillLive) {
            $this->info('Hàng tổng hợp đã ngưng dùng — không có gì để canh.');

            return self::SUCCESS;
        }

        throw new RuntimeException(
            'STRIPE_ACCOUNT_ID chưa khai (hoặc sai dạng acct_…) TRONG KHI hàng tổng hợp Stripe vẫn đang BẬT. '
            .'Webhook Stripe sẽ rơi về lưới cuối và ghi settlement vào connection đó — '
            .'tiền không hiện ra với chủ sở hữu, và KHÔNG có gì đỏ (#2893). '
            .'Lệnh dọn `payments:migrate-stripe-attribution --apply` cũng từ chối chạy khi thiếu biến này. '
            .'Đặt STRIPE_ACCOUNT_ID trong .env của production rồi deploy lại.'
        );
    }
}
