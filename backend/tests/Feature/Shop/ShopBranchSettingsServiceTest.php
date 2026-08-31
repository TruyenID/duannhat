<?php

/**
 * #1696 (con của #1666) — hai endpoint ghi cài đặt chi nhánh giờ đi qua
 * `ShopBranchSettingsService`.
 *
 * Vì sao file này tồn tại: endpoint `settings/takeaway-payment` trước #1696
 * KHÔNG có một bài test nào. Luật 5..120 của nó — cùng với chuỗi thông báo lỗi
 * — được chép nguyên văn ở CẢ HAI controller, nên xoá một bản đi thì không có
 * gì đỏ. Đó chính là kiểu trôi mà việc gộp về một service phải chặn, và một
 * service dùng chung chỉ đáng tin khi có bài test chứng minh cả hai cửa vào
 * cùng ăn một luật.
 *
 * Ca chịu lực KHÔNG phải "lưu rồi đọc lại" mà là:
 *   1. **biên** 5 và 120 (trong) vs 4 và 121 (ngoài) ở CẢ HAI endpoint;
 *   2. **hình dạng thân 422** — `message` là chuỗi cố định "Validation error.",
 *      không phải envelope của Laravel. admin-web đọc thân này, nên nó là hợp
 *      đồng; chuyển sang FormRequest sẽ đổi nó mà không ai thấy;
 *   3. **không ghi gì khi 422** — bản cũ trả về sớm trước `update()`, bản mới
 *      `abort()` trước `update()`. Nếu ai đó sau này chuyển validate xuống sau
 *      lệnh ghi, chỉ điều kiện này bắt được.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'takeaway_payment_timeout_minutes' => 30,
    ]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'bs-'.Str::random(4),
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->shop->id,
        'currency_code' => 'JPY',
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->branchUrl = "/api/v1/shops/{$this->shop->slug}/settings/branch";
    $this->takeawayUrl = "/api/v1/shops/{$this->shop->slug}/settings/takeaway-payment";
});

// =========================================================================
//  Cửa vào riêng: /settings/takeaway-payment
// =========================================================================

describe('endpoint takeaway-payment', function () {
    it('nhận giá trị trong khoảng và đọc lại được cả chuỗi kế thừa', function () {
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => 45])
            ->assertOk()
            ->assertJsonPath('data.takeaway_payment_timeout_minutes', 45)
            ->assertJsonPath('data.hq_brand_takeaway_payment_timeout_minutes', 30)
            ->assertJsonPath('data.effective_takeaway_payment_timeout_minutes', 45);

        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBe(45);
    });

    // Biên: 5 và 120 phải NHẬN. Một bài test chỉ thử 45 sẽ xanh kể cả khi ai đó
    // đổi khoảng thành 10..60, nên hai đầu mút mới là chỗ đo.
    it('nhận đúng hai đầu mút của khoảng', function (int $minutes) {
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => $minutes])
            ->assertOk()
            ->assertJsonPath('data.takeaway_payment_timeout_minutes', $minutes);
    })->with([5, 120]);

    it('từ chối giá trị ngoài khoảng và KHÔNG ghi gì', function (mixed $bad) {
        $this->shop->update(['takeaway_payment_timeout_minutes' => 45]);

        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => $bad])
            ->assertStatus(422)
            ->assertJsonPath('errors.takeaway_payment_timeout_minutes.0',
                'The takeaway payment timeout minutes must be between 5 and 120.');

        // Giá trị cũ còn nguyên — 422 không được đi kèm một lệnh ghi.
        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBe(45);
    })->with([4, 121, 0, -5, 'abc']);

    // Hình dạng thân 422 là hợp đồng với admin-web: `message` cố định, KHÔNG
    // phải envelope của Laravel (vốn đặt `message` = thông báo lỗi đầu tiên).
    it('giữ nguyên hình dạng thân 422 tự dựng', function () {
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => 999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation error.');
    });

    it('xoá ghi đè khi gửi null — quay về kế thừa brand', function () {
        $this->shop->update(['takeaway_payment_timeout_minutes' => 45]);

        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => null])
            ->assertOk()
            ->assertJsonPath('data.takeaway_payment_timeout_minutes', null)
            ->assertJsonPath('data.effective_takeaway_payment_timeout_minutes', 30);

        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBeNull();
    });
});

// =========================================================================
//  #1705 — `settings/takeaway-payment` là chủ sở hữu DUY NHẤT của cột
// =========================================================================

/*
 * #1696 ghim "cả hai endpoint dùng chung luật 5..120" — đúng với hành vi lúc đó,
 * và chính nó phơi ra vấn đề: hai cửa vào cùng ghi một cột với HAI ngữ nghĩa
 * khác nhau về "khoá vắng mặt", nên một PATCH rỗng tới cửa này âm thầm xoá giá
 * trị cửa kia vừa đặt.
 *
 * #1705 chốt MỘT chủ sở hữu. Hai dataset cũ ở đây được THAY, không phải nới:
 * luật 5..120 vẫn được ghim đầy đủ ở khối `endpoint takeaway-payment` bên trên.
 *
 * Đo trước khi chốt: admin-web chưa bao giờ gửi khoá này qua `settings/branch`
 * — nó chỉ gửi cart_timeout_minutes · invoice_registration_number · cặp
 * point-earn · faq_inherit_hq.
 */

