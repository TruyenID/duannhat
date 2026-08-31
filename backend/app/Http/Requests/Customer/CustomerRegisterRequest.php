<?php

namespace App\Http\Requests\Customer;

use App\Omnify\Enums\CustomerGenderEnum;
use App\Rules\StrongCustomerPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ô trống của form HTML gửi lên `""`, không phải `null`. Với `birthday` /
     * `gender` thì `""` làm rule `date` / `enum` trượt (422) trong khi ý khách
     * là "không khai" — cả hai đều là trường tuỳ chọn. Giống hệt cách
     * `CustomerUpdateProfileRequest` xử lý, cố ý.
     */
    protected function prepareForValidation(): void
    {
        foreach (['birthday', 'gender'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:customers,email'],
            // REQUIRED từ #1780 (trước là nullable) — form đăng ký giờ bắt buộc
            // SĐT. `max:20` chứ không phải `max:50` như trước: `customers.phone`
            // là VARCHAR(20), nên 21-50 ký tự qua được validate rồi mới chết ở
            // tầng DB — một 500 thay cho một 422 lẽ ra phải đọc được.
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', new StrongCustomerPassword, 'confirmed'],
            // Ngày sinh dân sự — `before_or_equal:today` dùng app clock là ĐÚNG
            // ở đây (#1091-ok): không phải mốc nghiệp vụ của chi nhánh nào, và
            // BusinessClock cần branch_id mà lúc validate còn chưa resolve slug
            // ra chi nhánh. Giống hệt `CustomerUpdateProfileRequest`.
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(CustomerGenderEnum::class)],
            // Khách có tham gia chương trình thành viên hay không (#1780).
            // `nullable` chứ không `required`: client cũ (và mọi caller không
            // phải form đăng ký) không gửi field này thì rơi về default `true`
            // của cột — hành vi y hệt trước khi có cột, tức mọi khách đều tích
            // điểm.
            'loyalty_opted_in' => ['nullable', 'boolean'],
            'device_name' => ['required', 'string'],
            // Cửa hàng khách bấm đăng ký (#1505). REQUIRED chứ không nullable:
            // nullable là tái lập đúng cái bug đang sửa — khách tự đăng ký rơi
            // vào branch_id = NULL và không quy về cửa hàng nào được. Customer
            // Web chỉ còn mở /register/{shop} nên slug luôn có sẵn.
            // Slug có tồn tại / còn hoạt động hay không do CustomerAuthService
            // kiểm — cùng một chỗ với việc resolve ra brand + organization.
            'branch_slug' => ['required', 'string', 'max:100'],
        ];
    }
}
