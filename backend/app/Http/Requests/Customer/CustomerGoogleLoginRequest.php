<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerGoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * #1784 — chỉ nhận ID token; mọi thứ khác đọc TỪ token sau khi xác minh.
     *
     * KHÔNG nhận `email` hay `sub` từ client: nhận là tin lời client nói về danh
     * tính của chính họ, và toàn bộ điểm của việc xác minh chữ ký là không phải
     * tin điều đó.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
