<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PrintImageRaster;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\PrintImageResolver;
use App\Services\Print\PrintImageStore;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * #1957 mảnh B — cổng cho resolver + feed đồng bộ.
 *
 * Ba thứ đáng canh, theo thứ tự mức thiệt hại nếu sai:
 *
 * 1. **Rò ảnh giữa các brand.** Hash là địa chỉ TOÀN CỤC, nên endpoint byte
 *    không được tin hash một mình.
 * 2. **`effective_from` đọc bằng sai đồng hồ** — đúng lớp lỗi #1091. Một quán
 *    Hà Nội lật logo theo giờ Tokyo là sai hai tiếng, mỗi ngày.
 * 3. **Mất ảnh gốc làm hỏng cả feed.** TR-05 nói phiếu vẫn phải in.
 */
beforeEach(function () {
    Storage::fake();
    $this->store = app(PrintImageStore::class);
    $this->resolver = app(PrintImageResolver::class);

    $this->orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $this->token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->asDevice = fn () => $this->withHeaders(['Authorization' => "Bearer {$this->token}"]);
});

function png(int $w = 120, int $h = 60, int $grey = 0): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, $grey, $grey, $grey));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function publishBrandLogo($test, string $bytes, ?string $effectiveFrom = null)
{
    $asset = $test->store->store($bytes, 'brand_logo', PrintTemplateScope::Brand, [
        'organization_id' => $test->org->id,
        'brand_id' => $test->brand->id,
    ]);

    return $test->store->publish($asset, null, $effectiveFrom);
}

