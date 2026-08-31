<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * #2146 — tải logo shop HỎNG thì phải KÊU, không được trả 201.
 *
 * Disk `s3` khai `throw => false` (`config/filesystems.php`), nên `put()` trả
 * `false` khi hỏng và **không ném exception, không ghi log**. Bản trước bỏ qua
 * giá trị trả về rồi đáp `201 Created` kèm `Storage::url($path)` — người dùng
 * thấy "thành công", ảnh không bao giờ hiện, Laravel log trống.
 *
 * Đây là hình dạng câm của #2101 lặp lại ở endpoint khác, và là chỗ THỨ BA
 * trong cùng họ được tìm ra trong tuần (#2101 `FileController`, #2136
 * `PrintImageStore`, và đây). Điểm chung không phải "chọn sai disk" mà là **một
 * thao tác lưu trữ hỏng mà không ai nghe thấy**.
 *
 * Thời đích ghi còn là `s3` ghi cứng, `.env.example` ship `AWS_BUCKET=` rỗng
 * nên hỏng-im-lặng là trạng thái MẶC ĐỊNH của mọi triển khai chưa cấu hình s3.
 * #2163 chuyển đích mặc định sang `public`, nhưng LỚP lỗi thì disk nào cũng
 * mắc — `public` cũng khai `throw => false` (#2184: quyền sau rsync, đầy đĩa,
 * `storage:link` trượt) — nên bài này vẫn là rào sống, không phải di tích.
 *
 * Chỗ tiêm lỗi: `stubFailingDisk()` stub `Storage::disk()` cho **MỌI tên disk**
 * trả về một disk có `put()` = `false`. Đó là **cái seam giữa controller và lưu
 * trữ** — đúng chỗ guard đang đứng — và việc stub không ghim tên disk là chủ
 * đích (#2184): đích ghi giờ là cấu hình (`filesystems.uploads`, #2163), một
 * stub ghim `'s3'` sẽ trượt trong im lặng ngay khi đích đổi. Mock chính hàm
 * chứa guard mới là tự bịt mắt; mock cái mà guard đang xét thì không.
 */
beforeEach(function () {
    // Theo đúng harness của ShopTest: `branches`/`users` khoá theo
    // `console_organization_id` (shadow id từ SSO), KHÔNG phải `organization_id`
    // — quy tắc ghi ở backend/CLAUDE.md, và tôi vừa vấp đúng nó ở bản đầu.
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'img-brand',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/shops";
});

/**
 * Disk giả trả `false` ở `put()` — đúng hành vi của s3 chưa cấu hình.
 *
 * Mock contract thay vì viết anonymous class implement cả `Filesystem`: interface
 * đó có hơn hai chục method, và một stub lệch chữ ký sẽ làm bài test đỏ vì lý do
 * KHÔNG liên quan tới thứ đang đo — tôi vừa vấp đúng vậy ở bản đầu.
 */
function stubFailingDisk(): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    $disk->shouldReceive('url')->andReturn('https://never-reached.invalid/x');

    Storage::shouldReceive('disk')->andReturn($disk);
}

it('KHÔNG trả 201 khi ghi vào disk thất bại', function () {
    stubFailingDisk();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertStatus(500)
        ->assertJsonPath('code', 'IMAGE_UPLOAD_STORAGE_FAILED');
});

it('GHI LOG khi ghi thất bại — im lặng chính là lỗi, không phải hệ quả', function () {
    stubFailingDisk();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    $this->actingAs($this->user)
        ->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

    $errors = array_filter(
        $logged,
        fn (MessageLogged $e) => $e->level === 'error' && str_contains($e->message, 'shop image upload'),
    );

    expect($errors)->not->toBeEmpty(
        'ghi hỏng mà log trống — đúng chế độ câm của #2101, chỉ đổi endpoint',
    );
});

it('đường THÀNH CÔNG vẫn trả 201 kèm URL — bản sửa không được siết nhầm', function () {
    // Mặt kia của bánh cóc: một guard quá tay sẽ chặn cả lần ghi tốt, và triệu
    // chứng lúc đó (không upload được gì) khó truy hơn hẳn triệu chứng cũ.
    Storage::fake(config('filesystems.uploads'));

    $this->actingAs($this->user)
        ->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['url']]);
});

/**
 * #2163 — ảnh phải rơi vào ĐÚNG disk mà `filesystems.uploads` chỉ định.
 *
 * Bài trên chỉ nói "201 và có URL", nên nó xanh cả khi ảnh rơi vào một disk
 * chẳng ai đọc — đúng trạng thái production ĐÃ mắc trước #2163: `AWS_BUCKET`
 * rỗng nên đích `s3` ghi cứng không tồn tại, và cái người dùng nhận là 500
 * (trước #2147 thì là 201 kèm URL trỏ vào hư không).
 *
 * Đây là bài đo HÀNH VI, khác với rào U4 trong `UploadDiskIsPubliclyServableTest`
 * vốn đọc mã nguồn: U4 canh cả LỚP đường upload khỏi bị thêm sai chỗ, còn bài
 * này chứng minh đường này thật sự ghi đúng chỗ. Cần cả hai — U4 một mình chỉ
 * chứng minh chuỗi `config('filesystems.uploads')` có mặt trong file.
 */
it('ghi ảnh vào ĐÚNG disk của filesystems.uploads, không phải disk ghi cứng', function () {
    $uploads = (string) config('filesystems.uploads');

    $fake = Storage::fake($uploads);
    // Fake luôn `s3` để nếu bản sửa bị lùi, lần ghi rơi vào một disk GIẢ chứ
    // không phải s3 thật của môi trường chạy test.
    Storage::fake('s3');

    $this->actingAs($this->user)
        ->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertCreated();

    expect($fake->files('branches'))->toHaveCount(
        1,
        "Không có ảnh nào trên disk upload '{$uploads}'. Đích ghi đang là một ".
        'disk khác — chính hình dạng lỗi của #2163 trên production.',
    );
});
