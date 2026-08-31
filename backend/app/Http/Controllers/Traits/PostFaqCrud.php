<?php

namespace App\Http\Controllers\Traits;

use App\Models\Post;
use App\Models\PostBranch;
use App\Models\PostCategory;
use App\Services\Post\PostFaqService;
use Illuminate\Database\Eloquent\Collection;

/**
 * #1673 — phần dùng chung của Câu hỏi thường gặp, cho cả hai cấp:
 *
 *   HQ   (`/hq/{brand}/faqs`)     → `branch_id = null`, câu hỏi của toàn tổ chức
 *   Shop (`/shops/{shop}/faqs`)   → `branch_id = <chi nhánh>`, câu riêng chi nhánh
 *
 * Hai controller chỉ khác nhau ở MỘT thứ: phạm vi `branch_id`.
 *
 * #1666 — luật GHI đã chuyển sang {@see PostFaqService}. Ở lại đây đúng những
 * thứ thuộc tầng HTTP: `abort(404)` khi ngoài phạm vi, hình dạng payload, và
 * truy vấn danh sách cho màn hình quản trị.
 *
 * Tiền tố `Post` trong tên trait là do sổ sở hữu module quy định, không phải
 * thẩm mỹ: `ModuleGraph::entityHint()` khớp TIỀN TỐ tên model, nên tên bắt đầu
 * bằng `Faq` sẽ rơi vào `Unassigned` và làm đỏ `ModuleBoundaryBaselineTest`
 * (`Faq` không phải model — FAQ là `posts` thuộc chuyên mục `faq`). Cùng lý do
 * với `PostFaqStoreRequest` và với chính `PostFaqService`.
 */
trait PostFaqCrud
{
    protected function faqService(): PostFaqService
    {
        return app(PostFaqService::class);
    }

    // =====================================================================
    //  Truy vấn
    // =====================================================================

    /**
     * Câu hỏi trong đúng MỘT phạm vi.
     *
     * `$branchId === null` là phạm vi HQ và nó **không** bao gồm câu của chi
     * nhánh: thiếu `whereNull` thì sau khi migrate, HQ mở trang ra sẽ thấy lẫn
     * câu của cả 17 chi nhánh trong một bảng và tưởng là mình soạn ra chúng.
     *
     * @return Collection<int, Post>
     */
    protected function faqsInScope(string $organizationId, ?string $branchId)
    {
        $categoryId = PostCategory::query()
            ->where('organization_id', $organizationId)
            ->where('slug', PostFaqService::CATEGORY_SLUG)
            ->value('id');

        if ($categoryId === null) {
            return Post::query()->whereRaw('1 = 0')->get();
        }

        return Post::query()
            ->where('organization_id', $organizationId)
            ->where('category_id', $categoryId)
            ->when($branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId),
            )
            ->with('translations')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * #1684 — những câu HQ mà chi nhánh này đã tự tắt.
     *
     * BR-FB01: **không có dòng ⇒ còn hiện**, nên hàm này chỉ trả về id của các
     * dòng có `is_visible = false`. Gọi nó rồi lật ngược lại ("id nào không
     * nằm trong đây thì hiện") — đừng biến nó thành danh sách trắng, vì một
     * chi nhánh chưa từng bấm gì sẽ có 0 dòng và danh sách trắng rỗng nghĩa là
     * ẩn sạch.
     *
     * @return list<string>
     */
    protected function hiddenHqFaqIds(string $branchId): array
    {
        return PostBranch::query()
            ->where('branch_id', $branchId)
            ->where('is_visible', false)
            ->pluck('post_id')
            ->all();
    }

    /**
     * Chặn cả ba đường rò: bài của tổ chức khác, bài không phải FAQ, VÀ câu
     * hỏi thuộc phạm vi khác (HQ đụng câu của chi nhánh hoặc ngược lại).
     *
     * Trả **404 chứ không 403** — 403 xác nhận "id này có tồn tại, chỉ là bạn
     * không được đụng", tức là biến endpoint FAQ thành máy dò id bài viết cho
     * cả những bài news/promotion mà màn hình này không quản.
     */
    protected function authorizeFaqInScope(Post $faq, string $organizationId, ?string $branchId): void
    {
        if ($faq->organization_id !== $organizationId) {
            abort(404, 'FAQ entry not found.');
        }

        if ($faq->category?->slug !== PostFaqService::CATEGORY_SLUG) {
            abort(404, 'FAQ entry not found.');
        }

        if ($faq->branch_id !== $branchId) {
            abort(404, 'FAQ entry not found.');
        }
    }

    // =====================================================================
    //  Đọc
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    protected function toFaqArray(Post $faq): array
    {
        return [
            'id' => $faq->id,
            // Ngôn ngữ hiện hành (SetLocale middleware) — cùng giá trị mà API
            // khách sẽ trả cho người dùng đang xem bằng ngôn ngữ đó.
            'question' => $faq->localizedTitle(),
            'answer' => $faq->localizedContent(),
            'is_published' => $this->faqService()->isVisibleToCustomers($faq),
            'is_pinned' => (bool) $faq->is_pinned,
            'branch_id' => $faq->branch_id,
            'published_at' => $faq->published_at?->toISOString(),
            'translations' => collect(PostFaqService::LOCALES)
                ->mapWithKeys(fn (string $locale): array => [
                    $locale => [
                        'question' => $faq->translate($locale)?->title,
                        'answer' => $faq->translate($locale)?->content,
                    ],
                ])
                ->all(),
            'created_at' => $faq->created_at?->toISOString(),
            'updated_at' => $faq->updated_at?->toISOString(),
        ];
    }
}
