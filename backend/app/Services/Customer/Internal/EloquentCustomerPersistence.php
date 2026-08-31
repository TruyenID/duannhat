<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\FindOrCreateCustomerCommand;
use App\Services\Customer\Commands\IssueCustomerAccessTokenCommand;
use App\Services\Customer\Commands\LinkCustomerScopeCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\RegisterCustomerAccountCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Commands\ReviseTenantCustomerProfileCommand;
use App\Services\Customer\Commands\RevokeCustomerAccessTokenCommand;
use App\Services\Customer\Commands\UnlinkCustomerScopeCommand;
use App\Services\Customer\Commands\VerifyCustomerEmailCommand;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\CustomerService;
use App\Services\Customer\Enums\CustomerAuthorityOperation;
use App\Services\Customer\Results\CustomerAuthenticationResult;
use App\Services\Customer\Results\CustomerMergeResult;
use App\Services\Customer\Results\CustomerMutationResult;
use App\Services\Customer\Results\CustomerResolvedResult;
use App\Services\Customer\ValueObjects\CustomerAccessTokenSecret;
use App\Services\Customer\ValueObjects\OptionalProfileField;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;
use App\Services\Order\Contracts\CustomerOrderReassignment;
use App\Services\Promotion\Contracts\CustomerCouponReassignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Phía GHI của ranh giới Customer (#1550).
 *
 * Uỷ quyền cho `CustomerService` đang chạy production, đúng khuôn
 * `EloquentProductPersistence` / `EloquentMenuPersistence`. Không viết lại
 * đường ghi khách hàng.
 *
 * ## Mọi method GHI đều đòi một mutation ĐÃ ĐÓNG DẤU
 *
 * Chữ ký nhận {@see VerifiedCustomerMutation}, không nhận Command trần —
 * `assertTrusted()` ném nếu vật thể không do
 * {@see EloquentCustomerAuthorityVerification} đóng dấu. Nghĩa là không có
 * đường nào ghi lên khách mà bỏ qua khâu xác minh, và điều đó được cưỡng chế
 * bằng kiểu chứ không bằng quy ước.
 *
 * ## Hai loại khách, một bảng
 *
 * `GlobalAccount` (tài khoản đăng nhập: `organization_id` NULL, có mật khẩu) và
 * `TenantCrm` (bản ghi của một cửa hàng: đủ org+brand+branch). Ràng buộc đó do
 * `CustomerScopeEvidence` phát biểu, và các method dưới đây tôn trọng nó — sửa
 * hồ sơ toàn cục trên một bản ghi CRM là ghi dữ liệu cấp tài khoản vào hàng
 * thuộc về một cửa hàng.
 */
final class EloquentCustomerPersistence implements CustomerPersistencePort
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerOrderReassignment $orderReassignment,
        private readonly CustomerCouponReassignment $couponReassignment,
    ) {}

    // =====================================================================
    //  Tạo mới
    // =====================================================================

    public function insertCustomer(RegisterCustomerCommand $command): CustomerMutationResult
    {
        $p = $command->payload;

        $customer = $this->withSuppliedId(fn () => $this->customers->create(array_filter([
            'id' => $command->customerId,
            'organization_id' => $command->context->organizationId,
            'brand_id' => $command->brandId,
            'branch_id' => $command->branchId,
            'first_name' => $p->givenName,
            'last_name' => $p->familyName,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'tax_code' => $p->taxCode,
            'note' => $p->note,
        ], static fn ($v) => $v !== null)));

        return new CustomerMutationResult((string) $customer->id, true);
    }

    public function insertAccountAndIssueToken(RegisterCustomerAccountCommand $command): CustomerAuthenticationResult
    {
        $p = $command->profile;

        $created = $this->withSuppliedId(fn () => $this->customers->createAuthAccount([
            'id' => $command->customerId,
            'first_name' => $p->givenName,
            'last_name' => $p->familyName,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'password' => $command->password->reveal(),
        ]));

        // `createAuthAccount` trả về hình dạng của tầng HTTP cũ (mảng có
        // `customer` + `token`) hoặc model, tuỳ đường. Chuẩn hoá ở đây thay vì
        // bắt mọi chỗ gọi tự đoán.
        [$customer, $token] = $this->splitAccountResult($created);

        return new CustomerAuthenticationResult(
            (string) $customer->id,
            $command->tokenName,
            new CustomerAccessTokenSecret($token),
        );
    }

    public function findOrInsertCustomer(FindOrCreateCustomerCommand $command): CustomerResolvedResult
    {
        $p = $command->payload;
        $scope = $command->scope;

        $existing = $p->phone === null ? null : Customer::query()
            ->where('organization_id', $scope->organizationId)
            ->where('phone', $p->phone)
            ->first();

        if ($existing !== null) {
            return new CustomerResolvedResult((string) $existing->id, false);
        }

        $customer = $this->withSuppliedId(fn () => $this->customers->create(array_filter([
            'id' => $command->candidateCustomerId,
            'organization_id' => $scope->organizationId,
            'brand_id' => $scope->brandId,
            'branch_id' => $scope->branchId,
            'first_name' => $p->givenName,
            'last_name' => $p->familyName,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'tax_code' => $p->taxCode,
            'note' => $p->note,
        ], static fn ($v) => $v !== null)));

        return new CustomerResolvedResult((string) $customer->id, true);
    }

    // =====================================================================
    //  Phiên đăng nhập
    // =====================================================================

    public function issueAccessToken(VerifiedCustomerMutation $command): CustomerAuthenticationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::IssueToken);
        /** @var IssueCustomerAccessTokenCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);
        $token = $account->createToken($cmd->tokenName);

        return new CustomerAuthenticationResult(
            (string) $account->id,
            (string) $token->accessToken->id,
            new CustomerAccessTokenSecret($token->plainTextToken),
        );
    }

    public function revokeAccessToken(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::RevokeToken);
        /** @var RevokeCustomerAccessTokenCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);

        // `revokeOtherTokens` là "đăng xuất mọi thiết bị KHÁC" — cố ý giữ token
        // hiện tại, nếu không người vừa bấm nút sẽ tự đá mình ra giữa chừng.
        $revoked = $cmd->revokeOtherTokens
            ? $account->tokens()->where('id', '!=', $cmd->tokenId)->delete()
            : $account->tokens()->where('id', $cmd->tokenId)->delete();

        return new CustomerMutationResult((string) $account->id, $revoked > 0);
    }

    public function replaceCredentials(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::ChangeCredentials);
        /** @var ChangeCustomerCredentialsCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);
        $account->forceFill(['password' => $cmd->payload->reveal()])->save();

        // Đổi mật khẩu phải làm mọi phiên cũ hết hiệu lực — nếu không, kẻ đã
        // lấy được token vẫn vào được sau khi nạn nhân đổi mật khẩu, và đó
        // chính là việc mà "đổi mật khẩu" sinh ra để chặn.
        $account->tokens()->delete();

        return new CustomerMutationResult((string) $account->id, true);
    }

    // =====================================================================
    //  Hồ sơ
    // =====================================================================

    public function applyGlobalProfileRevision(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::GlobalProfile);
        /** @var ReviseGlobalCustomerProfileCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);
        $p = $cmd->payload;

        $patch = $this->patch([
            'first_name' => $p->givenName,
            'last_name' => $p->familyName,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
        ]);

        if ($patch === []) {
            return new CustomerMutationResult((string) $account->id, false);
        }

        $this->customers->update($account, $patch);

        return new CustomerMutationResult((string) $account->id, true);
    }

    public function applyTenantProfileRevision(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::TenantProfile);
        /** @var ReviseTenantCustomerProfileCommand $cmd */
        $cmd = $command->command;

        $customer = $this->scopedCustomer($cmd->customerId, $cmd->context->organizationId);
        $p = $cmd->payload;

        $patch = $this->patch([
            'first_name' => $p->givenName,
            'last_name' => $p->familyName,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'tax_code' => $p->taxCode,
            'note' => $p->note,
        ]);

        if ($patch === []) {
            return new CustomerMutationResult((string) $customer->id, false);
        }

        $this->customers->update($customer, $patch);

        return new CustomerMutationResult((string) $customer->id, true);
    }

    public function recordEmailVerification(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::VerifyEmail);
        /** @var VerifyCustomerEmailCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);

        // Đã xác nhận rồi thì KHÔNG dời mốc: mốc ấy là bằng chứng thời điểm, và
        // ghi đè nó bằng lần bấm link thứ hai làm mất dấu lần đầu. Cùng luật mà
        // `BackfillEmailVerifiedTest` ghim cho đường backfill (#1730).
        if ($account->email_verified_at !== null) {
            return new CustomerMutationResult((string) $account->id, false);
        }

        $account->forceFill(['email_verified_at' => $cmd->evidence->emailVerifiedAt])->save();

        return new CustomerMutationResult((string) $account->id, true);
    }

    // =====================================================================
    //  Phạm vi (scope)
    // =====================================================================

    /**
     * Gắn một TÀI KHOẢN TOÀN CỤC vào phạm vi của một cửa hàng.
     *
     * ⚠ Schema chỉ đỡ được MỘT phạm vi cho mỗi hàng: `customers` có
     * `organization_id`/`brand_id`/`branch_id` là cột, KHÔNG có bảng nối. Nên
     * "gắn" ở đây là ĐẶT ba cột đó, và gắn sang thương hiệu thứ hai sẽ rời khỏi
     * thương hiệu thứ nhất — không phải quan hệ nhiều-nhiều.
     *
     * Đó là hệ quả có thật cho khách mua ở hai thương hiệu trong cùng một tổ
     * chức. Muốn giữ cả hai thì cần một bảng nối, tức đổi schema — việc đó nằm
     * ngoài #1550 và phải do chủ sản phẩm quyết. Ghi ở đây để người sau không
     * đọc `linkScope` như thể nó là nhiều-nhiều.
     */
    public function linkScope(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::LinkScope);
        /** @var LinkCustomerScopeCommand $cmd */
        $cmd = $command->command;

        $account = $this->globalAccount($cmd->customerId);
        $account->forceFill(array_filter([
            'organization_id' => $cmd->scope->organizationId,
            'brand_id' => $cmd->payload->brandId,
            'branch_id' => $cmd->payload->branchId,
        ], static fn ($v) => $v !== null))->save();

        return new CustomerMutationResult((string) $account->id, true);
    }

    public function unlinkScope(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::UnlinkScope);
        /** @var UnlinkCustomerScopeCommand $cmd */
        $cmd = $command->command;

        $customer = $this->scopedCustomer($cmd->customerId, $cmd->context->organizationId);

        // Gỡ phạm vi đưa hàng về TÀI KHOẢN TOÀN CỤC, nên nó chỉ hợp lệ khi hàng
        // đó thật sự là một tài khoản đăng nhập. Gỡ phạm vi khỏi một bản ghi CRM
        // thuần sẽ tạo ra một hàng không thuộc về ai và không đăng nhập được —
        // vô hình với mọi màn hình.
        if ($customer->password === null) {
            throw new \InvalidArgumentException('Only a login account can be unlinked from a tenant scope.');
        }

        $customer->forceFill([
            'organization_id' => null,
            'brand_id' => null,
            'branch_id' => null,
        ])->save();

        return new CustomerMutationResult((string) $customer->id, true);
    }

    // =====================================================================
    //  Vòng đời + gộp
    // =====================================================================

    public function markArchived(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::Archive);
        /** @var CustomerLifecycleCommand $cmd */
        $cmd = $command->command;

        $customer = $this->scopedCustomer($cmd->customerId, $cmd->context->organizationId);
        $this->customers->delete($customer);

        return new CustomerMutationResult((string) $customer->id, true);
    }

    public function markRestored(VerifiedCustomerMutation $command): CustomerMutationResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::Restore);
        /** @var CustomerLifecycleCommand $cmd */
        $cmd = $command->command;

        // Khôi phục là thao tác DUY NHẤT nhìn hàng đã xoá mềm.
        $customer = Customer::withTrashed()
            ->when($cmd->context->organizationId !== null, fn ($q) => $q->where('organization_id', $cmd->context->organizationId))
            ->whereKey($cmd->customerId)
            ->firstOrFail();

        $this->customers->restore($customer);

        return new CustomerMutationResult((string) $customer->id, true);
    }

    /**
     * Gộp hai khách: mọi tham chiếu chuyển sang bên GIỮ LẠI, bên kia lưu trữ.
     *
     * Danh sách bảng KHÔNG chép tay ở đây — nó tới từ `CustomerMergePlan` mà
     * cổng xác minh dựng, và cổng đó suy nó từ khoá ngoại thật
     * ({@see EloquentCustomerAuthorityVerification::MERGE_REFERENCES}). Chép tay
     * là danh sách cũ đi ngay lần ai thêm bảng có `customer_id`, và triệu chứng
     * là dữ liệu khách bị bỏ lại sau khi gộp — âm thầm.
     *
     * MỘT transaction: gộp nửa chừng để lại đơn trỏ về một khách đã lưu trữ.
     */
    public function mergeCustomers(VerifiedCustomerMutation $command): CustomerMergeResult
    {
        $command->assertTrusted(CustomerAuthorityOperation::Merge);
        /** @var MergeCustomersCommand $cmd */
        $cmd = $command->command;
        $plan = $command->mergePlan;

        $moved = DB::transaction(function () use ($plan, $cmd): int {
            $from = $plan->sourceCustomerId;
            $to = $plan->targetCustomerId;

            // Ba bảng CÓ CHỦ đi qua cổng của chủ (#1550). Xem khối chú thích
            // của `MERGE_REFERENCES` để biết vì sao bốn bảng còn lại thì không.
            $rows = $this->orderReassignment->reassignCustomer($from, $to)
                + $this->couponReassignment->reassignCustomer($from, $to);

            // Tên bảng là HẰNG, không phải biến. `DB::table($biến)` khiến
            // `architecture:domain-writers` chỉ đọc được `dynamic-table` — một
            // cửa ghi mà rào không biết nó chạm vào đâu, nên không kiểm toán
            // được. Bản dựng đầu viết vòng lặp và bị bắt đúng vì lý do này.
            $rows += DB::table('branch_reviews')->where('customer_id', $from)->update(['customer_id' => $to]);
            $rows += DB::table('customer_point_entries')->where('customer_id', $from)->update(['customer_id' => $to]);
            $rows += DB::table('product_reviews')->where('customer_id', $from)->update(['customer_id' => $to]);

            $source = Customer::query()->whereKey($cmd->sourceCustomerId)->first();
            if ($source !== null) {
                $this->customers->delete($source);
            }

            return $rows;
        });

        return new CustomerMergeResult($cmd->sourceCustomerId, $cmd->targetCustomerId, $moved >= 0);
    }

    // =====================================================================

    /** @param array<string, OptionalProfileField> $fields */
    private function patch(array $fields): array
    {
        $out = [];
        foreach ($fields as $column => $field) {
            if ($field->provided) {
                $out[$column] = $field->value;
            }
        }

        return $out;
    }

    private function scopedCustomer(string $customerId, ?string $organizationId): Customer
    {
        return Customer::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($customerId)
            ->firstOrFail();
    }

    private function globalAccount(string $customerId): Customer
    {
        $account = Customer::query()
            ->whereNull('organization_id')
            ->whereNotNull('password')
            ->whereKey($customerId)
            ->first();

        if ($account === null) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customerId]);
        }

        return $account;
    }

    /** @return array{0: Customer, 1: string} */
    private function splitAccountResult(mixed $created): array
    {
        if ($created instanceof Customer) {
            return [$created, $created->createToken('customer')->plainTextToken];
        }

        $customer = $created['customer'] ?? null;
        $token = $created['token'] ?? null;

        if (! $customer instanceof Customer || ! is_string($token)) {
            throw new \LogicException('createAuthAccount returned an unexpected shape.');
        }

        return [$customer, $token];
    }

    /**
     * Ghi mà id do NGƯỜI GỌI cấp thật sự được dùng — xem #1744.
     *
     * `'id'` không nằm trong `$fillable` của `Customer`, nên
     * `Model::create(['id' => …])` bỏ qua nó và sinh uuid khác. Điều đó phá đúng
     * thứ id-do-người-gọi-cấp sinh ra để làm: chống trùng.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private function withSuppliedId(\Closure $write): mixed
    {
        return Model::unguarded($write);
    }
}
