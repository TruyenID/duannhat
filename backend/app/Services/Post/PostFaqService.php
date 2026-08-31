<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Models\Post;
use App\Models\PostBranch;
use App\Models\PostCategory;
use App\Omnify\Enums\PostStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Luật ghi của Câu hỏi thường gặp (#1666, tách khỏi `PostFaqCrud`).
 *
 * `PostFaqCrud` gom được phần dùng chung của hai controller HQ/Shop (#1673) và
 * làm đúng việc đó — nhưng một trait trong `app/Http/Controllers/Traits` vẫn là
 * code của tầng HTTP, nên "chuyên mục tự sinh ở lần ghi đầu", "slug rỗng thì
 * lấy gì", "câu hỏi rỗng nghĩa là không đổi chứ không phải xoá" và máy trạng
 * thái xuất bản đang là luật nghiệp vụ sống trong controller.
 *
 * Trait GIỮ LẠI đúng ba thứ thuộc về tầng HTTP: `authorizeFaqInScope` (abort
 * 404), `toFaqArray` (hình dạng payload) và truy vấn danh sách. Chúng không có
 * chỗ nào tốt hơn để ở.
 *
 * ## Vì sao tên bắt đầu bằng `Post`
 *
 * Không phải thẩm mỹ, giống hệt lý do của `PostFaqCrud` và `PostFaqStoreRequest`:
 * `ModuleGraph::entityHint()` gán chủ sở hữu bằng cách khớp TIỀN TỐ tên class với
 * tên model, và `Post` thuộc CustomerEngagement. Một class tên `FaqService` rơi
 * vào `Unassigned` và làm đỏ `ModuleBoundaryBaselineTest` — `Faq` không phải
 * model, FAQ là `posts` thuộc chuyên mục `faq`.
 */
final class PostFaqService
{
    /** Slug của PostCategory chứa FAQ. Đổi ở đây là đổi cả customer-web. */
    public const CATEGORY_SLUG = 'faq';

    /** @var list<string> */
    public const LOCALES = ['ja', 'en', 'vi'];

    /** Tên chuyên mục dùng khi phải tự tạo nó ở lần ghi đầu tiên. */
    private const CATEGORY_NAMES = [
        'ja' => 'よくある質問',
        'en' => 'FAQ',
        'vi' => 'Câu hỏi thường gặp',
    ];

    /**
     * Tạo một câu hỏi trong đúng một phạm vi.
     *
     * `$branchId === null` là câu của toàn tổ chức (HQ); khác null là câu riêng
     * của chi nhánh. Client không có đường nào tự đặt cột này — phạm vi do
     * ROUTE quyết định, nên nó là tham số chứ không phải một khoá trong `$data`.
     *
     * Transaction bọc cả chuyên mục lẫn bài. Một chuyên mục `faq` mồ côi không
     * làm hỏng gì (lần ghi sau tìm lại đúng nó), nên đây KHÔNG phải bản vá cho
     * một lỗi mất dữ liệu — nó chỉ giữ cho "một lần tạo" là một đơn vị.
     *
     * Còn một khoảng đua chưa đóng, ghi ra để không ai tưởng nó đã kín:
     * `uniqueSlug()` dò rồi mới ghi, nên hai lượt tạo đồng thời cùng câu hỏi có
     * thể tính ra cùng một slug và lượt sau vỡ ở unique index
     * `(organization_id, slug)`. Với màn hình quản trị một người dùng thì chưa
     * đáng đổi lấy vòng thử-lại; đóng nó là bắt lỗi unique rồi tăng hậu tố.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $organizationId, ?string $branchId, ?string $authorId, array $data): Post
    {
        return DB::transaction(function () use ($organizationId, $branchId, $authorId, $data): Post {
            $faq = new Post;
            $faq->organization_id = $organizationId;
            $faq->category_id = $this->ensureCategory($organizationId)->id;
            $faq->branch_id = $branchId;
            $faq->author_id = $authorId;
            $faq->slug = $this->uniqueSlug($organizationId, $this->firstQuestion($data));

            $this->applyTranslations($faq, $data);
            $this->applyPublication($faq, $data, creating: true);
            $faq->save();

            return $faq;
        });
    }

    /**
     * Sửa từng phần. Ngôn ngữ không gửi thì không đụng; slug KHÔNG bao giờ đổi
     * (nó đã nằm trong URL mà khách bookmark).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Post $faq, array $data): Post
    {
        $this->applyTranslations($faq, $data);
        $this->applyPublication($faq, $data, creating: false);
        $faq->save();

        return $faq;
    }

    /**
     * Xoá mềm — không có gì tham chiếu tới một câu hỏi theo kiểu lịch sử (khác
     * `VoidReason`, nơi dòng đơn hàng trỏ vào reason bằng id).
     */
    public function delete(Post $faq): void
    {
        $faq->delete();
    }

    /**
     * #1684 — chi nhánh bật/tắt một câu HQ mà nó kế thừa.
     *
     * Bật lại GHI `is_visible = true` chứ không xoá dòng: xoá là mất luôn
     * `updated_by`/`updated_at`, đúng thứ HQ sẽ hỏi khi một câu quan trọng
     * không hiện ở một cửa hàng.
     */
    public function setInheritedVisibility(Post $faq, string $branchId, bool $isVisible, ?string $toggledById = null): void
    {
        // `toggled_by_id` điền TAY (#1689).
        //
        // Bản đầu dùng `options.audit` của Omnify để tự điền `created_by_id` /
        // `updated_by_id`. Nhưng `AuditConfig` sinh cột `bigint unsigned` cứng
        // — không có tuỳ chọn kiểu khoá — trong khi `users.id` ở repo này là
        // `char(36)`. Nhét UUID vào bigint thì MySQL truncate và MỌI lần bấm
        // công tắc đều chết bằng "Data truncated for column 'created_by_id'".
        //
        // Cột giờ là Association thật (uuid, FK sang users, SET NULL), đổi lại
        // mất hook auto-fill đi kèm `audit` — nên người bấm phải truyền vào.
        PostBranch::query()->updateOrCreate(
            ['post_id' => $faq->id, 'branch_id' => $branchId],
            ['is_visible' => $isVisible, 'toggled_by_id' => $toggledById],
        );
    }

    /**
     * Chuyên mục `faq` tự sinh ở lần ghi đầu tiên — không bắt ai chạy seeder
     * bằng tay trước khi màn hình quản trị dùng được. Chuyên mục là của TỔ
     * CHỨC, dùng chung cho cả HQ lẫn mọi chi nhánh; phạm vi nằm ở `branch_id`
     * của từng bài, không phải ở chuyên mục.
     */
    public function ensureCategory(string $organizationId): PostCategory
    {
        $category = PostCategory::query()
            ->where('organization_id', $organizationId)
            ->where('slug', self::CATEGORY_SLUG)
            ->first();

        if ($category !== null) {
            return $category;
        }

        $category = new PostCategory;
        $category->organization_id = $organizationId;
        $category->slug = self::CATEGORY_SLUG;
        $category->sort_order = 0;
        $category->is_active = true;

        foreach (self::CATEGORY_NAMES as $locale => $name) {
            $category->translateOrNew($locale)->name = $name;
        }

        $category->save();

        return $category;
    }

    /**
     * Đúng bộ điều kiện mà `CustomerPostController` dùng — hai chỗ lệch nhau thì
     * admin sẽ báo "đang hiện" cho một câu hỏi khách không thấy.
     */
    public function isVisibleToCustomers(Post $faq): bool
    {
        return $faq->status === PostStatusEnum::Published
            && $faq->published_at !== null
            && $faq->published_at->lessThanOrEqualTo(now());
    }

    /**
     * Slug duy nhất trong tổ chức.
     *
     * Ba cái bẫy: câu hỏi toàn chữ Nhật cho ra `Str::slug` RỖNG trong khi cột là
     * NOT NULL; unique index `(organization_id, slug)` vẫn tính cả dòng đã
     * soft-delete nên phải dò `withTrashed()`; và unique đó là theo TỔ CHỨC chứ
     * không theo chi nhánh, nên hai chi nhánh đặt trùng câu hỏi vẫn phải ra hai
     * slug khác nhau.
     */
    public function uniqueSlug(string $organizationId, string $question): string
    {
        $base = Str::limit(Str::slug($question), 160, '');

        if ($base === '') {
            $base = self::CATEGORY_SLUG;
        }

        $slug = $base;
        $suffix = 1;

        while (Post::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * Câu hỏi đầu tiên có nội dung, theo thứ tự ja → en → vi. Chỉ dùng để đặt
     * slug; request đã đảm bảo có ít nhất một ngôn ngữ.
     *
     * @param  array<string, mixed>  $data
     */
    public function firstQuestion(array $data): string
    {
        foreach (self::LOCALES as $locale) {
            $question = $data[$locale]['question'] ?? null;

            if (is_string($question) && trim($question) !== '') {
                return trim($question);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyTranslations(Post $faq, array $data): void
    {
        foreach (self::LOCALES as $locale) {
            $payload = $data[$locale] ?? null;

            if (! is_array($payload)) {
                continue;
            }

            $question = isset($payload['question']) && is_string($payload['question'])
                ? trim($payload['question'])
                : null;
            $answer = isset($payload['answer']) && is_string($payload['answer'])
                ? $payload['answer']
                : null;

            // `post_translations.title` là NOT NULL. Một ngôn ngữ chỉ gửi câu
            // trả lời mà chưa từng có câu hỏi thì bỏ qua hẳn ngôn ngữ đó, chứ
            // không tạo một dòng title=null rồi chết ở tầng DB.
            $existing = $faq->translate($locale);
            if ($existing === null && ($question === null || $question === '')) {
                continue;
            }

            $translation = $faq->translateOrNew($locale);

            // Câu hỏi rỗng = "không đổi", KHÔNG phải "xoá": cột NOT NULL nên xoá
            // câu hỏi của một ngôn ngữ không phải thao tác biểu diễn được.
            if ($question !== null && $question !== '') {
                $translation->title = $question;
            }

            if ($answer !== null) {
                $translation->content = $answer;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyPublication(Post $faq, array $data, bool $creating): void
    {
        if (isset($data['is_pinned'])) {
            $faq->is_pinned = (bool) $data['is_pinned'];
        } elseif ($creating) {
            $faq->is_pinned = false;
        }

        $published = match (true) {
            isset($data['is_published']) => (bool) $data['is_published'],
            // Người vận hành vừa gõ xong một câu hỏi thì mặc định là muốn nó
            // hiện — bắt bấm thêm một nút "xuất bản" chỉ để lại FAQ nháp vô hình.
            $creating => true,
            default => null,
        };

        if ($published === null) {
            return;
        }

        $faq->status = $published ? PostStatusEnum::Published : PostStatusEnum::Draft;

        // Giữ nguyên `published_at` cũ khi bật lại: thứ tự danh sách là
        // `published_at DESC`, nên đóng/mở một câu hỏi cũ mà dập lại mốc thời
        // gian sẽ ném nó lên đầu trang FAQ của khách.
        if ($published && $faq->published_at === null) {
            $faq->published_at = now();
        }
    }
}