describe('endpoint branch thôi ghi takeaway timeout', function () {
    it('bỏ qua khoá đó kể cả khi giá trị HỢP LỆ', function (int $minutes) {
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => 45])
            ->assertOk();

        $this->actingAs($this->user)
            ->patchJson($this->branchUrl, ['takeaway_payment_timeout_minutes' => $minutes])
            ->assertOk();

        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBe(45);
    })->with([5, 120]);

    it('KHÔNG 422 với giá trị ngoài khoảng — nó thôi là đầu vào của endpoint này', function (mixed $bad) {
        $this->shop->update(['takeaway_payment_timeout_minutes' => 45]);

        $this->actingAs($this->user)
            ->patchJson($this->branchUrl, ['takeaway_payment_timeout_minutes' => $bad])
            ->assertOk();

        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBe(45);
    })->with([4, 121, 'abc']);

    it('vẫn ĐỌC được giá trị, kèm giá trị brand và giá trị hiệu lực', function () {
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, ['takeaway_payment_timeout_minutes' => 45])
            ->assertOk();

        $this->actingAs($this->user)
            ->getJson($this->branchUrl)
            ->assertOk()
            ->assertJsonPath('data.takeaway_payment_timeout_minutes', 45)
            ->assertJsonPath('data.effective_takeaway_payment_timeout_minutes', 45);
    });

    // Khác biệt CỐ Ý giữa hai cửa vào, có từ trước #1696 và được giữ nguyên:
    // `settings/branch` là partial update thật (khoá vắng mặt = không đụng),
    // còn `settings/takeaway-payment` LUÔN ghi cột của nó.
    it('bỏ qua khoá vắng mặt, trong khi endpoint riêng thì xoá', function () {
        $this->shop->update(['takeaway_payment_timeout_minutes' => 45]);

        // branch: không gửi khoá ⇒ giữ nguyên.
        $this->actingAs($this->user)
            ->patchJson($this->branchUrl, ['cart_timeout_minutes' => 60])
            ->assertOk();
        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBe(45);

        // takeaway-payment: không gửi khoá ⇒ xoá ghi đè.
        $this->actingAs($this->user)
            ->patchJson($this->takeawayUrl, [])
            ->assertOk();
        expect($this->shop->fresh()->takeaway_payment_timeout_minutes)->toBeNull();
    });

    it('vẫn chặn cart_timeout_minutes ngoài khoảng 1..1440', function (mixed $bad) {
        $this->actingAs($this->user)
            ->patchJson($this->branchUrl, ['cart_timeout_minutes' => $bad])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation error.')
            ->assertJsonPath('errors.cart_timeout_minutes.0',
                'The cart timeout minutes must be between 1 and 1440.');
    })->with([0, 1441, 'abc']);

    // Một PATCH mang nhiều khoá mà một khoá hỏng thì KHÔNG khoá nào được ghi —
    // validate chạy hết trước lệnh ghi duy nhất ở cuối.
    it('không ghi khoá hợp lệ đi kèm khi một khoá khác hỏng', function () {
        // #1705 — khoá hỏng phải là khoá endpoint này CÒN validate.
        // `takeaway_payment_timeout_minutes` giờ bị bỏ qua ở đây nên nó không
        // hỏng được nữa; `cart_timeout_minutes` ngoài khoảng là ca tương đương.
        $this->actingAs($this->user)
            ->patchJson($this->branchUrl, [
                'faq_inherit_hq' => false,
                'cart_timeout_minutes' => 9999,
            ])
            ->assertStatus(422);

        expect($this->shop->fresh()->faq_inherit_hq)->not->toBeFalse();
    });
});
