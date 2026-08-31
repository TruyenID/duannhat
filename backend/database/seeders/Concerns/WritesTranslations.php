<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Ghi bản dịch trong seeder mà KHÔNG phụ thuộc model event.
 *
 * ## Vì sao seeder cần đường riêng
 *
 * Astrotomic bền hoá bản dịch trong một hook `static::saved(...)`
 * (`vendor/astrotomic/laravel-translatable/src/Translatable/Translatable.php:35`
 * → `saveTranslations()`). Mà `DatabaseSeeder` `use WithoutModelEvents`, tức cả
 * lượt `migrate:fresh --seed` chạy trong `Model::withoutEvents()` — hook đó
 * KHÔNG BAO GIỜ bắn.
 *
 * Cái bọc nằm ở `DatabaseSeeder`, nên seeder con có hay không trait đó đều bị.
 *
 * ## Hai kiểu ghi, và chỉ một kiểu sống — đã đo
 *
 *   A. `$m->translateOrNew('ja')->name = 'x'; $m->save();`   → **0 bản dịch**
 *   B. `$t = $m->translateOrNew('ja'); $t->name = 'x'; $t->save();` → 1 bản dịch
 *
 * Kiểu A giao việc bền hoá cho hook cha, nên nó bốc hơi lặng lẽ. Kiểu B ghi
 * thẳng model dịch — model đó KHÔNG dùng trait Translatable nên không có hook
 * nào để mất.
 *
 * Trait này là kiểu B, cộng hai thứ kiểu B trần không tự có:
 *
 *   - đi qua QUAN HỆ (`$model->translations()`), nên khoá ngoại luôn được đặt.
 *     `LocalDevSeeder` từng phải viết tay `$trans->category_id = $category->id;
 *     // Ensure FK is set` — đúng cái bẫy đó.
 *   - idempotent theo `locale`, nên seeder chạy lại không nhân đôi hàng.
 *
 * ## Cái bẫy đã trả giá
 *
 * `locale` KHÔNG nằm trong `$fillable` của model dịch (Omnify chỉ sinh các cột
 * nội dung), nên mass assignment bỏ qua nó và bản ghi mới chết ở NOT NULL. Phải
 * gán tay — điều kiện tra cứu ở `firstOrNew` là query builder nên vẫn đúng, chỉ
 * lượt fill là không.
 *
 * ## Vì sao nó đáng có một trait riêng
 *
 * Chuyện này đã hai lần đi tới chỗ quán không thu được tiền: bản dịch rỗng làm
 * trường i18n phát ra `[]`, máy trạm giải mã vào `map[string]string` thất bại,
 * và MỘT trường rỗng giết cả lượt giải mã feed (#2470 · #2477). Một hàm nằm
 * private trong một seeder không ngăn được seeder thứ hai đi lại vết đó.
 */
trait WritesTranslations
{
    /**
     * @param  array<string, array<string, mixed>>  $byLocale  locale → [thuộc tính => giá trị]
     */
    protected function writeTranslations(Model $model, array $byLocale): void
    {
        foreach ($byLocale as $locale => $attributes) {
            $translation = $model->translations()->firstOrNew(['locale' => $locale]);
            $translation->locale = $locale;
            $translation->fill($attributes);
            $translation->save();
        }
    }
}
