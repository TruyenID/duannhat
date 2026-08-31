<?php

/**
 * #1684 — chi nhánh tắt/bật TỪNG câu hỏi kế thừa từ HQ.
 *
 * #1673 chỉ có công tắc cả cụm; bộ test này ghim tầng mịn hơn nằm trên nó.
 *
 * Chỗ dễ hỏng nhất và là lý do file này tồn tại: **không có dòng pivot ⇒ CÒN
 * HIỆN** (BR-FB01). Viết truy vấn bằng INNER JOIN thay vì NOT EXISTS thì mọi
 * câu của HQ biến mất ở mọi chi nhánh chưa từng bấm gì — 17 cửa hàng có trang
 * FAQ trống trơn, và trống một cách im lặng vì không ai báo lỗi. Test đầu tiên
 * dưới đây tồn tại chỉ để bắt đúng lần hồi quy đó.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PostBranch;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->consoleOrgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'acme-faq-visibility',
        'is_active' => true,
    ]);

    $this->shopA = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'vis-shop-a',
        'is_active' => true,
    ]);

    $this->shopB = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'vis-shop-b',
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

    // Hai câu của HQ, không chi nhánh nào bấm gì — trạng thái xuất phát thật
    // của 17 cửa hàng ngay sau khi migration chạy.
    $this->hqOne = makeFaq($this->hqUrl, 'Giờ mở cửa thế nào?');
    $this->hqTwo = makeFaq($this->hqUrl, 'Có chỗ đậu xe không?');
});

/** Đọc danh sách FAQ của một chi nhánh, trả về map slug/id → dòng. */
function faqRows(string $shopUrl): array
{
    $rows = test()->getJson($shopUrl)->assertOk()->json('data');

    return collect($rows)->keyBy('id')->all();
}

/** Bấm công tắc ẩn/hiện một câu HQ cho một chi nhánh. */
function setVisible(string $shopUrl, string $faqId, bool $visible)
{
    return test()->patchJson("{$shopUrl}/{$faqId}/visibility", ['is_visible' => $visible]);
}

// =============================================================================
//  BR-FB01 — không có dòng ⇒ còn hiện
// =============================================================================

describe('mặc định khi chưa ai bấm gì', function () {
    it('chi nhánh chưa từng tắt câu nào thì thấy ĐỦ câu của HQ', function () {
        $rows = faqRows($this->shopAUrl);

        expect($rows)->toHaveCount(2)
            ->and($rows[$this->hqOne['id']]['is_visible'])->toBeTrue()
            ->and($rows[$this->hqTwo['id']]['is_visible'])->toBeTrue();

        // Và trang khách cũng vậy — đây mới là chỗ khách nhìn thấy.
        $customer = test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")
            ->assertOk()->json('data');

        expect($customer)->toHaveCount(2);
    });

    it('KHÔNG sinh dòng pivot nào khi chỉ đọc danh sách', function () {
        faqRows($this->shopAUrl);
        test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")->assertOk();

        // Bật một câu mới cho 17 chi nhánh phải là 0 dòng ghi, không phải
        // backfill 17 dòng — đó là lý do chọn "không dòng = hiện".
        expect(PostBranch::query()->count())->toBe(0);
    });
});

// =============================================================================
//  Tắt / bật
// =============================================================================

