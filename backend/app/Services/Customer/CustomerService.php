<?php

namespace App\Services\Customer;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\Order\Contracts\CustomerOrderPresence;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class CustomerService
{
    public function hasAuthAccountForEmail(string $email): bool
    {
        return Customer::query()
            ->whereNotNull('password')
            ->where('email', $email)
            ->exists();
    }

    /**
     * Create a self-service customer auth account.
     *
     * `branch_id` / `brand_id` / `organization_id` do
     * `CustomerAuthService::attachRegistrationBranch()` resolve từ slug cửa
     * hàng trong URL đăng ký (#1505) — KHÔNG lấy thẳng từ client. Ba khoá này
     * nullable ở đây vì hàm còn phục vụ đường tạo tài khoản khác (nhân viên
     * tạo hộ khách) chưa có ngữ cảnh cửa hàng.
     *
     * `birthday` / `gender` / `loyalty_opted_in` đến từ form đăng ký (#1780).
     * Cả ba đều tuỳ chọn ở đây vì đường tạo tài khoản khác không hỏi chúng.
     *
     * `loyalty_opted_in` dùng `array_key_exists` chứ KHÔNG dùng `?? true`:
     * caller gửi `false` là một câu trả lời thật (khách bấm "Không, bỏ qua"),
     * còn `?? true` chỉ phân biệt được null. Không gửi gì thì để cột tự rơi về
     * default `true` — chính là hành vi trước khi có cột này.
     *
     * @param  array{first_name: string, last_name?: string, email: string, password: string, phone?: string, birthday?: string|null, gender?: string|null, loyalty_opted_in?: bool|null, branch_id?: int|null, brand_id?: int|null, organization_id?: int|null}  $data
     */
    public function createAuthAccount(array $data): Customer
    {
        $attributes = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'birthday' => $data['birthday'] ?? null,
            'gender' => $data['gender'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
        ];

        if (array_key_exists('loyalty_opted_in', $data) && $data['loyalty_opted_in'] !== null) {
            $attributes['loyalty_opted_in'] = (bool) $data['loyalty_opted_in'];
        }

        return Customer::create($attributes);
    }

    /**
     * #1784 — nối một hồ sơ khách đã có với danh tính Google.
     *
     * Nằm ở ĐÂY chứ không ở `CustomerAuthService` vì `customers` chỉ có DUY
     * NHẤT một người ghi được khai trong `config/domain-mutation-guard.php` —
     * cùng lý do `markPreGateAccountsVerified` nằm ở lớp này (#1680). Ghi thẳng
     * từ service auth là mở thêm một cửa sau mà `architecture:domain-writers`
     * tồn tại để chặn, và nó đã chặn thật khi tôi thử.
     */
    public function linkGoogleIdentity(Customer $account, string $googleSub): Customer
    {
        $account->forceFill(['google_id' => $googleSub])->save();

        return $account->refresh();
    }

    /**
     * #1784 — đóng dấu đã xác nhận email cho một danh tính bên thứ ba đã xác minh.
     *
     * Khác `verifyEmail()`: hàm kia phát sự kiện `Verified` cho luồng bấm-link
     * của khách. Ở đây Google đã chứng minh quyền sở hữu hòm thư, nên chỉ cần
     * đóng dấu — và cũng KHÔNG dùng `now()` một cách tuỳ tiện: đây là thời điểm
     * xác minh thật sự xảy ra.
     */
    public function markEmailVerifiedByProvider(Customer $account): void
    {
        if ($account->hasVerifiedEmail()) {
            return;
        }

        $account->forceFill(['email_verified_at' => now()])->save();
    }

