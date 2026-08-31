<?php

namespace App\Services\Customer;

use App\Exceptions\EmailNotVerifiedException;
use App\Models\Customer;
use App\Notifications\Customer\ResetCustomerPassword;
use App\Services\Shop\PublicBranchScopeResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class CustomerAuthService
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly PublicBranchScopeResolver $branchScope,
        private readonly EmailVerificationCodeService $verificationCodes,
    ) {}

    /**
     * Đăng ký — KHÔNG phát token (#1680).
     *
     * Tài khoản được tạo nhưng chưa dùng được: `login()` từ chối cho tới khi
     * khách bấm link trong thư. Trước đây hàm này trả luôn `plainTextToken`,
     * tức email chỉ là một ô chữ chưa từng được chứng minh là của ai — nhưng
     * lại là thứ hoá đơn, thông báo đơn hàng và khôi phục mật khẩu bám vào.
     *
     * Vẫn tạo bản ghi ngay (không phải "đăng ký chờ") vì đó là chỗ duy nhất
     * giữ được `branch_id`/`brand_id`/`organization_id` đã phân giải từ slug
     * (#1505) cùng cái khoá chống đăng ký trùng bên dưới. Đổi lại, hàng ghi ra
     * là bản ghi CHƯA xác nhận và phải được đối xử như vậy ở mọi cổng.
     *
     * @param  array{first_name: string, last_name?: string, email: string, password: string, device_name: string, phone?: string, branch_slug: string}  $data
     */
    public function register(array $data): Customer
    {
        $email = $data['email'];
        $data = $this->attachRegistrationBranch($data);

        // The `customers` table has NO DB-level unique index on `email` (it is a
        // shared multi-tenant CRM table where password-less records may legitimately
        // repeat an address across orgs, and MySQL cannot express a partial
        // "unique among auth accounts" index). The FormRequest `unique:customers,email`
        // rule runs BEFORE the write, so two concurrent registrations can both pass
        // validation and create duplicate self-service accounts. Serialize the
        // check-and-create on the email under an exclusive lock and re-verify inside
        // it. Scope the re-check to auth-capable rows (password set) so it targets the
        // actual race (duplicate self-service accounts) and mirrors the login lookup.
        $lock = Cache::lock('customer:register:'.mb_strtolower($email), 10);

        return $lock->block(5, function () use ($data, $email) {
            if ($this->customerService->hasAuthAccountForEmail($email)) {
                throw ValidationException::withMessages([
                    'email' => [__('validation.unique', ['attribute' => 'email'])],
                ]);
            }

            return DB::transaction(function () use ($data) {
                $account = $this->customerService->createAuthAccount($data);

                // Fire Registered event to send verification email
                event(new Registered($account));

                return $account;
            });
        });
    }

    /**
     * Gắn cửa hàng nơi khách bấm đăng ký vào payload (#1505).
     *
     * Trước đây khách tự đăng ký luôn rơi vào `branch_id = NULL` — schema
     * `Customer.yaml` còn ghi hẳn "null với khách tự đăng ký" như một sự thật
     * hiển nhiên — nên không quy được khách về cửa hàng nào. Customer Web giờ
     * chỉ mở khu đăng ký ở URL có slug cửa hàng, và slug đó đi cùng request.
     *
     * Resolve slug → branch + brand + organization đúng theo đường mà đơn của
     * khách vãng lai đã đi (`CustomerOrderController::storeByBranch`): brand
     * nối qua `console_brand_id`, organization tra bằng
     * `console_organization_id`. Cả ba khoá ghi cùng lúc, không để lệch.
     *
     * `is_active` là bắt buộc: `GET /customer/branches` chỉ liệt kê chi nhánh
     * đang hoạt động, nên một slug đã tắt tới được đây chỉ có thể là URL cũ
     * hoặc gõ tay — không phải một cửa hàng nhận khách.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attachRegistrationBranch(array $data): array
    {
        // `?? null` chứ không đọc thẳng: FormRequest đã bắt buộc slug, nhưng
        // service này còn được gọi trực tiếp (test, lệnh artisan sau này) và
        // "Undefined array key" là cách hỏng tệ hơn hẳn một lỗi validation.
        //
        // Phép tra slug → branch → brand → organization thuộc module
        // Organization, nên nó đi qua contract của module đó thay vì với sang
        // ba model của người khác (#1526): làm tay ở đây đẩy số cạnh
        // CustomerEngagement → Organization 52 → 54 và làm đỏ bánh cóc ranh
        // giới trên `dev`. Nhu cầu xuyên module là chính đáng; với sang thì không.
        $scope = $this->branchScope->forPublicSlug($data['branch_slug'] ?? null);

        if ($scope === null) {
            throw ValidationException::withMessages([
                'branch_slug' => [__('validation.exists', ['attribute' => 'branch_slug'])],
            ]);
        }

        $data['branch_id'] = $scope['branch_id'];
        $data['brand_id'] = $scope['brand_id'];
        $data['organization_id'] = $scope['organization_id'];

        return $data;
    }

    /**
     * @param  array{email: string, password: string, device_name: string}  $data
     * @return array{account: Customer, token: string}
     *
     * @throws ValidationException
     */
    public function login(array $data): array
    {
        // `customers` is a shared multi-tenant table (org-scoped CRM records +
        // global self-service auth accounts), `email` is nullable and NOT unique,
        // so a bare where('email')->first() can resolve an arbitrary/cross-tenant
        // row — e.g. a password-less CRM record that shadows the real auth account,
        // locking the customer out. Only auth-capable rows (password set) are login
        // candidates; a password-less row could never pass Hash::check anyway.
        // #1782 — `identifier` nhận email HOẶC số điện thoại; `email` giữ lại
        // cho client cũ. Không đổi tên trường trong hợp đồng API là cố ý: đổi
        // sẽ làm hỏng mọi bản customer-web đang chạy ngoài kia cùng lúc.
        $identifier = CustomerLoginIdentifier::parse((string) ($data['identifier'] ?? $data['email'] ?? ''));

        $candidates = Customer::query()
            ->whereNotNull('password')
            ->when(
                $identifier->isEmail,
                fn ($q) => $q->where('email', $identifier->raw),
                fn ($q) => $q->whereIn('phone', $identifier->phoneVariants),
            )
            // Lấy TỐI ĐA 2 để phát hiện mơ hồ mà không kéo cả nghìn hàng về khi
            // một số bị nhập trùng hàng loạt.
            ->limit(2)
            ->get();

        // #1782 — SỐ TRÙNG thì TỪ CHỐI, không chọn bừa một hàng.
        //
        // `customers.phone` KHÔNG unique (chỉ có index `organization_id, phone`),
        // nên một số có thể ứng với nhiều tài khoản. Chọn hàng đầu tiên là đăng
        // nhập vào tài khoản NGƯỜI KHÁC nếu mật khẩu tình cờ khớp — không bao
        // giờ xác thực một định danh mơ hồ.
        //
        // Thông điệp nói THẲNG phải làm gì (dùng email), vì nếu không thì nhóm
        // khách này chỉ thấy "sai mật khẩu" và không đời nào đoán ra lý do.
        // Đây KHÔNG phải rò rỉ: nó chỉ nói số này trùng, không nói tài khoản nào.
        if (! $identifier->isEmail && $candidates->count() > 1) {
            throw ValidationException::withMessages([
                'identifier' => [__('auth.phone_ambiguous')],
            ]);
        }

        $account = $candidates->first();

        if (! $account || ! Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // #1680 — cổng thật của việc xác nhận email nằm ở ĐÂY, không nằm ở
        // việc `register` thôi phát token. Chỉ bỏ token lúc đăng ký thì tài
        // khoản chưa xác nhận vẫn đăng nhập được ngay sau đó bằng chính mật
        // khẩu vừa đặt — tức không chặn được gì cả.
        //
        // Kiểm SAU khi so mật khẩu, cố ý: trả "chưa xác nhận" cho một mật khẩu
        // sai sẽ biến endpoint này thành máy dò xem địa chỉ nào có tài khoản.
        if (! $account->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException((string) $account->email);
        }

        $token = $account->createToken($data['device_name'])->plainTextToken;

        return ['account' => $account, 'token' => $token];
    }

    /**
     * Gửi lại thư xác nhận (#1680) — CÔNG KHAI, không cần token.
     *
     * Phải công khai vì đúng những người cần nó là những người chưa có token:
     * đăng ký xong, thư lạc vào spam, và tài khoản thì chưa đăng nhập được.
     * Endpoint cũ nằm sau `auth:customer` nên trên thực tế không ai với tới.
     *
     * Không nói cho người gọi biết địa chỉ có tồn tại hay không (controller
     * luôn trả cùng một câu) và chỉ gửi thư cho tài khoản CÓ THẬT và CHƯA xác
     * nhận — nên nó không dò được danh sách email, cũng không dùng để dội thư
     * vào một địa chỉ chưa từng đăng ký.
     */
    public function resendVerification(string $email): void
    {
        $account = Customer::query()
            ->whereNotNull('password')
            ->where('email', $email)
            ->first();

        if ($account === null || $account->hasVerifiedEmail()) {
            return;
        }

        $this->customerService->sendVerificationNotification($account);
    }

    /**
     * Xác nhận email bằng mã 6 chữ số gõ tay.
     *
     * Trả về một trong các hằng `EmailVerificationCodeService::RESULT_*`, cộng
     * thêm `'already'` cho địa chỉ đã xác nhận từ trước.
     *
     * Địa chỉ KHÔNG tồn tại nhận đúng `RESULT_EXPIRED` như một địa chỉ có thật
     * mà chưa xin mã — cùng lý do với `resendVerification`: endpoint này công
     * khai, và một câu trả lời riêng cho "email này chưa từng đăng ký" biến nó
     * thành máy dò danh sách khách của quán. Trạng thái "chưa có mã nào đang
     * sống" là câu đúng cho cả hai trường hợp, nên không phải nói dối để giấu.
     */
    public function verifyEmailCode(string $email, string $code): string
    {
        $account = Customer::query()
            ->whereNotNull('password')
            ->where('email', $email)
            ->first();

        if ($account === null) {
            return EmailVerificationCodeService::RESULT_EXPIRED;
        }

        if ($account->hasVerifiedEmail()) {
            // Dọn luôn mã còn sống: email đã xác nhận rồi thì mã kia không còn
            // việc gì để làm, và một bí mật không còn dùng tới thì đừng để nằm lại.
            $this->verificationCodes->invalidate($account);

            return 'already';
        }

        $result = $this->verificationCodes->verify($account, $code);

        if ($result === EmailVerificationCodeService::RESULT_OK) {
            $this->customerService->verifyEmail($account);
        }

        return $result;
    }

    /**
     * #1783 — gửi link đặt lại mật khẩu. CÔNG KHAI, không cần token.
     *
     * Trả về `void` trong MỌI trường hợp, kể cả khi địa chỉ không tồn tại. Nếu
     * phân biệt được "có gửi" với "không có tài khoản" thì chính form quên mật
     * khẩu trở thành máy dò xem địa chỉ nào đã đăng ký — cùng lý do
     * `resendVerification` im lặng, và cùng lý do `login()` kiểm trạng thái xác
     * nhận SAU khi so mật khẩu.
     *
     * Tra người dùng ở ĐÂY chứ không qua provider của broker: `customers` là
     * bảng đa-tenant dùng chung, `email` nullable và KHÔNG unique, nên một truy
     * vấn theo email trần có thể trúng hàng CRM không mật khẩu và che mất tài
     * khoản thật (xem khối chú thích trong `login()`).
     *
     * KHÔNG gửi cho tài khoản chưa xác nhận email? Có gửi — cố ý. Người quên mật
     * khẩu và người chưa bấm link xác nhận thường là cùng một người, và bắt họ
     * xác nhận trước mới được đặt lại là một vòng luẩn quẩn: thư xác nhận cũ đã
     * trôi mất trong hộp thư. Xem `resetPassword()` cho phần đóng vòng đó.
     */
    public function sendPasswordResetLink(string $email): void
    {
        if ((string) Config::get('customer.web_url', '') === '') {
            // Link sẽ hỏng — thà không gửi. Một thư đặt lại mật khẩu dẫn tới hư
            // không khiến khách tin hệ thống hỏng, chứ không nghĩ tới cấu hình.
            Log::warning('[#1783] Bỏ qua thư đặt lại mật khẩu: customer.web_url chưa cấu hình.');

            return;
        }

        $account = $this->passwordResetCandidate($email);
        if ($account === null) {
            return;
        }

        $token = $this->passwordResetTokens()->create($account);

        $account->notify(new ResetCustomerPassword($token));
    }

    /**
     * #1783 — đặt mật khẩu mới bằng token trong thư.
     *
     * @param  array{email: string, token: string, password: string}  $data
     *
     * @throws ValidationException token sai/hết hạn, hoặc địa chỉ không có tài khoản
     */
    public function resetPassword(array $data): void
    {
        $account = $this->passwordResetCandidate($data['email']);

        // Gộp "không có tài khoản" và "token sai" thành MỘT thông điệp: tách ra
        // là để lộ địa chỉ nào đã đăng ký, đúng thứ `sendPasswordResetLink` vừa
        // giấu đi.
        if ($account === null || ! $this->passwordResetTokens()->exists($account, $data['token'])) {
            throw ValidationException::withMessages([
                'token' => [__('passwords.token')],
            ]);
        }

        DB::transaction(function () use ($account, $data): void {
            // `changePassword` của `CustomerService` cần `currentTokenId` để
            // GIỮ LẠI phiên hiện tại — luồng này không có phiên nào (khách chưa
            // đăng nhập được), và cũng KHÔNG muốn giữ phiên nào. Nên ghi thẳng
            // rồi tự xoá sạch token bên dưới.
            $account->update(['password' => $data['password']]);

            // #1680 — bấm được link trong thư CHỨNG MINH quyền kiểm soát hòm
            // thư, đúng bằng thứ mà link xác nhận chứng minh. Không đóng dấu ở
            // đây thì người vừa đặt lại mật khẩu vẫn không đăng nhập được, và
            // thư xác nhận cũ thì đã trôi mất — một ngõ cụt tự tạo ra.
            if (! $account->hasVerifiedEmail()) {
                $account->markEmailAsVerified();
            }

            // Mật khẩu đã đổi thì mọi phiên cũ phải chết: kịch bản của luồng này
            // là "có thể ai đó đã vào được tài khoản".
            $account->tokens()->delete();
        });

        $this->passwordResetTokens()->delete($account);
    }

    /**
     * Hàng DUY NHẤT được coi là tài khoản đăng nhập cho một địa chỉ.
     *
     * Cùng vị từ với `login()` và `resendVerification()` — ba đường phải đồng ý
     * với nhau, nếu không thì đặt lại mật khẩu cho một hàng mà đăng nhập không
     * bao giờ chọn tới.
     */
    private function passwordResetCandidate(string $email): ?Customer
    {
        return Customer::query()
            ->whereNotNull('password')
            ->where('email', $email)
            ->first();
    }

    private function passwordResetTokens(): TokenRepositoryInterface
    {
        return Password::broker('customer_accounts')->getRepository();
    }

    /**
     * #1784 — đăng nhập bằng Google. Hệ RIÊNG, không đi qua SSO nhân viên.
     *
     * @param  array{id_token: string, device_name: string}  $data
     * @return array{account: Customer, token: string, created: bool}
     *
     * @throws ValidationException token không hợp lệ, email chưa xác nhận, hoặc mơ hồ
     */
    public function loginWithGoogle(array $data): array
    {
        $verifier = app(GoogleIdentityVerifier::class);

        if (! $verifier->enabled()) {
            throw ValidationException::withMessages(['id_token' => [__('auth.google_disabled')]]);
        }

        try {
            $claims = $verifier->verify($data['id_token']);
        } catch (\Throwable $e) {
            // KHÔNG chuyển thông điệp của verifier ra ngoài: nó phân biệt được
            // "aud sai" với "chữ ký hỏng" với "hết hạn", và đó là bản đồ miễn phí
            // cho người đang dò. Chi tiết vào log, khách nhận một câu.
            Log::warning('[#1784] Google ID token bị từ chối: '.$e->getMessage());

            throw ValidationException::withMessages(['id_token' => [__('auth.google_failed')]]);
        }

        // RULING 1 — email Google CHƯA xác nhận thì TỪ CHỐI, không tạo, không nối.
        //
        // `email_verified = false` nghĩa là Google cũng không biết người này có
        // sở hữu hòm thư hay không. Nối một tài khoản theo địa chỉ chưa xác nhận
        // là trao tài khoản của người khác cho ai đăng ký trùng địa chỉ.
        if (! $claims['email_verified']) {
            throw ValidationException::withMessages(['id_token' => [__('auth.google_email_unverified')]]);
        }

        $bySub = Customer::query()->where('google_id', $claims['sub'])->first();
        if ($bySub !== null) {
            return $this->issueGoogleSession($bySub, false);
        }

        // Tra theo email trong số hàng ĐĂNG NHẬP ĐƯỢC — cùng vị từ `login()`
        // dùng, vì lý do y hệt: hàng CRM không mật khẩu có thể che tài khoản thật.
        $candidates = Customer::query()
            ->whereNotNull('password')
            ->where('email', $claims['email'])
            ->limit(2)
            ->get();

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages(['id_token' => [__('auth.google_ambiguous')]]);
        }

        // RULING 2 — email TRÙNG một tài khoản mật khẩu thì NỐI, không tạo hàng thứ hai.
        //
        // Google đã khẳng định người đang đứng đây kiểm soát hòm thư đó, và hòm
        // thư đó chính là thứ đặt-lại-mật-khẩu (#1783) dùng để trao lại quyền.
        // Nói cách khác họ VỐN ĐÃ vào được tài khoản này bằng một đường dài hơn;
        // từ chối nối chỉ tạo ra hai hồ sơ cho một người, và điểm thưởng, lịch sử
        // đơn, coupon sẽ chia đôi.
        $existing = $candidates->first();
        if ($existing !== null) {
            $this->customerService->linkGoogleIdentity($existing, $claims['sub']);

            return $this->issueGoogleSession($existing, false);
        }

        $account = $this->customerService->create([
            'first_name' => $claims['name'] ?? 'Khách',
            'email' => $claims['email'],
            'google_id' => $claims['sub'],
            // KHÔNG có mật khẩu. Tài khoản này đăng nhập bằng Google; muốn có
            // mật khẩu thì đi đường "quên mật khẩu" (#1783) — nhưng đường đó lọc
            // `whereNotNull('password')` nên hiện KHÔNG với tới tài khoản chỉ-Google.
            // Ghi ra đây vì đó là một khoảng trống thật, không phải sơ suất.
            'password' => null,
        ]);

        return $this->issueGoogleSession($account, true);
    }

    /**
     * @return array{account: Customer, token: string, created: bool}
     */
    private function issueGoogleSession(Customer $account, bool $created): array
    {
        // RULING 3 — email do Google khẳng định đã xác nhận thì TÍNH LÀ đã xác
        // nhận ở phía mình (#1680). Bắt khách bấm thêm một link nữa để chứng
        // minh thứ Google vừa chứng minh là một vòng vô nghĩa.
        $this->customerService->markEmailVerifiedByProvider($account);

        return [
            'account' => $account,
            'token' => $account->createToken('google')->plainTextToken,
            'created' => $created,
        ];
    }

    public function logout(Customer $account): void
    {
        $this->customerService->revokeCurrentAccessToken($account);
    }

    public function updateProfile(Customer $account, array $data): Customer
    {
        return $this->customerService->updateProfile($account, $data);
    }

    /**
     * @param  array{password: string, logout_other_devices?: bool}  $data
     */
    public function changePassword(Customer $account, string $currentTokenId, array $data): void
    {
        $this->customerService->changePassword($account, $currentTokenId, $data);
    }
}
