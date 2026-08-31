<?php

use App\Models\Allergen;
use App\Models\Brand;
use App\Models\Material;
use App\Models\User;

/**
 * #2376 — cổng "không xoá allergen đang được dùng" (409 ALLERGEN_IN_USE).
 *
 * Đường này CHƯA TỪNG có test, và nó trả giá thật: generator của omnify 5.9.21
 * sinh `belongsToMany(Material::class, 'allergen_material_pivot')` — một bảng
 * KHÔNG TỒN TẠI, bảng thật là `material_allergens`. `AllergenService::delete()`
 * gọi đúng quan hệ đó, và nó có route (`DELETE /hq/{brand}/allergens/{id}`),
 * nên mọi lượt xoá allergen đều nổ SQL thay vì chạy cổng.
 *
 * 6.0.1 sửa tên bảng. Nhưng 38 ca test có sẵn quanh Allergen XANH cả trước lẫn
 * sau bản sửa — đo bằng cách trả lại tên bảng ma rồi chạy lại. Tức bản sửa
 * không được cái gì canh, và một lượt regen sau có thể phá lại mà không ai biết.
 *
 * Bài này canh HÀNH VI, không canh tên bảng: nếu quan hệ trỏ sai bảng thì truy
 * vấn ném trước khi tới được 409, nên ca đầu đỏ ngay.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

it('chặn xoá allergen còn được material dùng, và nêu tên material chặn', function () {
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
        'allergen_ids' => [$allergen->id],
    ])->assertOk();

    $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
        ->assertStatus(409)
        ->assertJsonPath('error', 'ALLERGEN_IN_USE')
        ->assertJsonPath('used_by.0.id', (string) $material->id);

    expect(Allergen::query()->whereKey($allergen->id)->exists())->toBeTrue();
});

it('cho xoá allergen không material nào dùng', function () {
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
        ->assertSuccessful();

    expect(Allergen::query()->whereKey($allergen->id)->exists())->toBeFalse();
});
