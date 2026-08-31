<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Controllers\Traits\PostFaqCrud;
use App\Http\Requests\PostFaqStoreRequest;
use App\Http\Requests\PostFaqUpdateRequest;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * #1504 — CRUD Câu hỏi thường gặp cấp HQ. #1673 — thu hẹp về `branch_id IS NULL`.
 *
 * Cố ý KHÔNG phải CRUD bài viết chung: một câu hỏi là một dòng `posts` thuộc
 * PostCategory slug `faq`, nhưng payload chỉ nói `question` / `answer` /
 * `is_published` / `is_pinned`. Người vận hành không cần biết FAQ được lưu
 * bằng bảng bài viết, và cũng không có đường nào từ đây chạm tới bài
 * news/promotion.
 *
 * Ở đây chỉ quản câu hỏi **cấp tổ chức** (`branch_id IS NULL`). Câu riêng của
 * từng chi nhánh nằm ở `/shops/{shop}/faqs` (`ShopFaqController`). Chi nhánh
 * chọn có đọc kèm bộ này hay không bằng `branches.faq_inherit_hq`.
 *
 * Phạm vi là ORGANIZATION, không phải brand: `posts` không có `brand_id`.
 * Xem docblock ở `routes/api/hq/faqs.php`.
 */
class FaqController extends Controller
{
    use HasOrganizationContext;
    use PostFaqCrud;

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/faqs',
        summary: 'List organization-wide FAQ entries',
        description: 'FAQ entries owned by the organization (branch_id IS NULL) — published AND unpublished. Branch-owned entries are NOT included.',
        tags: ['Faqs'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of FAQ entries'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(): JsonResponse
    {
        $faqs = $this->faqsInScope($this->getOrganizationId(), null);

        return response()->json([
            'data' => $faqs->map(fn (Post $faq): array => $this->toFaqArray($faq))->all(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/faqs',
        summary: 'Create an organization-wide FAQ entry',
        description: 'Question required in at least one language. Defaults to published. Seen by every branch whose faq_inherit_hq is on.',
        tags: ['Faqs'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
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
        // branchId NULL = câu của toàn tổ chức. Client không có đường nào đặt
        // được cột này — muốn câu riêng chi nhánh thì dùng `/shops/{shop}/faqs`.
        $faq = $this->faqService()->create(
            $this->getOrganizationId(),
            branchId: null,
            authorId: $request->user()?->getKey(),
            data: $request->validated(),
        );

        return response()->json(
            ['data' => $this->toFaqArray($faq->fresh(['translations']))],
            201,
        );
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/faqs/{faq}',
        summary: 'Update an organization-wide FAQ entry',
        description: 'Partial update. Omitted languages untouched; slug never changes. A branch-owned entry returns 404 here.',
        tags: ['Faqs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
        $this->authorizeFaqInScope($faq, $this->getOrganizationId(), null);

        $this->faqService()->update($faq, $request->validated());

        return response()->json(['data' => $this->toFaqArray($faq->fresh(['translations']))]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/faqs/{faq}',
        summary: 'Delete an organization-wide FAQ entry',
        description: 'Soft delete — nothing references a FAQ entry historically, so removing it is safe.',
        tags: ['Faqs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Post $faq): JsonResponse
    {
        $this->authorizeFaqInScope($faq, $this->getOrganizationId(), null);

        $this->faqService()->delete($faq);

        return response()->json(null, 204);
    }
}
