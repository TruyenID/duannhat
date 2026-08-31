<?php

/**
 * #1772 — ảnh nền thẻ thành viên, mỗi hạng một hình.
 *
 *   PATCH /api/v1/hq/{brand}/settings/brand   HQ đặt map {tier_key: file_id}
 *   GET   /api/v1/hq/{brand}/settings/brand   map id (để PATCH lại) + map URL (xem trước)
 *   GET   /api/v1/customer/me/membership      từng hạng kèm `background_image_url`
 *
 * Hai thứ được ghim ở đây vì cả hai đều hỏng CÂM nếu sai:
 *
 *   - khoá hạng gõ sai vẫn lưu được ⇒ người cấu hình tưởng đã đặt ảnh cho hạng
 *     đó, khách không bao giờ thấy;
 *   - file upload lên là file TẠM, hết hạn sau 12h ⇒ ảnh biến mất sau một đêm,
 *     và triệu chứng rơi vào khách chứ không rơi vào người vừa bấm Lưu.
 */

use App\Models\Brand;
use App\Models\Customer;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'b-'.Str::random(4),
        'is_active' => true,
        'customer_tier_card_backgrounds' => null,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

/** File đã upload xong, còn ở trạng thái TẠM — đúng thứ admin gửi lên khi lưu. */
function tierBackgroundFile(string $orgId): File
{
    return File::factory()->create([
        'organization_id' => $orgId,
        'collection' => 'customer_tier_card_background',
        'disk' => 'public',
    ]);
}

// =============================================================================
//  Đường ghi — HQ
// =============================================================================

it('lưu ảnh nền cho từng hạng và trả về cả id lẫn URL', function () {
    $gold = tierBackgroundFile($this->orgId);
    $platinum = tierBackgroundFile($this->orgId);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => [
                'gold' => $gold->id,
                'platinum' => $platinum->id,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.customer_tier_card_backgrounds.gold', $gold->id)
        ->assertJsonPath('data.customer_tier_card_backgrounds.platinum', $platinum->id);

    expect($this->brand->fresh()->customer_tier_card_backgrounds)
        ->toBe(['gold' => $gold->id, 'platinum' => $platinum->id]);

    // URL giải ra lúc ĐỌC, không lưu vào DB — đó là điểm cả tính năng này dựa vào.
    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/brand")
        ->assertOk()
        ->assertJsonPath('data.customer_tier_card_background_urls.gold', $gold->fresh()->getUrl())
        ->assertJsonPath('data.customer_tier_card_backgrounds.gold', $gold->id);
});

it('không lưu URL tuyệt đối vào DB — chỉ id', function () {
    $gold = tierBackgroundFile($this->orgId);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => ['gold' => $gold->id],
        ])
        ->assertOk();

    // Cột chỉ được chứa id. Một URL lọt vào đây là ghim host của lúc lưu, và
    // mọi ảnh gãy cùng lúc khi staging xoay tunnel hoặc prod đổi CDN.
    $stored = $this->brand->fresh()->customer_tier_card_backgrounds;

    expect($stored['gold'])->toBe($gold->id)
        ->and(str_contains($stored['gold'], 'http'))->toBeFalse();
});

it('chặn khoá hạng không có trong thang hạng', function () {
    $file = tierBackgroundFile($this->orgId);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            // Gõ sai "platinum" — kiểu lỗi sẽ không ai phát hiện nếu lọt.
            'customer_tier_card_backgrounds' => ['platinium' => $file->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_tier_card_backgrounds');

    expect($this->brand->fresh()->customer_tier_card_backgrounds)->toBeNull();
});

it('chặn file id không tồn tại', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => ['gold' => (string) Str::uuid()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_tier_card_backgrounds.gold');
});

it('xoá ảnh của một hạng khi gửi null, giữ nguyên các hạng khác', function () {
    $gold = tierBackgroundFile($this->orgId);
    $silver = tierBackgroundFile($this->orgId);
    $this->brand->update([
        'customer_tier_card_backgrounds' => ['gold' => $gold->id, 'silver' => $silver->id],
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => ['gold' => $gold->id, 'silver' => null],
        ])
        ->assertOk();

    expect($this->brand->fresh()->customer_tier_card_backgrounds)->toBe(['gold' => $gold->id]);
});

it('đưa cột về null khi mọi hạng đều bị xoá', function () {
    $gold = tierBackgroundFile($this->orgId);
    $this->brand->update(['customer_tier_card_backgrounds' => ['gold' => $gold->id]]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => ['gold' => null],
        ])
        ->assertOk();

    // `{}` cũng là "chưa cấu hình", nhưng null là cách nói duy nhất không mơ hồ.
    expect($this->brand->fresh()->customer_tier_card_backgrounds)->toBeNull();
});