it('bản nháp KHÔNG có hiệu lực — chỉ published mới tới được quán', function () {
    $this->store->store(png(), 'brand_logo', PrintTemplateScope::Brand, [
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    expect($this->resolver->forBranch($this->branch->id, 'brand_logo'))->toBeNull();
});

it('chi nhánh thắng brand', function () {
    publishBrandLogo($this, png(120, 60, 0));

    $shop = $this->store->store(png(120, 60, 60), 'brand_logo', PrintTemplateScope::Shop, [
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->store->publish($shop);

    expect($this->resolver->forBranch($this->branch->id, 'brand_logo')->id)->toBe($shop->id);
});

it('effective_from đọc bằng đồng hồ CHI NHÁNH, không phải đồng hồ máy chủ (#1091)', function () {
    // 2026-09-01 00:30 JST = 2026-08-31 15:30 UTC. Một quán Tokyo ĐÃ sang mùng 1;
    // đọc bằng `now()` (UTC) thì vẫn là 31/8 và logo không lật — sai một ngày.
    Carbon::setTestNow(Carbon::parse('2026-08-31 15:30:00', 'UTC'));

    publishBrandLogo($this, png(), '2026-09-01 00:00:00');

    expect(BusinessClock::now($this->branch->id)->format('Y-m-d'))->toBe('2026-09-01')
        ->and($this->resolver->forBranch($this->branch->id, 'brand_logo'))->not->toBeNull();

    Carbon::setTestNow();
});

it('effective_from ở tương lai thì CHƯA có hiệu lực', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00', 'UTC'));
    publishBrandLogo($this, png(), '2026-09-01 00:00:00');

    expect($this->resolver->forBranch($this->branch->id, 'brand_logo'))->toBeNull();

    Carbon::setTestNow();
});

it('effective_from NULL nghĩa là hiệu lực ngay — không bị loại khỏi truy vấn', function () {
    // Bẫy SQL thật: `where('effective_from', '<=', $now)` một mình sẽ loại MỌI
    // hàng null, tức mọi ảnh publish theo cách thông thường.
    publishBrandLogo($this, png(), null);

    expect($this->resolver->forBranch($this->branch->id, 'brand_logo'))->not->toBeNull();
});

it('index liệt kê biến thể kèm hash, KHÔNG kèm byte', function () {
    publishBrandLogo($this, png(200, 100));
    $res = ($this->asDevice)()->getJson('/api/v1/workstation/print-images');

    $res->assertOk();
    $row = $res->json('data.0');

    expect($row['source'])->toBe('brand_logo')
        ->and($row['variants'])->toHaveCount(2)
        ->and($row['variants'][0])->toHaveKeys(['max_width_dots', 'width_dots', 'height_dots', 'content_hash', 'byte_length'])
        // Byte KHÔNG được nằm ở đây — đó là toàn bộ lý do tách hai bước.
        ->and($row['variants'][0])->not->toHaveKey('data');
});

it('show trả byte theo hash và đánh dấu cache vĩnh viễn', function () {
    $asset = publishBrandLogo($this, png(200, 100));
    $raster = $this->store->rasterFor($asset, 576);
    $res = ($this->asDevice)()->getJson('/api/v1/workstation/print-images/'.$raster->content_hash);

    $res->assertOk()
        ->assertHeader('ETag', '"'.$raster->content_hash.'"');

    // Laravel sắp lại thứ tự directive, nên so từng mảnh chứ không so cả chuỗi.
    $cache = $res->headers->get('Cache-Control');
    expect($cache)->toContain('immutable')->toContain('max-age=31536000');

    expect(base64_decode($res->json('data.data'), true))->toHaveLength($raster->byte_length);
});

it('KHÔNG rò ảnh của brand khác dù biết đúng hash', function () {
    $mine = publishBrandLogo($this, png(120, 60, 0));

    $otherOrgId = (string) Str::uuid();
    $otherOrg = Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $theirs = $this->store->store(png(200, 90, 240), 'brand_logo', PrintTemplateScope::Brand, [
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);
    $this->store->publish($theirs);
    $theirHash = $this->store->rasterFor($theirs, 576)->content_hash;

    // Ảnh khác nhau ⇒ hash khác nhau; nếu trùng thì test này vô nghĩa.
    //
    // Cỡ VÀ độ sáng đều phải khác. Hai ảnh cùng cỡ, một xám 0 một xám 77, cho ra
    // hash GIỐNG HỆT: ngưỡng mực là 128 nên cả hai đều thành toàn mực. Đúng hành
    // vi của rasteriser, nhưng nó từng làm chính test này tự vô hiệu.
    expect($theirHash)->not->toBe($this->store->rasterFor($mine, 576)->content_hash);

    ($this->asDevice)()
        ->getJson('/api/v1/workstation/print-images/'.$theirHash)
        ->assertNotFound();
});

it('branch_id lạ trong query bị chặn 403 — thiết bị bị ghim từ lúc pair', function () {
    ($this->asDevice)()
        ->getJson('/api/v1/workstation/print-images?branch_id='.Branch::factory()->create()->id)
        ->assertForbidden();
});

it('TR-05 — mất ảnh gốc thì feed bỏ qua, KHÔNG hỏng', function () {
    $asset = publishBrandLogo($this, png(200, 100));
    Storage::delete($asset->original_path);

    // Hai biến thể mặc định đã raster sẵn lúc store, nên chúng VẪN phải có —
    // mất ảnh gốc chỉ chặn việc raster bề rộng MỚI.
    $res = ($this->asDevice)()->getJson('/api/v1/workstation/print-images');

    $res->assertOk();
    expect($res->json('data.0.variants'))->toHaveCount(2);
});

it('feed print_images nằm trong sync-manifest và động theo dữ liệu', function () {
    $before = ($this->asDevice)()->getJson('/api/v1/workstation/sync-manifest')->json('data');
    expect($before['feeds'])->toHaveKey('print_images');

    publishBrandLogo($this, png());
    // Manifest được cache 3 giây (TTL_SECONDS) — không xoá thì lượt hai đọc lại
    // đúng giá trị cũ và test sẽ "chứng minh" một điều sai.
    Cache::flush();

    $after = ($this->asDevice)()->getJson('/api/v1/workstation/sync-manifest')->json('data');

    expect($after['feeds']['print_images'])->not->toBe($before['feeds']['print_images'])
        ->and($after['manifest_version'])->not->toBe($before['manifest_version']);
});

it('chi nhánh KHÔNG gắn brand thì trả null, không nổ', function () {
    // `branches.console_brand_id` là nullable. Một chi nhánh chưa gắn thương hiệu
    // là dữ liệu hợp lệ, không phải lỗi — và resolver phải nói "không có ảnh"
    // chứ không ném. TR-05: quán đó vẫn phải in được phiếu.
    $orphan = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => null,
        'timezone' => 'Asia/Tokyo',
    ]);

    publishBrandLogo($this, png(120, 60));

    expect($this->resolver->forBranch($orphan->id, 'brand_logo'))->toBeNull()
        ->and($this->resolver->allForBranch($orphan->id))->toBe([]);
});

it('chi nhánh KHÔNG tồn tại thì trả null, không nổ', function () {
    // Thiết bị còn cầm token của một chi nhánh đã bị xoá là chuyện có thật.
    expect($this->resolver->forBranch((string) Str::uuid(), 'brand_logo'))->toBeNull();
});

/*
 * KHÔNG có test cho nhánh "thiết bị chưa gắn chi nhánh" (`index` trả rỗng,
 * `show` trả 403 NO_BRANCH) — đã thử và ĐO ĐƯỢC rằng nó không chạm tới được:
 * `devices.branch_id` là NOT NULL, nên một thiết bị như vậy không tồn tại.
 *
 * Giữ nguyên guard trong controller thay vì xoá cho đẹp số coverage: nó bắt
 * `$request->attributes->get('device')` trả null — ca xảy ra nếu middleware
 * không chạy, và khi đó `$device?->branch_id` cho null. Xoá một guard để một
 * con số tăng lên là tối ưu hoá thước đo chống lại chính mục tiêu của nó.
 */

it('ảnh MẤT HẾT biến thể thì bị bỏ khỏi feed, không trả hàng rỗng', function () {
    // Ảnh gốc mất khỏi storage VÀ các raster đã sinh cũng mất — máy trạm không
    // được nhận một mục không có byte nào để tải. Trả nó ra sẽ khiến máy trạm
    // gọi `{hash}` cho một hash không tồn tại, mỗi tick, mãi mãi.
    $asset = publishBrandLogo($this, png(200, 100));
    Storage::delete($asset->original_path);
    PrintImageRaster::query()->where('asset_id', $asset->id)->delete();

    ($this->asDevice)()->getJson('/api/v1/workstation/print-images')
        ->assertOk()
        ->assertJsonPath('data', []);
});
