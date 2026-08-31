<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\IssueCustomerAccessTokenCommand;
use App\Services\Customer\Commands\LinkCustomerScopeCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Commands\ReviseTenantCustomerProfileCommand;
use App\Services\Customer\Commands\RevokeCustomerAccessTokenCommand;
use App\Services\Customer\Commands\UnlinkCustomerScopeCommand;
use App\Services\Customer\Commands\VerifyCustomerEmailCommand;
use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Enums\CustomerAuthorityOperation;
use App\Services\Customer\Enums\CustomerLifecycleAction;
use App\Services\Customer\ValueObjects\CustomerMergePlan;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Cổng XÁC MINH THẨM QUYỀN của Customer (#1550).
 *
 * `CustomerPersistencePort` không nhận Command trần — nó nhận
 * {@see VerifiedCustomerMutation}, một vật thể chỉ class này đóng dấu được.
 * Nghĩa là không có đường nào ghi lên khách hàng mà không đi qua đây, và điều
 * đó **fail-closed**: `VerificationAuthority` từ chối nếu class này chưa được
 * liệt kê trong `config/domain_mutation.php`. Implement interface KHÔNG tự cấp
 * quyền — đúng câu docblock của `VerificationAuthority`.
 *
 * ## Nó xác minh CÁI GÌ
 *
 * Một câu duy nhất, lặp cho mọi thao tác: **khách này có thật, và có nằm trong
 * phạm vi mà lệnh tự khai không**. Đó là chỗ dễ mất nhất khi mỗi controller tự
 * kiểm — mười chỗ kiểm là mười cơ hội quên một cái.
 *
 * ## Kế hoạch GỘP suy ra từ KHOÁ NGOẠI, không phải danh sách chép tay
 *
 * `CustomerMergePlan` cần biết bảng nào tham chiếu khách. Chép tay là danh sách
 * đó cũ đi ngay lần ai thêm bảng mới có `customer_id` — và triệu chứng là dữ
 * liệu của khách bị bỏ lại sau khi gộp, âm thầm. Nên nó là hằng số ĐO ĐƯỢC:
 * sáu bảng dưới đây lấy từ `foreign('customer_id')->references('id')->on('customers')`
 * trong migration, và có test đối chiếu lại với schema thật.
 */
final class EloquentCustomerAuthorityVerification implements CustomerAuthorityVerificationPort
{
    /** @var list<string> */
    private const ISSUANCE_SCOPES = ['customer.verified_mutation'];

    /**
     * Bảng mang khoá ngoại tới `customers`. Có test đối chiếu với schema.
     *
     * Đây là danh sách KIỂM TOÁN — "gộp phải động tới đúng sáu bảng này" — chứ
     * không phải danh sách để lặp: `EloquentCustomerPersistence::mergeCustomers`
     * ghi từng bảng bằng TÊN HẰNG, vì `DB::table($biến)` khiến
     * `architecture:domain-writers` chỉ đọc được `dynamic-table`, tức một cửa
     * ghi không kiểm toán được (bản dựng đầu bị bắt đúng vì lý do này).
     *
     * BA trong sáu bảng KHÔNG do CustomerEngagement ghi: `customer_orders` đi
     * qua `CustomerOrderReassignment` (Ordering); `coupon_redemptions` +
     * `coupons` qua `CustomerCouponReassignment` (Pricing). Ba bảng còn lại
     * (`branch_reviews`, `product_reviews`, `customer_point_entries`) thuộc
     * chính CustomerEngagement nên được ghi thẳng.
     *
     * `coupons` vào cổng vì lý do KHÁC hai bảng kia, và đó là chỗ dễ hụt: nó
     * không thuộc aggregate nào (`fk_reachability_exempt`) nên
     * `architecture:domain-writers` im lặng — nhưng nó thuộc module Pricing, và
     * `RawTableReadsTest` R2 mới là rào bắt được, với ngân sách 0. Nghe một rào
     * rồi dừng thì rào kia đỏ.
     *
     * `MergeWritesEveryReferenceTest` neo hai danh sách vào nhau, nên thêm một
     * bảng vào đây mà quên viết lệnh ghi sẽ ĐỎ, thay vì lặng lẽ bỏ sót dữ liệu.
     *
     * @var list<string>
     */
    public const MERGE_REFERENCES = [
        'branch_reviews',
        'coupon_redemptions',
        'customer_orders',
        'coupons',
        'customer_point_entries',
        'product_reviews',
    ];

    public function verifyLifecycleAuthority(CustomerLifecycleCommand $command): VerifiedCustomerMutation
    {
        // Khôi phục là thao tác DUY NHẤT nhìn hàng đã xoá mềm — mọi thao tác
        // khác chạy trên khách còn sống. Cùng bài học với `markRestored` của
        // Menu (#1550), chỉ khác aggregate.
        $this->assertCustomerExists(
            $command->customerId,
            $command->context->organizationId,
            withTrashed: $command->action === CustomerLifecycleAction::Restore,
        );

        return $this->issue($command, CustomerAuthorityOperation::from($command->action->value));
    }

    public function verifyTokenIssueAuthority(IssueCustomerAccessTokenCommand $command): VerifiedCustomerMutation
    {
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::IssueToken);
    }

    public function verifyTokenRevokeAuthority(RevokeCustomerAccessTokenCommand $command): VerifiedCustomerMutation
    {
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::RevokeToken);
    }

    public function verifyGlobalProfileAuthority(ReviseGlobalCustomerProfileCommand $command): VerifiedCustomerMutation
    {
        // Hồ sơ TOÀN CỤC chỉ tồn tại trên tài khoản đăng nhập. Cho phép sửa nó
        // trên một bản ghi CRM là ghi dữ liệu cấp tài khoản vào một hàng thuộc
        // về một cửa hàng.
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::GlobalProfile);
    }

    public function verifyTenantProfileAuthority(ReviseTenantCustomerProfileCommand $command): VerifiedCustomerMutation
    {
        $this->assertCustomerExists($command->customerId, $command->context->organizationId);

        return $this->issue($command, CustomerAuthorityOperation::TenantProfile);
    }

    public function verifyEmailAuthority(VerifyCustomerEmailCommand $command): VerifiedCustomerMutation
    {
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::VerifyEmail);
    }

    public function verifyLinkAuthority(LinkCustomerScopeCommand $command): VerifiedCustomerMutation
    {
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::LinkScope);
    }

    public function verifyUnlinkAuthority(UnlinkCustomerScopeCommand $command): VerifiedCustomerMutation
    {
        $this->assertCustomerExists($command->customerId, $command->context->organizationId);

        return $this->issue($command, CustomerAuthorityOperation::UnlinkScope);
    }

    public function verifyMergeAuthority(MergeCustomersCommand $command): VerifiedCustomerMutation
    {
        $organizationId = $command->context->organizationId;
        $this->assertCustomerExists($command->sourceCustomerId, $organizationId);
        $this->assertCustomerExists($command->targetCustomerId, $organizationId);

        if ($command->sourceCustomerId === $command->targetCustomerId) {
            throw new InvalidArgumentException('Cannot merge a customer into itself.');
        }

        return VerifiedCustomerMutation::issue(
            $this,
            $this->authority(),
            $command,
            CustomerAuthorityOperation::Merge,
            new CustomerMergePlan($command->sourceCustomerId, $command->targetCustomerId, self::MERGE_REFERENCES),
        );
    }

    public function verifyCredentialAuthority(ChangeCustomerCredentialsCommand $command): VerifiedCustomerMutation
    {
        $this->assertGlobalAccount($command->customerId);

        return $this->issue($command, CustomerAuthorityOperation::ChangeCredentials);
    }

    // =====================================================================

    private function issue(MutationCommand $command, CustomerAuthorityOperation $operation): VerifiedCustomerMutation
    {
        return VerifiedCustomerMutation::issue($this, $this->authority(), $command, $operation);
    }

    private function authority(): VerificationAuthority
    {
        return VerificationAuthority::forConfiguredAdapter(
            $this,
            CustomerAuthorityVerificationPort::class,
            self::ISSUANCE_SCOPES,
        );
    }

    private function assertCustomerExists(string $customerId, ?string $organizationId, bool $withTrashed = false): void
    {
        $exists = ($withTrashed ? Customer::withTrashed() : Customer::query())
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($customerId)
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customerId]);
        }
    }

    /** Tài khoản đăng nhập: KHÔNG mang tenant, và có mật khẩu. */
    private function assertGlobalAccount(string $customerId): void
    {
        $exists = Customer::query()
            ->whereNull('organization_id')
            ->whereNotNull('password')
            ->whereKey($customerId)
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customerId]);
        }
    }
}
