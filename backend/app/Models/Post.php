<?php

/**
 * Post Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Post\Models\PostBaseModel;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Post — add project-specific model logic here.
 */
class Post extends PostBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    // =====================================================================
    //  Text đã bản địa hoá, có đường lui (#1504)
    // =====================================================================

    /**
     * `config/translatable.php` đặt `use_fallback => false`, nên `$post->title`
     * trả **null** khi bài không có bản dịch cho ngôn ngữ đang xem. Với bài
     * viết được seed đủ ja/en/vi thì không ai thấy; với FAQ do người vận hành
     * tự gõ thì thấy ngay — nhập mỗi tiếng Việt là khách xem tiếng Nhật gặp
     * một dòng trống.
     *
     * Và đường lui một tầng của Astrotomic (`fallback_locale`) cũng không đủ:
     * bài chỉ có `vi` vẫn ra null vì fallback là `en`. Nên ở đây là dò lần
     * lượt: ngôn ngữ đang xem → fallback → phần còn lại.
     *
     * Một câu hỏi hiển thị bằng ngôn ngữ khác vẫn hơn một dòng trống. Muốn
     * đúng ngôn ngữ thì nhập đủ — đó là việc của người vận hành, không phải
     * thứ API nên im lặng nuốt.
     */
    public function localizedTitle(): ?string
    {
        return $this->localizedTranslation('title');
    }

    public function localizedExcerpt(): ?string
    {
        return $this->localizedTranslation('excerpt');
    }

    public function localizedContent(): ?string
    {
        return $this->localizedTranslation('content');
    }

    private function localizedTranslation(string $attribute): ?string
    {
        foreach ($this->translationLocalePreference() as $locale) {
            $value = $this->translate($locale)?->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Thứ tự dò: ngôn ngữ đang xem trước, rồi fallback_locale, rồi các ngôn
     * ngữ còn lại theo đúng thứ tự khai trong config (ổn định giữa các lần
     * gọi — không phụ thuộc thứ tự dòng trong DB).
     *
     * @return list<string>
     */
    private function translationLocalePreference(): array
    {
        /** @var list<string> $configured */
        $configured = config('translatable.locales', []);

        $preferred = [
            app()->getLocale(),
            (string) config('translatable.fallback_locale', 'en'),
            ...$configured,
        ];

        return array_values(array_unique(array_filter(
            $preferred,
            static fn ($locale): bool => is_string($locale) && $locale !== '',
        )));
    }
}
