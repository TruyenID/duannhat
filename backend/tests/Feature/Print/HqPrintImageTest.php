<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintImageAsset;
use App\Models\PrintImageRaster;
use App\Models\Role;
use App\Models\User;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\PrintImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * #1957 mảnh B — bề mặt HQ tải ảnh in lên.
 *
 * Cái đáng canh nhất không phải "tải lên được" mà là **thứ gì KHÔNG xuống được
 * máy quán**: bản nháp chưa publish, `source` ngoài allow-list, và tệp hỏng.
 * Ba thứ đó mà lọt thì hậu quả nằm ở quán, trên giấy, không hoàn tác được.
 */
beforeEach(function () {
    Storage::fake();

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->admin = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->admin, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/print-images";
    $this->store = app(PrintImageStore::class);
});

/** PNG thật, để GD giải mã được (Laravel's fake image cũng là PNG thật). */
function logoUpload(int $w = 200, int $h = 100, string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

it('index liệt kê ĐỦ ô có thể tải lên, kể cả ô còn trống', function () {
    $res = $this->actingAs($this->admin)->getJson($this->base);

    $res->assertOk();
    $sources = array_column($res->json('data'), 'source');

    // Giao diện không được phải tự biết allow-list, nên ô trống vẫn phải hiện
    // với `asset: null` chứ không bị bỏ khỏi danh sách.
    expect($sources)->toBe(config('print_blocks.image.sources'))
        ->and($res->json('data.0.asset'))->toBeNull();
});

it('tải lên tạo bản NHÁP, chưa xuống quán', function () {
    $res = $this->actingAs($this->admin)
        ->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    $res->assertCreated();

    expect($res->json('data.status'))->toBe('draft')
        ->and($res->json('data.version'))->toBe(1)
        ->and($res->json('data.variants'))->toHaveCount(2)
        ->and($res->json('data.original_filename'))->toBe('logo.png');
});

it('publish mới đưa vào hiệu lực', function () {
    $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    $res = $this->actingAs($this->admin)->postJson($this->base.'/brand_logo/publish');

    $res->assertOk();
    expect($res->json('data.status'))->toBe('published')
        ->and($res->json('data.published_at'))->not->toBeNull();
});

it('publish khi chưa tải gì lên là 404, không phải 500', function () {
    $this->actingAs($this->admin)
        ->postJson($this->base.'/brand_logo/publish')
        ->assertNotFound()
        ->assertJsonPath('code', 'PRINT_IMAGE_NOT_FOUND');
});

it('TR-21 — `source` ngoài allow-list là 422', function () {
    $this->actingAs($this->admin)
        ->postJson($this->base.'/promo_banner', ['file' => logoUpload()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRINT_IMAGE_SOURCE_UNKNOWN');

    // Và không được tạo hàng nào dưới định danh lạ đó.
    expect(PrintImageAsset::count())->toBe(0);
});

it('tệp không giải mã được là 422 đọc được, không phải 500', function () {
    // Đuôi .png nhưng nội dung là rác — đúng kiểu người dùng đổi tên PDF.
    $bad = UploadedFile::fake()->createWithContent('logo.png', 'không phải ảnh');

    $res = $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => $bad]);

    // Hoặc validation `mimes` chặn (422), hoặc store ném và controller đổi thành
    // 422. Cả hai đều đúng; thứ KHÔNG được phép là 500.
    expect($res->status())->toBe(422)
        ->and(PrintImageAsset::count())->toBe(0);
});

it('tải lại đúng tệp cũ không đẻ phiên bản mới', function () {
    $file = logoUpload();

    $a = $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => $file]);
    $b = $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    // `UploadedFile::fake()->image()` sinh ảnh giống nhau cho cùng tham số, nên
    // hash trùng ⇒ cùng một hàng. Đây là chốt giữ feed đồng bộ đứng yên.
    expect($b->json('data.id'))->toBe($a->json('data.id'))
        ->and(PrintImageAsset::count())->toBe(1);
});

it('người không có quyền không thấy và không ghi được', function () {
    $outsider = User::factory()->create(['console_organization_id' => (string) Str::uuid()]);

    $this->actingAs($outsider)->getJson($this->base)->assertForbidden();
    $this->actingAs($outsider)
        ->postJson($this->base.'/brand_logo', ['file' => logoUpload()])
        ->assertForbidden();
});

it('khách chưa đăng nhập bị chặn', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('effective_from sai định dạng bị chặn ở validation', function () {
    $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    $this->actingAs($this->admin)
        ->postJson($this->base.'/brand_logo/publish', ['effective_from' => 'ngày mai'])
        ->assertStatus(422);
});

it('ảnh gắn ĐÚNG organization — brands không có organization_id nên phải tra vòng', function () {
    $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    $asset = PrintImageAsset::firstOrFail();

    // Đây là kiểu sai IM LẶNG: `$brand->organization_id` cho ra null, cột cho
    // phép null, hàng vẫn ghi được — chỉ là không thuộc tổ chức nào.
    expect($asset->organization_id)->toBe($this->orgId)
        ->and($asset->brand_id)->toBe($this->brand->id)
        ->and($asset->scope)->toBe(PrintTemplateScope::Brand->value);
});

it('TR-37 — đọc được KHÔNG có nghĩa là ghi được', function () {
    // Test "người ngoài org" ở trên KHÔNG chứng minh policy làm gì: middleware
    // ngữ cảnh brand đã chặn họ từ trước. Đột biến cho thấy đúng vậy — gỡ hẳn
    // `authorize('manageBrand')` mà cả file vẫn xanh.
    //
    // Ranh giới thật nằm ở người BÊN TRONG tổ chức: `shop-manager` có
    // `menu.manage` (đọc) nhưng không có `catalog.approve` (ghi).
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole(
        Role::query()->where('slug', 'shop-manager')->firstOrFail(),
        $this->orgId,
    );

    $this->actingAs($manager)->getJson($this->base)->assertOk();

    $this->actingAs($manager)
        ->postJson($this->base.'/brand_logo', ['file' => logoUpload()])
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson($this->base.'/brand_logo/publish')
        ->assertForbidden();

    expect(PrintImageAsset::count())->toBe(0);
});

it('ảnh mất hết raster vẫn liệt kê được, chỉ là không có biến thể', function () {
    // Khác feed máy trạm (bỏ hẳn mục đó): ở HQ người vận hành CẦN thấy ảnh vẫn
    // tồn tại nhưng đang hỏng, để còn tải lại. Giấu nó đi sẽ thành "logo biến
    // mất không rõ vì sao".
    $this->actingAs($this->admin)->postJson($this->base.'/brand_logo', ['file' => logoUpload()]);

    $asset = PrintImageAsset::firstOrFail();
    Storage::delete($asset->original_path);
    PrintImageRaster::query()->where('asset_id', $asset->id)->delete();

    $res = $this->actingAs($this->admin)->getJson($this->base);

    $res->assertOk();
    $row = collect($res->json('data'))->firstWhere('source', 'brand_logo');

    expect($row['asset'])->not->toBeNull()
        ->and($row['asset']['variants'])->toBe([]);
});
