<?php

/**
 * #1504 — HQ FAQ CRUD (`/hq/{brand}/faqs`).
 *
 * FAQ không phải bảng riêng: mỗi câu hỏi là một `posts` thuộc PostCategory
 * slug `faq`. Bộ test này ghim đúng những chỗ mà cách lưu đó có thể rò ra
 * ngoài — chuyên mục tự sinh, slug từ chữ Nhật, bài không-phải-FAQ phải 404,
 * và FAQ không được lọt vào carousel bài viết của trang chủ.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use App\Omnify\Enums\PostStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-faq',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/faqs";

    $this->actingAs($this->user);
});

/** Tạo nhanh một câu hỏi qua API — dùng lại chính đường ghi thật. */
function createFaq(array $payload = []): array
{
    return test()->postJson(test()->baseUrl, array_merge([
        'vi' => ['question' => 'Tôi tích điểm như thế nào?', 'answer' => 'Mỗi 100 yên được 1 điểm.'],
    ], $payload))->assertCreated()->json('data');
}

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('trả rỗng và KHÔNG tạo chuyên mục nào khi chưa có câu hỏi — GET không được ghi dữ liệu', function () {
        $this->getJson($this->baseUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        expect(PostCategory::where('organization_id', $this->orgId)->count())->toBe(0);
    });

    it('đưa câu hỏi được ghim lên đầu, phần còn lại mới nhất trước', function () {
        createFaq(['vi' => ['question' => 'Câu cũ', 'answer' => 'A']]);
        $this->travel(1)->minutes();
        createFaq(['vi' => ['question' => 'Câu mới', 'answer' => 'B']]);
        $this->travel(1)->minutes();
        $pinned = createFaq(['vi' => ['question' => 'Câu ghim', 'answer' => 'C'], 'is_pinned' => true]);

        $questions = collect($this->getJson($this->baseUrl)->assertOk()->json('data'))
            ->pluck('question')
            ->all();

        expect($questions)->toBe(['Câu ghim', 'Câu mới', 'Câu cũ'])
            ->and($pinned['is_pinned'])->toBeTrue();
    });

    it('liệt kê cả câu hỏi đang tắt hiển thị — admin phải thấy thứ khách không thấy', function () {
        createFaq(['vi' => ['question' => 'Đang ẩn', 'answer' => 'A'], 'is_published' => false]);

        $data = $this->getJson($this->baseUrl)->assertOk()->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['is_published'])->toBeFalse();
    });

    it('không trả câu hỏi của tổ chức khác', function () {
        createFaq();

        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);
        grantOrgAccess($outsider, $otherOrgId);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-faq',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/hq/{$otherBrand->slug}/faqs")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('tự tạo chuyên mục `faq` kèm tên 3 ngôn ngữ ở lần ghi đầu tiên', function () {
        createFaq();

        $category = PostCategory::where('organization_id', $this->orgId)
            ->where('slug', 'faq')
            ->first();

        expect($category)->not->toBeNull()
            ->and($category->translate('ja')?->name)->toBe('よくある質問')
            ->and($category->translate('en')?->name)->toBe('FAQ')
            ->and($category->translate('vi')?->name)->toBe('Câu hỏi thường gặp');
    });

    it('dùng lại chuyên mục đã có thay vì tạo cái thứ hai', function () {
        createFaq(['vi' => ['question' => 'Một', 'answer' => 'A']]);
        createFaq(['vi' => ['question' => 'Hai', 'answer' => 'B']]);

        expect(PostCategory::where('organization_id', $this->orgId)->where('slug', 'faq')->count())->toBe(1);
    });

    it('lưu đủ 3 ngôn ngữ và mặc định là đang hiển thị', function () {
        $faq = createFaq([
            'ja' => ['question' => 'ポイントの貯め方は？', 'answer' => '100円ごとに1ポイント。'],
            'en' => ['question' => 'How do I earn points?', 'answer' => '1 point per 100 yen.'],
            'vi' => ['question' => 'Tôi tích điểm thế nào?', 'answer' => 'Mỗi 100 yên 1 điểm.'],
        ]);

        expect($faq['translations']['ja']['question'])->toBe('ポイントの貯め方は？')
            ->and($faq['translations']['en']['answer'])->toBe('1 point per 100 yen.')
            ->and($faq['translations']['vi']['question'])->toBe('Tôi tích điểm thế nào?')
            ->and($faq['is_published'])->toBeTrue()
            ->and($faq['is_pinned'])->toBeFalse();
    });

    it('sinh slug không rỗng khi câu hỏi chỉ có chữ Nhật — Str::slug trả rỗng mà cột là NOT NULL', function () {
        createFaq(['ja' => ['question' => 'ポイントの貯め方は？', 'answer' => 'A'], 'vi' => null]);

        $slug = Post::where('organization_id', $this->orgId)->value('slug');

        expect($slug)->not->toBeEmpty();
    });

    it('không đụng slug của nhau khi hai câu hỏi cho ra cùng một slug gốc', function () {
        createFaq(['vi' => ['question' => 'Câu hỏi trùng', 'answer' => 'A']]);
        createFaq(['vi' => ['question' => 'Câu hỏi trùng', 'answer' => 'B']]);

        expect(Post::where('organization_id', $this->orgId)->distinct()->count('slug'))->toBe(2);
    });

    it('từ chối khi không có câu hỏi ở ngôn ngữ nào', function () {
        $this->postJson($this->baseUrl, ['vi' => ['answer' => 'Chỉ có câu trả lời']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ja.question');

        expect(Post::count())->toBe(0);
    });

    it('KHÔNG tạo dòng dịch cho ngôn ngữ chỉ gửi câu trả lời — post_translations.title là NOT NULL', function () {
        $faq = createFaq([
            'vi' => ['question' => 'Có câu hỏi', 'answer' => 'A'],
            'en' => ['answer' => 'Answer with no question'],
        ]);

        expect($faq['translations']['en']['question'])->toBeNull()
            ->and($faq['translations']['en']['answer'])->toBeNull()
            ->and($faq['translations']['vi']['question'])->toBe('Có câu hỏi');
    });

    it('bỏ qua slug/category_id/status do client cố nhét vào', function () {
        $this->postJson($this->baseUrl, [
            'vi' => ['question' => 'Câu hỏi', 'answer' => 'A'],
            'slug' => 'client-chosen-slug',
            'status' => 'Archived',
            'category_id' => (string) Str::uuid(),
            'view_count' => 999,
        ])->assertCreated();

        $post = Post::where('organization_id', $this->orgId)->first();

        expect($post->slug)->not->toBe('client-chosen-slug')
            ->and($post->status)->toBe(PostStatusEnum::Published)
            ->and($post->view_count)->toBe(0)
            ->and($post->category->slug)->toBe('faq');
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('sửa được một ngôn ngữ mà không đụng ngôn ngữ khác', function () {
        $faq = createFaq([
            'vi' => ['question' => 'Câu tiếng Việt', 'answer' => 'A'],
            'en' => ['question' => 'English question', 'answer' => 'B'],
        ]);

        $updated = $this->patchJson("{$this->baseUrl}/{$faq['id']}", [
            'vi' => ['answer' => 'Câu trả lời mới'],
        ])->assertOk()->json('data');

        expect($updated['translations']['vi']['answer'])->toBe('Câu trả lời mới')
            ->and($updated['translations']['vi']['question'])->toBe('Câu tiếng Việt')
            ->and($updated['translations']['en']['question'])->toBe('English question');
    });

    it('tắt hiển thị thì khách không còn thấy, admin thì vẫn', function () {
        $faq = createFaq();

        $this->patchJson("{$this->baseUrl}/{$faq['id']}", ['is_published' => false])
            ->assertOk()
            ->assertJsonPath('data.is_published', false);

        $this->getJson('/api/v1/customer/posts?category=faq&with_content=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson($this->baseUrl)->assertOk()->assertJsonCount(1, 'data');
    });

    it('bật lại KHÔNG dập lại published_at — thứ tự FAQ của khách không bị xáo', function () {
        $faq = createFaq();
        $originalPublishedAt = Post::find($faq['id'])->published_at;

        $this->travel(2)->days();
        $this->patchJson("{$this->baseUrl}/{$faq['id']}", ['is_published' => false])->assertOk();
        $this->patchJson("{$this->baseUrl}/{$faq['id']}", ['is_published' => true])->assertOk();

        expect(Post::find($faq['id'])->published_at->timestamp)->toBe($originalPublishedAt->timestamp);
    });

    it('câu hỏi rỗng là "không đổi", không phải "xoá" — cột title NOT NULL', function () {
        $faq = createFaq(['vi' => ['question' => 'Giữ nguyên', 'answer' => 'A']]);

        $updated = $this->patchJson("{$this->baseUrl}/{$faq['id']}", [
            'vi' => ['question' => '   '],
        ])->assertOk()->json('data');

        expect($updated['translations']['vi']['question'])->toBe('Giữ nguyên');
    });

    it('trả 404 cho bài viết KHÔNG phải FAQ — không biến endpoint này thành máy dò id bài viết', function () {
        $newsCategory = PostCategory::factory()->create([
            'organization_id' => $this->orgId,
            'slug' => 'news',
        ]);
        $news = Post::factory()->create([
            'organization_id' => $this->orgId,
            'category_id' => $newsCategory->id,
            'slug' => 'a-news-post',
        ]);

        $this->patchJson("{$this->baseUrl}/{$news->id}", ['is_pinned' => true])->assertNotFound();
        $this->deleteJson("{$this->baseUrl}/{$news->id}")->assertNotFound();

        expect(Post::find($news->id))->not->toBeNull();
    });

    it('trả 404 cho câu hỏi của tổ chức khác', function () {
        $faq = createFaq();

        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);
        grantOrgAccess($outsider, $otherOrgId);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-faq-2',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->patchJson("/api/v1/hq/{$otherBrand->slug}/faqs/{$faq['id']}", ['is_pinned' => true])
            ->assertNotFound();
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('xoá mềm và biến khỏi cả admin lẫn trang khách', function () {
        $faq = createFaq();

        $this->deleteJson("{$this->baseUrl}/{$faq['id']}")->assertNoContent();

        $this->getJson($this->baseUrl)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/posts?category=faq&with_content=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        expect(Post::withTrashed()->find($faq['id']))->not->toBeNull();
    });

    it('slug của câu đã xoá không chặn được câu mới — unique index vẫn tính dòng soft-deleted', function () {
        $faq = createFaq(['vi' => ['question' => 'Câu hỏi trùng slug', 'answer' => 'A']]);
        $this->deleteJson("{$this->baseUrl}/{$faq['id']}")->assertNoContent();

        $this->postJson($this->baseUrl, [
            'vi' => ['question' => 'Câu hỏi trùng slug', 'answer' => 'B'],
        ])->assertCreated();
    });
});

// =========================================================================
//  Giao diện khách
// =========================================================================

describe('customer-facing surface', function () {
    it('câu hỏi vừa tạo hiện ngay ở /customer/posts?category=faq kèm nội dung', function () {
        createFaq(['vi' => ['question' => 'Điểm có hết hạn không?', 'answer' => 'Có, sau 12 tháng.']]);

        $data = $this->getJson('/api/v1/customer/posts?category=faq&with_content=1')
            ->assertOk()
            ->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['content'])->toBe('Có, sau 12 tháng.');
    });

    it('FAQ KHÔNG lọt vào carousel bài viết của trang chủ', function () {
        createFaq(['vi' => ['question' => 'Câu hỏi FAQ', 'answer' => 'A']]);

        $newsCategory = PostCategory::factory()->create([
            'organization_id' => $this->orgId,
            'slug' => 'news',
        ]);
        Post::factory()->create([
            'organization_id' => $this->orgId,
            'category_id' => $newsCategory->id,
            'slug' => 'real-news',
            'status' => PostStatusEnum::Published,
            'published_at' => now()->subDay(),
        ]);

        $slugs = collect($this->getJson('/api/v1/customer/posts?limit=20')->assertOk()->json('data'))
            ->pluck('category.slug')
            ->all();

        expect($slugs)->not->toContain('faq')
            ->and($slugs)->toContain('news');
    });

    it('bài KHÔNG có chuyên mục vẫn nằm trong carousel — chỉ FAQ bị loại', function () {
        Post::factory()->create([
            'organization_id' => $this->orgId,
            'category_id' => null,
            'slug' => 'uncategorised',
            'status' => PostStatusEnum::Published,
            'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/customer/posts?limit=20')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
