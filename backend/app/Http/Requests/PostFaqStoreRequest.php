<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * #1504 — tạo một câu hỏi thường gặp.
 *
 * Không kế thừa `PostStoreRequestBase` của Omnify: màn hình này cố ý KHÔNG
 * phải CRUD bài viết, nên nó không được nhận `slug`, `category_id`, `status`,
 * `excerpt`, `cover_image_url`, `view_count` từ client. Những cột đó do
 * `FaqController` quyết định.
 *
 * Tiền tố `Post` trong tên class là do sổ sở hữu module quy định, không phải
 * thẩm mỹ: Deptrac suy chủ sở hữu của một Request bằng
 * cách khớp TIỀN TỐ tên model, nên `FaqStoreRequest` rơi vào `Unassigned` và
 * làm đỏ `ModuleBoundaryBaselineTest`. `Faq` không phải model (FAQ là `posts`
 * thuộc chuyên mục `faq`), và khai một model ma trong `config/modules.php` thì
 * `phantomModels()` bắt ngay.
 */
class PostFaqStoreRequest extends FormRequest
{
    /** @var list<string> */
    public const LOCALES = ['ja', 'en', 'vi'];

    /**
     * `post_translations.title` là `string(200)`, nên câu hỏi bị chặn ở 200 ký
     * tự — chặt ở tầng validate để người dùng thấy lỗi 422 tử tế thay vì một
     * `Data too long for column` từ MySQL.
     */
    public const QUESTION_MAX = 200;

    /** `content` là longText; trần này chỉ để chặn payload rác. */
    public const ANSWER_MAX = 65000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'is_published' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
        ];

        // `sometimes` để `validated()` không trả về khoá của ngôn ngữ vắng mặt
        // — controller phân biệt "không gửi" (giữ nguyên) với "gửi chuỗi rỗng".
        foreach (self::LOCALES as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.question"] = ['sometimes', 'nullable', 'string', 'max:'.self::QUESTION_MAX];
            $rules["{$locale}.answer"] = ['sometimes', 'nullable', 'string', 'max:'.self::ANSWER_MAX];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasAnyQuestion()) {
                return;
            }

            $validator->errors()->add(
                'ja.question',
                'The question field is required in at least one language (ja, en, or vi).',
            );
        });
    }

    protected function hasAnyQuestion(): bool
    {
        foreach (self::LOCALES as $locale) {
            $question = $this->input("{$locale}.question");

            if (is_scalar($question) && trim((string) $question) !== '') {
                return true;
            }
        }

        return false;
    }
}