describe('công tắc từng câu', function () {
    it('tắt một câu thì câu đó biến khỏi trang khách, câu còn lại ở nguyên', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        $customer = collect(
            test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")
                ->assertOk()->json('data')
        );

        expect($customer)->toHaveCount(1)
            ->and($customer->pluck('id')->all())->toBe([$this->hqTwo['id']]);
    });

    it('câu bị tắt VẪN hiện ở màn quản trị, đánh dấu đang tắt', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        $rows = faqRows($this->shopAUrl);

        // Người quản chi nhánh phải thấy câu mình vừa tắt để còn bật lại được;
        // biến mất khỏi màn quản trị là không có đường quay lại.
        expect($rows)->toHaveCount(2)
            ->and($rows[$this->hqOne['id']]['is_visible'])->toBeFalse()
            ->and($rows[$this->hqOne['id']]['is_inherited'])->toBeTrue();
    });

    it('bật lại thì câu trở lại trang khách', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();
        setVisible($this->shopAUrl, $this->hqOne['id'], true)->assertOk();

        $customer = test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")
            ->assertOk()->json('data');

        expect($customer)->toHaveCount(2);
    });

    it('bật lại GIỮ dòng chứ không xoá — còn dấu vết ai tắt lúc nào', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();
        setVisible($this->shopAUrl, $this->hqOne['id'], true)->assertOk();

        $row = PostBranch::query()
            ->where('post_id', $this->hqOne['id'])
            ->where('branch_id', $this->shopA->id)
            ->first();

        expect($row)->not->toBeNull()
            ->and((bool) $row->is_visible)->toBeTrue();
    });

    it('ghi lại NGƯỜI bấm công tắc', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        $row = PostBranch::query()
            ->where('post_id', $this->hqOne['id'])
            ->where('branch_id', $this->shopA->id)
            ->first();

        // #1689 — cột này điền TAY ở service, không còn hook auto-fill của
        // `options.audit` (audit sinh cột bigint trong khi users.id là UUID).
        // Quên truyền vào thì nó lặng lẽ null, nên phải có test.
        expect($row->toggled_by_id)->toBe($this->user->getKey());
    });

    it('bấm tắt hai lần không sinh dòng thứ hai', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        expect(PostBranch::query()
            ->where('post_id', $this->hqOne['id'])
            ->where('branch_id', $this->shopA->id)
            ->count())->toBe(1);
    });

    it('từ chối giá trị không phải boolean', function () {
        test()->patchJson("{$this->shopAUrl}/{$this->hqOne['id']}/visibility", ['is_visible' => 'có'])
            ->assertStatus(422);
    });
});

// =============================================================================
//  Cách ly giữa các chi nhánh
// =============================================================================

describe('cách ly', function () {
    it('chi nhánh A tắt một câu KHÔNG ảnh hưởng chi nhánh B', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        $customerB = test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopB->slug}")
            ->assertOk()->json('data');

        expect($customerB)->toHaveCount(2);

        $rowsB = faqRows($this->shopBUrl);
        expect($rowsB[$this->hqOne['id']]['is_visible'])->toBeTrue();
    });

    it('chi nhánh A không bấm được công tắc thay chi nhánh B', function () {
        // Đường duy nhất để chỉ định chi nhánh là URL; không có trường nào
        // trong body nói được "làm hộ chi nhánh khác".
        setVisible($this->shopAUrl, $this->hqOne['id'], false)->assertOk();

        expect(PostBranch::query()->where('branch_id', $this->shopB->id)->count())->toBe(0);
    });
});

// =============================================================================
//  BR-FB02 / BR-FB03 / BR-FB04 — cấp trên luôn thắng
// =============================================================================

describe('thứ tự ưu tiên', function () {
    it('BR-FB02: tắt kế thừa cả cụm thì công tắc từng câu không còn nghĩa', function () {
        setVisible($this->shopAUrl, $this->hqOne['id'], true)->assertOk();
        setInherit($this->shopA, false);

        $customer = test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")
            ->assertOk()->json('data');

        expect($customer)->toHaveCount(0);

        // Màn quản trị cũng không liệt câu HQ nữa.
        expect(faqRows($this->shopAUrl))->toHaveCount(0);
    });

    it('BR-FB03: câu HQ đang ẩn với khách thì chi nhánh bật cũng không hiện', function () {
        $hidden = makeFaq($this->hqUrl, 'Câu HQ đang ẩn', ['is_published' => false]);

        setVisible($this->shopAUrl, $hidden['id'], true)->assertOk();

        $customer = collect(
            test()->getJson("/api/v1/customer/posts?category=faq&branch={$this->shopA->slug}")
                ->assertOk()->json('data')
        );

        expect($customer->pluck('id')->all())->not->toContain($hidden['id']);
    });

    it('BR-FB04: câu RIÊNG của chi nhánh không đi qua công tắc này — 404', function () {
        $own = makeFaq($this->shopAUrl, 'Câu riêng của chi nhánh A');

        // Nó đã có `is_published` của chính nó; hai công tắc cho cùng một việc
        // là chỗ để chúng lệch nhau về sau.
        setVisible($this->shopAUrl, $own['id'], false)->assertNotFound();
    });

    it('câu của chi nhánh KHÁC cũng 404, không phải 403', function () {
        $ownB = makeFaq($this->shopBUrl, 'Câu riêng của chi nhánh B');

        setVisible($this->shopAUrl, $ownB['id'], false)->assertNotFound();
    });
});