it('giữ file tạm lại thành vĩnh viễn khi lưu', function () {
    $gold = tierBackgroundFile($this->orgId);

    expect($gold->status)->toBe(FileStatusEnum::Temporary)
        ->and($gold->expires_at)->not->toBeNull();

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_tier_card_backgrounds' => ['gold' => $gold->id],
        ])
        ->assertOk();

    // Không có bước này thì job dọn file tạm xoá ảnh sau 12h và thẻ rơi về
    // gradient mặc định — không có gì trong hệ thống kêu lên.
    $fresh = $gold->fresh();
    expect($fresh->status)->toBe(FileStatusEnum::Permanent)
        ->and($fresh->expires_at)->toBeNull();
});

// =============================================================================
//  Đường đọc — khách
// =============================================================================

it('gắn background_image_url vào hạng hiện tại và cả thang hạng', function () {
    $bronze = tierBackgroundFile($this->orgId);
    $this->brand->update(['customer_tier_card_backgrounds' => ['bronze' => $bronze->id]]);

    $customer = Customer::factory()->selfRegistered()->create(['brand_id' => $this->brand->id]);
    $token = $customer->createToken('test')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/customer/me/membership')
        ->assertOk()
        ->assertJsonPath('data.current_tier.key', 'bronze')
        ->assertJsonPath('data.current_tier.background_image_url', $bronze->fresh()->getUrl());

    // Cả thang hạng cũng phải mang ảnh — trang quyền lợi vẽ từ `tiers[]`.
    $tiers = collect($response->json('data.tiers'))->keyBy('key');

    expect($tiers['bronze']['background_image_url'])->toBe($bronze->fresh()->getUrl())
        // Hạng chưa cấu hình phải là null, KHÔNG phải một URL mặc định: FE dựa
        // vào đúng chỗ này để rơi về gradient vàng sẵn có.
        ->and($tiers['gold']['background_image_url'])->toBeNull()
        ->and($tiers['platinum']['background_image_url'])->toBeNull();
});

it('trả null cho mọi hạng khi brand chưa cấu hình ảnh nào', function () {
    $customer = Customer::factory()->selfRegistered()->create(['brand_id' => $this->brand->id]);
    $token = $customer->createToken('test')->plainTextToken;

    $tiers = $this->withToken($token)
        ->getJson('/api/v1/customer/me/membership')
        ->assertOk()
        ->assertJsonPath('data.current_tier.background_image_url', null)
        ->json('data.tiers');

    expect(collect($tiers)->pluck('background_image_url')->filter())->toBeEmpty();
});

it('không ngã khi khách chưa gắn brand', function () {
    $customer = Customer::factory()->selfRegistered()->create(['brand_id' => null]);
    $token = $customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/customer/me/membership')
        ->assertOk()
        ->assertJsonPath('data.current_tier.background_image_url', null);
});

it('bỏ qua khoá trỏ tới file đã bị xoá', function () {
    $gold = tierBackgroundFile($this->orgId);
    $this->brand->update(['customer_tier_card_backgrounds' => ['gold' => $gold->id]]);
    $gold->delete();

    $customer = Customer::factory()->selfRegistered()->create(['brand_id' => $this->brand->id]);
    $token = $customer->createToken('test')->plainTextToken;

    $tiers = collect(
        $this->withToken($token)
            ->getJson('/api/v1/customer/me/membership')
            ->assertOk()
            ->json('data.tiers')
    )->keyBy('key');

    // Ảnh mất thì rơi về nền mặc định, không phải 500.
    expect($tiers['gold']['background_image_url'])->toBeNull();
});