    public function revokeCurrentAccessToken(Customer $account): void
    {
        $account->currentAccessToken()->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(Customer $account, array $data): Customer
    {
        $allowed = array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone', 'address', 'birthday', 'gender', 'loyalty_opted_in']));

        $account->fill($allowed);
        $changes = $account->getDirty();
        $original = array_intersect_key($account->getOriginal(), $changes);
        $account->save();

        if ($changes !== []) {
            $this->logAudit($account, 'profile_updated', [
                'changes' => $changes,
                'original' => $original,
            ]);
        }

        return $account->refresh();
    }

    /**
     * @param  array{password: string, logout_other_devices?: bool}  $data
     */
    public function changePassword(Customer $account, string $currentTokenId, array $data): void
    {
        $account->update([
            'password' => $data['password'],
        ]);

        if ($data['logout_other_devices'] ?? true) {
            $account->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        // No payload — never log the password (even hashed) per plan-010 D10.
        $this->logAudit($account, 'password_changed');
    }

    public function verifyEmail(Customer $customer): bool
    {
        if ($customer->hasVerifiedEmail()) {
            return false;
        }

        if ($customer->markEmailAsVerified()) {
            event(new Verified($customer));

            return true;
        }

        return false;
    }

    public function sendVerificationNotification(Customer $customer): void
    {
        $customer->sendEmailVerificationNotification();
    }

    /**
     * #1680 — đóng dấu đã-xác-nhận cho những tài khoản tạo TRƯỚC khi có cổng.
     *
     * Đây là phần bắt buộc của việc bật cổng, không phải dọn dẹp tuỳ chọn:
     * mọi tài khoản cũ đều có `email_verified_at = NULL` vì hồi đó không ai
     * phải xác nhận gì. Deploy mà không chạy lệnh này thì toàn bộ khách đang
     * dùng bị khoá ngoài cửa cùng một lúc, bằng đúng mật khẩu vẫn đang đúng.
     *
     * Dấu thời gian lấy theo `created_at` chứ không phải `now()`, để lịch sử
     * không nói dối rằng tất cả cùng xác nhận vào giây deploy.
     *
     * Việc ghi nằm ở service này vì `customers` chỉ có DUY NHẤT một người ghi
     * được khai trong `config/domain-mutation-guard.php` — một lệnh artisan
     * ghi thẳng vào model là mở thêm một cửa sau mà `architecture:domain-writers`
     * tồn tại để chặn.
     *
     * @return int số bản ghi (sẽ) được đóng dấu
     */
    public function markPreGateAccountsVerified(bool $apply, ?\DateTimeInterface $before = null): int
    {
        $pending = Customer::query()
            ->whereNotNull('password')
            ->whereNotNull('email')
            ->whereNull('email_verified_at')
            // #1730 — MỐC CẮT là bắt buộc về mặt ngữ nghĩa, dù tham số nullable.
            //
            // Tên method nói "pre-gate"; trước #1730 truy vấn không nói vậy, nên
            // nó đóng dấu cả tài khoản đăng ký SAU khi cổng bật. Kịch bản đã
            // chạy thật: deploy lúc T, T+1h một người đăng ký bằng địa chỉ gõ
            // nhầm và không bấm link — đúng người mà #1680 sinh ra để chặn —
            // rồi T+2h vận hành chạy backfill và địa chỉ đó được đóng dấu ĐÃ
            // XÁC NHẬN. Cổng thủng đúng bằng cửa sổ giữa deploy và backfill.
            //
            // `null` giữ nguyên hành vi cũ và CHỈ dùng được cho đường đếm khô;
            // `BackfillCustomerEmailVerified` bắt buộc `--before` khi `--apply`.
            ->when($before !== null, fn ($q) => $q->where('created_at', '<', $before));

        if (! $apply) {
            return $pending->count();
        }

        $stamped = 0;

        $pending->orderBy('id')->chunkById(200, function ($accounts) use (&$stamped) {
            foreach ($accounts as $account) {
                $account->forceFill([
                    'email_verified_at' => $account->created_at ?? now(),
                ])->save();
                $stamped++;
            }
        });

        return $stamped;
    }

    /**
     * @param  array{organization_id?: string, branch_id?: string, brand_id?: string, search?: string, phone?: string, with_trashed?: bool, with_point_balance?: bool, with_branch?: bool, sort?: string, per_page?: int}  $filters
     *
     * The `search` key performs a LIKE match against first_name, last_name, phone, and email.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Customer::query();

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        });

        $query->when($filters['phone'] ?? null, fn ($q, $phone) => $q->where('phone', 'like', "{$phone}%"));

        // #1700 — số dư điểm cho cột "Điểm" ở màn khách hàng HQ. Là một
        // subquery `withSum`, KHÔNG phải một cột: sổ điểm append-only, số dư
        // luôn là SUM(points) (BR-PT01). Có cờ vì các màn khác gọi chung hàm
        // này và không màn nào nên trả tiền cho một subquery nó không hiển thị.
        if (! empty($filters['with_point_balance'])) {
            $query->withSum('customerPointEntries as point_balance', 'points');
        }

        // #1712 — cột "Cửa hàng" ở màn khách hàng HQ. Có cờ vì màn Shop đã
        // scope sẵn một chi nhánh nên tên đó là thừa. `withTrashed` trên chính
        // relation: chi nhánh đóng cửa vẫn phải hiện tên, nếu không thì bật
        // "Hiển thị đã xóa" sẽ ra một cột toàn dấu gạch — trông như mất dữ
        // liệu chứ không phải như một chi nhánh đã ngừng hoạt động.
        if (! empty($filters['with_branch'])) {
            $query->with(['branch' => fn ($q) => $q->withTrashed()]);
        }

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Customer
    {
        return Customer::findOrFail($id);
    }

    /**
     * Exact-phone lookup scoped to org + branch.
     */
    public function findByPhone(string $phone, string $organizationId, ?string $branchId = null): ?Customer
    {
        $query = Customer::query()
            ->where('organization_id', $organizationId)
            ->where('phone', $phone);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    /**
     * Find an existing customer by exact phone within the given org+branch
     * scope, or create a minimal one. Used by the POS create-order flow
     * (staff captures only a phone number) and by the workstation sync-UP
     * endpoint, which additionally forwards the name/email the cashier
     * captured while LAN-only.
     *
     * When a row is created, the optional first_name/last_name/email from
     * $context are used; first_name falls back to the placeholder "Khách" so
     * the schema's required-name constraint holds and staff can edit later via
     * the customer admin screen. An existing row is returned untouched —
     * find-or-create never overwrites a known customer. Returns
     * [$customer, $wasCreated].
     *
     * @param  array{organization_id: string, branch_id?: string, brand_id?: string, first_name?: string|null, last_name?: string|null, email?: string|null}  $context
     * @return array{0: Customer, 1: bool}
     */
    public function findOrCreateByPhone(string $phone, array $context): array
    {
        $existing = $this->findByPhone(
            $phone,
            $context['organization_id'],
            $context['branch_id'] ?? null,
        );

        if ($existing) {
            return [$existing, false];
        }

        $firstName = trim((string) ($context['first_name'] ?? ''));

        $customer = $this->create([
            'organization_id' => $context['organization_id'],
            'branch_id' => $context['branch_id'] ?? null,
            'brand_id' => $context['brand_id'] ?? null,
            'first_name' => $firstName !== '' ? $firstName : 'Khách',
            'last_name' => $context['last_name'] ?? null,
            'email' => $context['email'] ?? null,
            'phone' => $phone,
        ]);

        return [$customer, true];
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->fresh();
    }

    public function delete(Customer $customer): void
    {
        // plan-042: block deleting a customer still referenced by an open order.
        if (app(CustomerOrderPresence::class)->hasOpenOrder((string) $customer->id)) {
            abort(response()->json([
                'message' => 'Cannot delete a customer who has an open order. Close their orders first.',
                'code' => 'CUSTOMER_DELETE_BLOCKED_OPEN_ORDER',
            ], 409));
        }

        $customer->delete();
    }

    public function restore(Customer $customer): Customer
    {
        $customer->restore();

        return $customer->fresh();
    }

    /**
     * Write a customer audit-trail row. Mirrors AuditsActivity::logAudit but is
     * scoped to explicit credential/profile events — the Customer model does
     * not opt into automatic auditing, so there is no double-logging.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logAudit(Customer $account, string $action, array $metadata = []): void
    {
        try {
            AuditLog::create([
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => $action,
                'user_id' => Auth::id(),
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        } catch (\Throwable) {
            // Audit must never break the business operation.
        }
    }
}
