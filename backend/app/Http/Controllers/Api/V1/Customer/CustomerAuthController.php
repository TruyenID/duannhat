<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerChangePasswordRequest;
use App\Http\Requests\Customer\CustomerForgotPasswordRequest;
use App\Http\Requests\Customer\CustomerGoogleLoginRequest;
use App\Http\Requests\Customer\CustomerLoginRequest;
use App\Http\Requests\Customer\CustomerRegisterRequest;
use App\Http\Requests\Customer\CustomerResendVerificationRequest;
use App\Http\Requests\Customer\CustomerResetPasswordRequest;
use App\Http\Requests\Customer\CustomerUpdateProfileRequest;
use App\Http\Requests\Customer\CustomerVerifyEmailCodeRequest;
use App\Models\Customer;
use App\Services\Customer\CustomerAuthService;
use App\Services\Customer\CustomerService;
use App\Services\Customer\EmailVerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class CustomerAuthController extends Controller
{
    /**
     * Ngôn ngữ Customer Web phục vụ. Phần tử đầu là mặc định khi link không
     * mang locale (link cũ, hoặc chữ ký hỏng nên không tin được query string).
     *
     * @var list<string>
     */
    private const SUPPORTED_LOCALES = ['ja', 'en', 'vi'];

    /** @var array<string, string> */
    private const VERIFICATION_MESSAGES = [
        'ok' => 'Email verified successfully.',
        'already' => 'Email already verified.',
        'expired' => 'Verification link has expired.',
        'invalid' => 'Invalid verification link.',
        'failed' => 'Email verification failed.',
    ];

    /**
     * Lời từ chối cho đường MÃ 6 chữ số — tách khỏi bảng của đường link ở trên
     * vì hai đường nói về hai vật khác nhau ("link hết hạn" vs "mã hết hạn"),
     * và dùng chung một câu là để lại chữ "link" trong một màn không có link nào.
     *
     * @var array<string, string>
     */
    private const CODE_FAILURE_MESSAGES = [
        EmailVerificationCodeService::RESULT_INVALID => 'The code is incorrect.',
        EmailVerificationCodeService::RESULT_EXPIRED => 'The code has expired. Request a new one.',
        EmailVerificationCodeService::RESULT_TOO_MANY_ATTEMPTS => 'Too many incorrect attempts. Request a new code.',
    ];

    public function __construct(
        private CustomerAuthService $authService,
        private CustomerService $customerService,
    ) {}

    /**
     * #1680 — 202 kèm địa chỉ email, KHÔNG kèm token.
     *
     * 202 chứ không 201 vì việc chưa xong: tài khoản đã ghi nhưng chưa dùng
     * được, phần còn lại nằm ở hộp thư của khách. Client đọc `email` để hiện
     * màn "hãy kiểm tra hộp thư" và để gọi gửi lại mà không bắt gõ lại địa chỉ.
     */
    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $account = $this->authService->register($request->validated());

        return response()->json([
            'data' => [
                'email' => $account->email,
                'verification_required' => true,
            ],
            'message' => 'Verification email sent.',
        ], 202);
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $result['account']->id,
                    'name' => $result['account']->name,
                    'email' => $result['account']->email,
                    'email_verified' => $result['account']->hasVerifiedEmail(),
                ],
                'token' => $result['token'],
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout($request->user('customer'));

        return response()->noContent();
    }

    public function user(Request $request): JsonResponse
    {
        $account = $request->user('customer');

        return response()->json([
            'data' => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'email_verified' => $account->hasVerifiedEmail(),
                'first_name' => $account->first_name,
                'last_name' => $account->last_name,
                'phone' => $account->phone,
                'address' => $account->address,
                // `Y-m-d` chứ không phải ISO-8601: đây là ngày dân sự, gắn giờ
                // vào là mời client dịch múi giờ và lệch mất một ngày.
                'birthday' => $account->birthday?->toDateString(),
                'gender' => $account->gender?->value,
                // #1780 — khách chọn ở trang đăng ký, đổi được sau ở trang tài
                // khoản. Trả về để UI không phải đoán trạng thái nút.
                'loyalty_opted_in' => (bool) $account->loyalty_opted_in,
            ],
        ]);
    }

    public function update(CustomerUpdateProfileRequest $request): JsonResponse
    {
        $account = $this->authService->updateProfile(
            $request->user('customer'),
            $request->validated(),
        );

        return response()->json([
            'data' => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'phone' => $account->phone,
                'birthday' => $account->birthday?->toDateString(),
                'gender' => $account->gender?->value,
                'loyalty_opted_in' => (bool) $account->loyalty_opted_in,
            ],
        ]);
    }

    public function changePassword(CustomerChangePasswordRequest $request): Response
    {
        $this->authService->changePassword(
            $request->user('customer'),
            $request->user('customer')->currentAccessToken()->id,
            $request->validated(),
        );

        return response()->noContent();
    }

    /**
     * Đích của link trong thư — luôn TRẢ KHÁCH VỀ Customer Web (#1680).
     *
     * Không còn dùng middleware `signed`: nó ném 403 trần cho mọi kiểu hỏng,
     * mà "link hết hạn" và "link bị sửa" cần hai lời nhắn khác nhau — cái thứ
     * nhất chỉ cần bấm gửi lại, cái thứ hai thì không. Chữ ký vẫn được kiểm ở
     * đây bằng chính `hasValidSignature()`, không nới lỏng gì.
     */
    public function verify(Request $request): JsonResponse|RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->verificationOutcome(
                $request,
                URL::signatureHasNotExpired($request) ? 'invalid' : 'expired',
            );
        }

        $customer = Customer::find($request->route('id'));

        if ($customer === null || ! hash_equals(sha1((string) $customer->email), (string) $request->route('hash'))) {
            return $this->verificationOutcome($request, 'invalid');
        }

        if ($customer->hasVerifiedEmail()) {
            return $this->verificationOutcome($request, 'already');
        }

        $verified = $this->customerService->verifyEmail($customer);

        return $this->verificationOutcome($request, $verified ? 'ok' : 'failed');
    }

    /**
     * Dựng đích quay về sau khi bấm link xác nhận.
     *
     * `locale`/`shop` đến từ query string đã được ký (xem `AppServiceProvider`),
     * nhưng vẫn được lọc lại ở đây: nhánh hỏng chữ ký cũng đi qua hàm này, và ở
     * nhánh đó hai giá trị kia là dữ liệu người lạ. Locale phải nằm trong danh
     * sách hỗ trợ, slug phải là slug — nếu không thì một link bịa có thể ghép
     * đường dẫn tuỳ ý vào URL trả về.
     */
    private function verificationOutcome(Request $request, string $status): JsonResponse|RedirectResponse
    {
        $base = (string) Config::get('customer.web_url');

        if ($base === '') {
            // Không cấu hình Customer Web (máy dev, test) → giữ nguyên hành vi
            // JSON cũ thay vì chuyển hướng vào một URL rỗng.
            return response()->json(
                ['message' => self::VERIFICATION_MESSAGES[$status], 'status' => $status],
                $status === 'ok' || $status === 'already' ? 200 : 400,
            );
        }

        $locale = (string) $request->query('locale', '');
        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = self::SUPPORTED_LOCALES[0];
        }

        $shop = (string) $request->query('shop', '');
        $shop = preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/i', $shop) === 1 ? $shop : '';

        $query = ['verified' => $status];

        if ($shop === '') {
            // Khách đăng ký từ trước #1505 có thể không gắn cửa hàng nào —
            // đẩy về chỗ chọn cửa hàng thay vì dựng một URL /login/ cụt.
            $path = "/{$locale}/select-branch";
            $query['next'] = 'login';
        } else {
            $path = "/{$locale}/login/{$shop}";
        }

        return redirect()->away($base.$path.'?'.http_build_query($query));
    }

    /**
     * Gửi lại thư xác nhận — công khai (#1680).
     *
     * Câu trả lời KHÔNG đổi theo việc địa chỉ có tồn tại hay đã xác nhận chưa:
     * endpoint này công khai, nên một câu trả lời phân biệt được hai trường hợp
     * sẽ biến nó thành máy dò xem ai đã đăng ký ở quán này.
     */
    public function resend(CustomerResendVerificationRequest $request): JsonResponse
    {
        $this->authService->resendVerification($request->validated()['email']);

        return response()->json([
            'message' => 'If that address needs verification, a new code has been sent.',
        ]);
    }

    /**
     * Xác nhận email bằng mã 6 chữ số gõ trên Customer Web.
     *
     * 422 với `errors.code` chứ không phải 400 hay 403 cho mọi kiểu hỏng: ô mà
     * khách vừa gõ là ô `code`, và form ở phía kia đã biết cách gắn lỗi vào ô
     * mang tên trường. Một `message` trần thì rơi vào banner chung, xa chỗ
     * khách đang nhìn.
     *
     * `reason` đi kèm để giao diện biết CÓ ĐƯỢC GÕ LẠI KHÔNG: `invalid` thì gõ
     * lại là xong, còn `expired`/`too_many_attempts` thì mã đã chết, gõ lại
     * bao nhiêu lần cũng vô ích — phải bấm gửi lại. Đó là hai lời khuyên khác
     * nhau, nên không được để chúng ra cùng một câu.
     */
    public function verifyCode(CustomerVerifyEmailCodeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->authService->verifyEmailCode($data['email'], $data['code']);

        if ($result === EmailVerificationCodeService::RESULT_OK || $result === 'already') {
            return response()->json([
                'data' => ['verified' => true, 'status' => $result === 'already' ? 'already' : 'ok'],
                'message' => self::VERIFICATION_MESSAGES[$result === 'already' ? 'already' : 'ok'],
            ]);
        }

        return response()->json([
            'message' => self::CODE_FAILURE_MESSAGES[$result],
            'reason' => $result,
            'errors' => ['code' => [self::CODE_FAILURE_MESSAGES[$result]]],
        ], 422);
    }

    /**
     * #1784 — đăng nhập bằng Google. CÔNG KHAI.
     *
     * Trả về cùng hình dạng với `login` cộng cờ `created` để FE biết đây là
     * lượt đăng ký đầu tiên (hiện màn chào mừng) hay một lượt đăng nhập lại.
     */
    public function loginWithGoogle(CustomerGoogleLoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginWithGoogle($request->validated());

        // Hình dạng ĐÚNG BẰNG `login()` cộng cờ `created`. Trả một shape khác
        // (ví dụ một Resource) buộc FE phải xử hai kiểu payload cho cùng một
        // việc "đăng nhập xong" — và chỗ rẽ nhánh đó là nơi trạng thái phiên
        // lệch nhau về sau.
        return response()->json([
            'data' => [
                'user' => [
                    'id' => $result['account']->id,
                    'name' => $result['account']->name,
                    'email' => $result['account']->email,
                    'email_verified' => $result['account']->hasVerifiedEmail(),
                ],
                'token' => $result['token'],
                'created' => $result['created'],
            ],
        ]);
    }

    /**
     * #1783 — xin link đặt lại mật khẩu. CÔNG KHAI.
     *
     * Trả 200 với CÙNG một câu, kể cả khi địa chỉ không có tài khoản. Phân biệt
     * được hai trường hợp là biến form quên-mật-khẩu thành máy dò tài khoản —
     * cùng chính sách với `resend` (#1680).
     */
    public function forgotPassword(CustomerForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->sendPasswordResetLink($request->validated()['email']);

        return response()->json([
            'message' => 'If that address has an account, a reset link has been sent.',
        ]);
    }

    /**
     * #1783 — đặt mật khẩu mới bằng token trong thư.
     *
     * Token sai / hết hạn / đã dùng và địa chỉ không có tài khoản đều trả về
     * CÙNG một lỗi 422 trên trường `token`.
     */
    public function resetPassword(CustomerResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());

        return response()->json([
            'message' => 'Password has been reset.',
        ]);
    }
}
