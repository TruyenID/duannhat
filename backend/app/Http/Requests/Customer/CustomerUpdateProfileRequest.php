<?php

namespace App\Http\Requests\Customer;

use App\Omnify\Enums\CustomerGenderEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerUpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Một form HTML gửi ô trống là `""`, không phải `null`. Với `phone` thì
        // `""` chỉ là giá trị rỗng, nhưng với `birthday`/`gender` nó còn làm rule
        // `date`/`enum` trượt (422) trong khi ý người dùng là "xoá khai báo".
        foreach (['phone', 'birthday', 'gender'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // `first_name` is NOT NULL in the DB, so it can be omitted (PATCH =
            // leave unchanged) but never cleared to null/"" — `sometimes|required`
            // rejects an explicit empty value with 422 instead of a 500.
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // `customers.phone` is a VARCHAR(20); cap validation at the column
            // width so an over-long value is rejected instead of truncated/500ing.
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            // Ngày sinh dân sự — `before_or_equal:today` dùng app clock là ĐÚNG ở
            // đây: đây không phải mốc nghiệp vụ của một chi nhánh nào (#1091-ok,
            // BusinessClock cần branch_id mà khách tự đăng ký thì không có).
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(CustomerGenderEnum::class)],
            // #1780 — trang đăng ký hứa với khách bấm "Không, bỏ qua" rằng
            // "Có thể đăng ký sau". Không có đường bật lại thì câu đó là lời
            // nói dối, nên chỗ bật lại nằm ngay đây. `boolean` (không
            // `nullable`): "chưa khai" không phải một trạng thái — cột NOT NULL
            // có default, gửi null lên chỉ có thể là lỗi client.
            'loyalty_opted_in' => ['sometimes', 'boolean'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
