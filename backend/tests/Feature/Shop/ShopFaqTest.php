<?php

/**
 * #1673 — FAQ theo chi nhánh, kế thừa từ HQ có công tắc bật/tắt.
 *
 * Bộ test này ghim đúng những chỗ mô hình hai cấp có thể rò: HQ nhìn thấy câu
 * của chi nhánh, chi nhánh sửa được câu nó chỉ kế thừa, chi nhánh A đụng được
 * chi nhánh B, và trang khách trả sai bộ câu hỏi khi công tắc tắt.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->consoleOrgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'acme-faq-branch',
        'is_active' => true,
    ]);

    $this->shopA = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'shop-a',
        'is_active' => true,
    ]);

    $this->shopB = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'shop-b',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    grantOrgAccess($this->user, $this->org->id);

    $this->hqUrl = "/api/v1/hq/{$this->brand->slug}/faqs";
    $this->shopAUrl = "/api/v1/shops/{$this->shopA->slug}/faqs";
    $this->shopBUrl = "/api/v1/shops/{$this->shopB->slug}/faqs";

    $this->actingAs($this->user);
});

// `makeFaq()` / `setInherit()` sống ở `tests/Pest.php` từ #1684 — file
// `ShopFaqVisibilityTest` cũng cần chúng, mà hàm khai trong một file test chỉ
// tồn tại khi file ĐÓ được nạp: chạy riêng file kia sẽ chết "undefined function".

// =========================================================================
//  Mặc định sau migration
// =========================================================================

it('bật kế thừa theo mặc định — migration không được làm trống trang FAQ đang chạy', function () {
    expect($this->shopA->fresh()->faq_inherit_hq)->toBeTrue()
        ->and($this->shopB->fresh()->faq_inherit_hq)->toBeTrue();
});

// =========================================================================
//  Cách ly hai cấp
// =========================================================================

describe('cách ly HQ ↔ chi nhánh', function () {
    it('HQ KHÔNG thấy câu của chi nhánh trong danh sách của mình', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $questions = collect($this->getJson($this->hqUrl)->assertOk()->json('data'))
            ->pluck('question')->all();

        expect($questions)->toBe(['Câu của HQ']);
    });

    it('HQ không sửa/xoá được câu của chi nhánh — 404', function () {
        $shopFaq = makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $this->patchJson("{$this->hqUrl}/{$shopFaq['id']}", ['is_pinned' => true])->assertNotFound();
        $this->deleteJson("{$this->hqUrl}/{$shopFaq['id']}")->assertNotFound();

        expect(Post::find($shopFaq['id']))->not->toBeNull();
    });

    it('chi nhánh không sửa/xoá được câu nó chỉ KẾ THỪA từ HQ — 404', function () {
        $hqFaq = makeFaq($this->hqUrl, 'Câu của HQ');

        $this->patchJson("{$this->shopAUrl}/{$hqFaq['id']}", ['is_pinned' => true])->assertNotFound();
        $this->deleteJson("{$this->shopAUrl}/{$hqFaq['id']}")->assertNotFound();

        expect(Post::find($hqFaq['id']))->not->toBeNull();
    });

    it('chi nhánh A không đụng được câu của chi nhánh B — 404', function () {
        $faqB = makeFaq($this->shopBUrl, 'Câu riêng của shop B');

        $this->patchJson("{$this->shopAUrl}/{$faqB['id']}", ['is_pinned' => true])->assertNotFound();
        $this->deleteJson("{$this->shopAUrl}/{$faqB['id']}")->assertNotFound();
    });

    it('chi nhánh không ghi được câu sang chi nhánh khác dù client cố nhét branch_id', function () {
        $this->postJson($this->shopAUrl, [
            'vi' => ['question' => 'Cố nhét', 'answer' => 'x'],
            'branch_id' => $this->shopB->id,
        ])->assertCreated();

        $post = Post::where('branch_id', $this->shopA->id)->first();

        expect($post)->not->toBeNull()
            ->and(Post::where('branch_id', $this->shopB->id)->count())->toBe(0);
    });
});

// =========================================================================
//  Danh sách phía quản trị chi nhánh
// =========================================================================

describe('danh sách của chi nhánh', function () {
    it('gồm câu riêng TRƯỚC, rồi câu kế thừa — và đánh dấu rõ cái nào kế thừa', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $body = $this->getJson($this->shopAUrl)->assertOk()->json();

        expect(collect($body['data'])->pluck('question')->all())
            ->toBe(['Câu riêng của shop A', 'Câu của HQ'])
            ->and($body['data'][0]['is_inherited'])->toBeFalse()
            ->and($body['data'][1]['is_inherited'])->toBeTrue()
            ->and($body['inherit_hq'])->toBeTrue();
    });

    it('tắt kế thừa thì câu HQ biến khỏi danh sách của chi nhánh', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        setInherit($this->shopA, false);

        $body = $this->getJson($this->shopAUrl)->assertOk()->json();

        expect(collect($body['data'])->pluck('question')->all())->toBe(['Câu riêng của shop A'])
            ->and($body['inherit_hq'])->toBeFalse();
    });

    it('tắt kế thừa ở chi nhánh A KHÔNG ảnh hưởng chi nhánh B', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        setInherit($this->shopA, false);

        expect($this->getJson($this->shopAUrl)->assertOk()->json('data'))->toHaveCount(0)
            ->and($this->getJson($this->shopBUrl)->assertOk()->json('data'))->toHaveCount(1);
    });
});

// =========================================================================
//  Công tắc kế thừa qua endpoint cài đặt
// =========================================================================

describe('công tắc kế thừa', function () {
    it('đọc lại được ở GET settings/branch', function () {
        setInherit($this->shopA, false);

        $this->getJson("/api/v1/shops/{$this->shopA->slug}/settings/branch")
            ->assertOk()
            ->assertJsonPath('data.faq_inherit_hq', false);
    });

    it('từ chối giá trị không phải boolean', function () {
        $this->patchJson("/api/v1/shops/{$this->shopA->slug}/settings/branch", [
            'faq_inherit_hq' => 'maybe',
        ])->assertStatus(422)->assertJsonValidationErrors('faq_inherit_hq');

        expect($this->shopA->fresh()->faq_inherit_hq)->toBeTrue();
    });
});

// =========================================================================
//  Trang khách
// =========================================================================

describe('giao diện khách', function () {
    it('kèm ?branch → câu riêng chi nhánh TRƯỚC câu HQ', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $titles = collect(
            $this->getJson("/api/v1/customer/posts?category=faq&with_content=1&branch={$this->shopA->slug}")
                ->assertOk()->json('data')
        )->pluck('title')->all();

        expect($titles)->toBe(['Câu riêng của shop A', 'Câu của HQ']);
    });

    it('tắt kế thừa → khách chỉ thấy câu riêng của chi nhánh', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');
        setInherit($this->shopA, false);

        $titles = collect(
            $this->getJson("/api/v1/customer/posts?category=faq&with_content=1&branch={$this->shopA->slug}")
                ->assertOk()->json('data')
        )->pluck('title')->all();

        expect($titles)->toBe(['Câu riêng của shop A']);
    });

    it('khách ở chi nhánh B KHÔNG thấy câu riêng của chi nhánh A', function () {
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');
        makeFaq($this->shopBUrl, 'Câu riêng của shop B');

        $titles = collect(
            $this->getJson("/api/v1/customer/posts?category=faq&with_content=1&branch={$this->shopB->slug}")
                ->assertOk()->json('data')
        )->pluck('title')->all();

        expect($titles)->toBe(['Câu riêng của shop B']);
    });

    it('KHÔNG truyền branch → chỉ câu cấp tổ chức, giữ nguyên hành vi bản đang chạy', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $titles = collect(
            $this->getJson('/api/v1/customer/posts?category=faq&with_content=1')
                ->assertOk()->json('data')
        )->pluck('title')->all();

        expect($titles)->toBe(['Câu của HQ']);
    });

    it('slug chi nhánh lạ coi như không truyền, KHÔNG phải 404 — endpoint công khai', function () {
        makeFaq($this->hqUrl, 'Câu của HQ');

        $this->getJson('/api/v1/customer/posts?category=faq&with_content=1&branch=khong-ton-tai')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('câu chi nhánh đang tắt hiển thị thì khách không thấy', function () {
        $faq = makeFaq($this->shopAUrl, 'Câu riêng của shop A');
        $this->patchJson("{$this->shopAUrl}/{$faq['id']}", ['is_published' => false])->assertOk();

        $this->getJson("/api/v1/customer/posts?category=faq&with_content=1&branch={$this->shopA->slug}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('câu ghim vẫn lên đầu, kể cả khi câu đó là của HQ', function () {
        makeFaq($this->hqUrl, 'Câu HQ được ghim', ['is_pinned' => true]);
        makeFaq($this->shopAUrl, 'Câu riêng của shop A');

        $titles = collect(
            $this->getJson("/api/v1/customer/posts?category=faq&with_content=1&branch={$this->shopA->slug}")
                ->assertOk()->json('data')
        )->pluck('title')->all();

        expect($titles)->toBe(['Câu HQ được ghim', 'Câu riêng của shop A']);
    });
});
