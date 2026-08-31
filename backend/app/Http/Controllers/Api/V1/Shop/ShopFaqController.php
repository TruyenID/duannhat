<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Controllers\Traits\PostFaqCrud;
use App\Http\Requests\PostFaqStoreRequest;
use App\Http\Requests\PostFaqUpdateRequest;
use App\Models\Branch;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #1673 — CRUD Câu hỏi thường gặp RIÊNG của một chi nhánh.
 *
 * Cùng bộ luật với `HQ\FaqController`, chỉ khác phạm vi: ở đây mọi câu hỏi
 * mang `branch_id = <chi nhánh trên URL>`. Luật dùng chung nằm ở trait
 * `PostFaqCrud` để hai cấp không trôi ra hai cách hiểu khác nhau về "câu hỏi
 * rỗng nghĩa là gì" hay "bật lại thì có dập published_at không".
 *
 * `index` trả về CẢ câu kế thừa từ HQ, đánh dấu `is_inherited: true` và chỉ để
 * đọc: người quản chi nhánh cần thấy đúng thứ khách đang đọc, chứ không phải
 * một danh sách rỗng trong khi trang FAQ của khách có 20 câu. Ghi/sửa/xoá chỉ
 * chạm được câu của chính chi nhánh — câu HQ trả 404 (xem `authorizeFaqInScope`).
 *
 * Công tắc kế thừa (`branches.faq_inherit_hq`) KHÔNG đặt ở đây mà ở
 * `PATCH /shops/{shop}/settings/branch` cùng với các cài đặt chi nhánh khác.
 */
class ShopFaqController extends Controller
{
    use HasOrganizationContext;
    use PostFaqCrud;

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/faqs',
        summary: 'List this branch FAQ entries (own + inherited)',
        description: 'Own entries first, then HQ entries when faq_inherit_hq is on. Inherited rows carry is_inherited=true and are read-only here.',
        tags: ['ShopFaqs'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of FAQ entries'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $organizationId = $this->getOrganizationId();

        $own = $this->faqsInScope($organizationId, $shop->id)
            ->map(fn (Post $faq): array => $this->toFaqArray($faq) + [
                'is_inherited' => false,
                // Câu riêng đã có `is_published` của chính nó; `is_visible`
                // chỉ có nghĩa với câu đi mượn (BR-FB04).
                'is_visible' => true,
            ]);

        // Câu của HQ: nội dung chỉ đọc, nhưng #1684 cho chi nhánh tắt/bật TỪNG
        // câu. Trả kèm để màn hình chi nhánh phản chiếu đúng trang FAQ của khách.
        $hidden = $shop->faq_inherit_hq ? $this->hiddenHqFaqIds($shop->id) : [];

        $inherited = $shop->faq_inherit_hq
            ? $this->faqsInScope($organizationId, null)
                ->map(fn (Post $faq): array => $this->toFaqArray($faq) + [
                    'is_inherited' => true,
                    // BR-FB01 — không có dòng ⇒ còn hiện.
                    'is_visible' => ! in_array($faq->id, $hidden, true),
                ])
            : collect();

        return response()->json([
            'data' => $own->concat($inherited)->values()->all(),
            'inherit_hq' => (bool) $shop->faq_inherit_hq,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/faqs',
        summary: 'Create a FAQ entry owned by this branch',
        tags: ['ShopFaqs'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ja', type: 'object', nullable: true),
                    new OA\Property(property: 'en', type: 'object', nullable: true),
                    new OA\Property(property: 'vi', type: 'object', nullable: true),
                    new OA\Property(property: 'is_published', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_pinned', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'FAQ entry created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(PostFaqStoreRequest $request): JsonResponse
    {
        $shop = $this->shop($request);

        // Chi nhánh lấy từ URL, KHÔNG từ client — nếu không thì một chi nhánh
        // ghi được câu hỏi vào chi nhánh khác chỉ bằng cách thêm một trường.
        $faq = $this->faqService()->create(
            $this->getOrganizationId(),
            branchId: $shop->id,
            authorId: $request->user()?->getKey(),
            data: $request->validated(),
        );

        return response()->json(
            ['data' => $this->toFaqArray($faq->fresh(['translations'])) + ['is_inherited' => false]],
            201,
        );
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/faqs/{faq}',
        summary: 'Update a FAQ entry owned by this branch',
        description: 'A HQ entry, or another branch entry, returns 404 — a branch cannot edit what it only inherits.',
        tags: ['ShopFaqs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(PostFaqUpdateRequest $request, Post $faq): JsonResponse
    {
        $shop = $this->shop($request);
        $this->authorizeFaqInScope($faq, $this->getOrganizationId(), $shop->id);

        $this->faqService()->update($faq, $request->validated());

        return response()->json([
            'data' => $this->toFaqArray($faq->fresh(['translations'])) + ['is_inherited' => false],
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/faqs/{faq}',
        summary: 'Delete a FAQ entry owned by this branch',
        tags: ['ShopFaqs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, Post $faq): JsonResponse
    {
        $shop = $this->shop($request);
        $this->authorizeFaqInScope($faq, $this->getOrganizationId(), $shop->id);

        $this->faqService()->delete($faq);

        return response()->json(null, 204);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/faqs/{faq}/visibility',
        summary: 'Show or hide ONE inherited HQ FAQ entry for this branch',
        description: 'Only HQ entries (branch_id IS NULL) have this switch — a branch own entry already has is_published. No row means visible, so turning it back on deletes nothing: it writes is_visible=true to keep who/when.',
        tags: ['ShopFaqs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'is_visible', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Switch applied'),
            new OA\Response(response: 404, description: 'Not an inherited HQ FAQ entry of this organization'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function visibility(Request $request, Post $faq): JsonResponse
    {
        $shop = $this->shop($request);

        // BR-FB04 — công tắc này CHỈ áp cho câu cấp tổ chức, nên phạm vi kiểm
        // là `null` chứ không phải `$shop->id`. Câu riêng của chi nhánh đi qua
        // đây sẽ 404: nó đã có `is_published` của chính nó, và hai công tắc cho
        // cùng một việc là chỗ để chúng lệch nhau về sau.
        $this->authorizeFaqInScope($faq, $this->getOrganizationId(), null);

        $value = $request->input('is_visible');

        if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => ['is_visible' => ['The is visible field must be true or false.']],
            ], 422);
        }

        $isVisible = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        // Bật lại KHÔNG xoá dòng mà ghi `is_visible = true`: xoá là mất luôn
        // `toggled_by_id`/`updated_at`, đúng thứ HQ sẽ hỏi khi một câu quan
        // trọng không hiện ở một cửa hàng.
        $this->faqService()->setInheritedVisibility(
            $faq,
            $shop->id,
            $isVisible,
            $request->user()?->getKey(),
        );

        return response()->json([
            'data' => $this->toFaqArray($faq->fresh(['translations'])) + [
                'is_inherited' => true,
                'is_visible' => $isVisible,
            ],
        ]);
    }

    /**
     * Chi nhánh do `ResolveShopFromSlug` gắn lên request. Không nhận từ body
     * hay query — đó là ranh giới duy nhất ngăn một chi nhánh ghi sang chi
     * nhánh khác.
     */
    private function shop(Request $request): Branch
    {
        /** @var Branch|null $shop */
        $shop = $request->attributes->get('shop');

        if ($shop === null) {
            abort(404, 'Shop not found.');
        }

        return $shop;
    }
}
