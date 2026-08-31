<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Post;
use App\Omnify\Enums\PostStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomerPostController extends Controller
{
    private const DEFAULT_LIMIT = 6;

    private const MAX_LIMIT = 20;

    /**
     * #1441 — trang FAQ dùng lại chính bảng `posts` (category slug `faq`), nên
     * nó cần đọc được CẢ NỘI DUNG chứ không chỉ excerpt, và nhiều hơn 20 mục.
     * Một danh sách Q&A mà mỗi câu hỏi phải gọi thêm một request mới thấy được
     * câu trả lời là một trang FAQ hỏng.
     *
     * Trần cao hơn CHỈ mở khi có lọc category — không có ràng buộc đó thì
     * `?limit=100&with_content=1` biến endpoint bài viết công khai thành lệnh
     * dump toàn bộ nội dung site trong một cú.
     */
    private const CATEGORY_MAX_LIMIT = 100;

    /**
     * #1504 — chuyên mục bị loại khỏi danh sách KHÔNG lọc category.
     *
     * `blog-excerpt-section.tsx` gọi `/posts?limit=6` không kèm category để lấy
     * bài cho carousel trang chủ. FAQ dùng chung bảng `posts`, nên nếu không
     * loại ra thì mỗi câu hỏi thường gặp mới thêm sẽ đẩy một bài viết thật ra
     * khỏi trang chủ. Hỏi lấy thẳng `?category=faq` thì vẫn ra bình thường.
     *
     * @var list<string>
     */
    private const FEED_EXCLUDED_CATEGORY_SLUGS = ['faq'];

    /**
     * List published posts for the customer-facing site. Pinned posts come
     * first, then most recent.
     *
     * Public — no auth required. Locale picked up from SetLocale middleware
     * so the returned title/excerpt match the caller's UI language.
     *
     * Query params:
     *   - limit         1..20 (1..100 khi có `category`), default 6
     *   - category      slug của PostCategory (vd `faq`)
     *   - with_content  1 ⇒ kèm `content`; chỉ có tác dụng khi có `category`
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;

        $maxLimit = $category !== null ? self::CATEGORY_MAX_LIMIT : self::MAX_LIMIT;
        $limit = (int) $request->query('limit', (string) self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, $maxLimit));

        $withContent = $category !== null && $request->boolean('with_content');

        // #1673 — phạm vi chi nhánh. `branch` là slug; chi nhánh lạ ⇒ coi như
        // không truyền, KHÔNG phải 404: đây là endpoint công khai và một slug
        // sai không đáng làm hỏng trang.
        $branch = $this->resolveBranch($request->query('branch'));
        $organizationId = $branch === null ? null : $this->organizationIdForBranch($branch);

        $posts = Post::query()
            ->where('status', PostStatusEnum::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->when($category, fn ($q, $slug) => $q->whereHas(
                'category',
                fn ($cq) => $cq->where('slug', $slug)
            ))
            // Không có chi nhánh ⇒ chỉ bài cấp tổ chức. Có chi nhánh ⇒ bài của
            // chính nó, cộng bài cấp tổ chức NẾU công tắc kế thừa đang bật.
            ->when($branch === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where(function ($sub) use ($branch) {
                    $sub->where('branch_id', $branch->id);
                    if ($branch->faq_inherit_hq) {
                        // #1684 — bài cấp tổ chức, TRỪ những bài chi nhánh này
                        // đã tự tắt.
                        //
                        // BR-FB01: KHÔNG có dòng ⇒ CÒN HIỆN, nên đây phải là
                        // `NOT EXISTS` chứ không phải join. Một INNER JOIN sang
                        // `post_branches` sẽ làm MỌI câu của HQ biến mất ở MỌI
                        // chi nhánh chưa từng tắt gì — tức là trang FAQ trống
                        // trơn ở 17 cửa hàng, và trống một cách im lặng.
                        $sub->orWhere(fn ($hq) => $hq
                            ->whereNull('branch_id')
                            ->whereNotExists(fn ($ex) => $ex
                                ->selectRaw('1')
                                ->from('post_branches')
                                ->whereColumn('post_branches.post_id', 'posts.id')
                                ->where('post_branches.branch_id', $branch->id)
                                ->where('post_branches.is_visible', false)
                            )
                        );
                    }
                }),
            )
            // Biết chi nhánh thì biết tổ chức — bịt luôn chỗ endpoint này chưa
            // bao giờ lọc theo tổ chức. Nhánh không có chi nhánh vẫn hở, cần
            // issue riêng: không suy ra được tổ chức từ một request rỗng.
            //
            // `branches` KHÔNG có cột `organization_id` — chỉ `console_organization_id`,
            // là id xuyên hệ thống do SSO ghi. Khoá ngoại của `posts` trỏ vào
            // `organizations.id` (khoá chính cục bộ), nên phải bắc cầu qua
            // `organizations`, không so thẳng hai cột khác nghĩa nhau.
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            // `whereDoesntHave` giữ lại cả bài KHÔNG có chuyên mục nào
            // (`category_id` nullable) — chỉ FAQ bị loại, không phải mọi bài
            // chưa phân loại.
            ->when($category === null, fn ($q) => $q->whereDoesntHave(
                'category',
                fn ($cq) => $cq->whereIn('slug', self::FEED_EXCLUDED_CATEGORY_SLUGS)
            ))
            ->with(['category:id,slug'])
            ->orderByDesc('is_pinned')
            // Câu riêng của chi nhánh đứng trước câu chung: nếu không, câu
            // riêng nằm lẫn giữa hai chục câu của HQ và coi như không tồn tại.
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $data = $posts->map(fn (Post $post) => [
            'id' => $post->id,
            'slug' => $post->slug,
            // #1504 — có đường lui theo ngôn ngữ: bài chỉ được nhập một thứ
            // tiếng (FAQ do người vận hành gõ tay) từng trả về null ở đây.
            'title' => $post->localizedTitle(),
            'excerpt' => $post->localizedExcerpt(),
            'cover_image_url' => $post->cover_image_url,
            'published_at' => $post->published_at?->toISOString(),
            'is_pinned' => $post->is_pinned,
            'category' => $post->category ? [
                'id' => $post->category->id,
                'slug' => $post->category->slug,
            ] : null,
            ...($withContent ? ['content' => $post->localizedContent()] : []),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Slug chi nhánh → model, hoặc null khi không truyền / không tìm thấy.
     *
     * Chi nhánh không hoạt động vẫn tra được: khách đang đứng ở trang của chi
     * nhánh đó thì họ cần đọc FAQ của nó, còn việc chi nhánh có nhận đơn hay
     * không là chuyện khác.
     */
    private function resolveBranch(mixed $slug): ?Branch
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Branch::query()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * `branches.console_organization_id` → `organizations.id`.
     *
     * Hai khái niệm id khác nhau và rất dễ lẫn: `console_organization_id` là
     * định danh xuyên hệ thống do SSO đồng bộ, còn `organizations.id` là khoá
     * chính CỤC BỘ mà mọi khoá ngoại tenant (kể cả `posts.organization_id`)
     * trỏ vào. So thẳng hai cột đó với nhau thì luôn ra rỗng.
     */
    private function organizationIdForBranch(Branch $branch): ?string
    {
        return Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');
    }

    /**
     * Một bài viết theo slug, kèm nội dung đầy đủ.
     *
     * Lặp lại đúng bộ điều kiện "đã xuất bản" của `index`: bài nháp hoặc bài
     * hẹn giờ chưa tới lúc phải là 404 với khách, chứ không phải xem được nếu
     * đoán trúng slug.
     */
    public function show(string $slug): JsonResponse
    {
        $post = Post::query()
            ->where('slug', $slug)
            ->where('status', PostStatusEnum::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with(['category:id,slug'])
            ->first();

        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        return response()->json([
            'data' => [
                'id' => $post->id,
                'slug' => $post->slug,
                'title' => $post->localizedTitle(),
                'excerpt' => $post->localizedExcerpt(),
                'content' => $post->localizedContent(),
                'cover_image_url' => $post->cover_image_url,
                'published_at' => $post->published_at?->toISOString(),
                'category' => $post->category ? [
                    'id' => $post->category->id,
                    'slug' => $post->category->slug,
                ] : null,
            ],
        ]);
    }
}
