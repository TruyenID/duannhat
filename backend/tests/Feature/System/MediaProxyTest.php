<?php

use App\Models\File;
use Illuminate\Support\Facades\Storage;

/**
 * Phần lớn file này kiểm các CỔNG BẢO MẬT của proxy (#896). Chúng chạy trên
 * đích upload thật của môi trường test — `config('filesystems.uploads')` —
 * chứ không trên một tên disk viết thẳng vào test, vì proxy đọc từ đúng disk
 * đó (#2175). Các bài ở cuối file mới là bài ghim chính hành vi "đọc theo
 * config": chúng tự đặt `filesystems.uploads` rồi kiểm byte nào được trả về.
 */
beforeEach(function () {
    Storage::fake(config('filesystems.uploads'));
});

/**
 * Persist a fake object on the upload disk AND a matching permanent `files`
 * row so the MediaProxy File-row gate (issue #896) treats it as a public
 * product image.
 */
function putGalleryImage(string $path, string $mime = 'image/jpeg', string $bytes = 'FAKE-IMAGE-BYTES'): void
{
    $disk = (string) config('filesystems.uploads');

    Storage::disk($disk)->put($path, $bytes);

    File::factory()->permanent()->create([
        'disk' => $disk,
        'collection' => 'gallery',
        'path' => $path,
        'mime_type' => $mime,
    ]);
}

// ---------------------------------------------------------------------------
// File-row gate — happy path
// ---------------------------------------------------------------------------

it('streams a permanent gallery image backed by a files row', function () {
    putGalleryImage('uploads/temp/org-1/bun-cha.jpg', 'image/jpeg', 'FAKE-JPEG-BYTES');

    $res = $this->get('/api/v1/media/uploads/temp/org-1/bun-cha.jpg');

    $res->assertOk();
    expect($res->streamedContent())->toBe('FAKE-JPEG-BYTES');
});

it('requires no auth (public read-only proxy for the kiosk)', function () {
    putGalleryImage('uploads/temp/org-1/x.png', 'image/png');

    // No acting user / device token — a public gallery image must still resolve.
    $this->get('/api/v1/media/uploads/temp/org-1/x.png')->assertOk();
});

// ---------------------------------------------------------------------------
// File-row gate — rejections (private / non-image / wrong disk / wrong collection)
// ---------------------------------------------------------------------------

it('404s a temporary (unattached) upload', function () {
    Storage::disk(config('filesystems.uploads'))->put('uploads/temp/org-1/temp.jpg', 'X');
    File::factory()->create([                     // default state = temporary
        'disk' => config('filesystems.uploads'),
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/temp.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->get('/api/v1/media/uploads/temp/org-1/temp.jpg')->assertNotFound();
});

it('404s a permanent image in a non-public collection (default upload)', function () {
    Storage::disk(config('filesystems.uploads'))->put('uploads/temp/org-1/secret.jpg', 'X');
    File::factory()->permanent()->create([
        'disk' => config('filesystems.uploads'),
        'collection' => 'default',                // NOT in COLLECTION_ALLOWLIST
        'path' => 'uploads/temp/org-1/secret.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->get('/api/v1/media/uploads/temp/org-1/secret.jpg')->assertNotFound();
});

it('404s an object that has no files row and is not under a public prefix', function () {
    Storage::disk(config('filesystems.uploads'))->put('imports/customers.jpg', 'X');   // exists on disk, no row

    $this->get('/api/v1/media/imports/customers.jpg')->assertNotFound();
});

it('404s a permanent non-image file (application/pdf)', function () {
    Storage::disk(config('filesystems.uploads'))->put('uploads/temp/org-1/invoice.pdf', 'PDF');
    File::factory()->permanent()->create([
        'disk' => config('filesystems.uploads'),
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/invoice.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->get('/api/v1/media/uploads/temp/org-1/invoice.pdf')->assertNotFound();
});

it('404s an svg file row (XSS vector excluded from the allowlist)', function () {
    Storage::disk(config('filesystems.uploads'))->put('uploads/temp/org-1/logo.svg', '<svg onload="alert(1)"/>');
    File::factory()->permanent()->create([
        'disk' => config('filesystems.uploads'),
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/logo.svg',
        'mime_type' => 'image/svg+xml',
    ]);

    $this->get('/api/v1/media/uploads/temp/org-1/logo.svg')->assertNotFound();
});

it('404s a files row recorded on a disk other than the upload disk', function () {
    // `files.disk` is per row. A row pointing at another disk does not
    // authorise the key we would actually stream from the upload disk.
    config()->set('filesystems.uploads', 'public');
    Storage::fake('public');

    Storage::disk('public')->put('uploads/temp/org-1/review.jpg', 'X');
    File::factory()->permanent()->create([
        'disk' => 's3',
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/review.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->get('/api/v1/media/uploads/temp/org-1/review.jpg')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Prefix gate — brand assets with no files row
// ---------------------------------------------------------------------------

it('streams a branches/ object with no files row via the prefix allowlist', function () {
    Storage::disk(config('filesystems.uploads'))->put('branches/logos/shop.png', 'LOGO-BYTES');

    $res = $this->get('/api/v1/media/branches/logos/shop.png');

    $res->assertOk();
    expect($res->streamedContent())->toBe('LOGO-BYTES');
});

it('404s a branches/ object with a non-raster extension (svg)', function () {
    Storage::disk(config('filesystems.uploads'))->put('branches/logos/shop.svg', '<svg/>');

    $this->get('/api/v1/media/branches/logos/shop.svg')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Hardened headers + content-type override
// ---------------------------------------------------------------------------

it('sets nosniff, inline disposition and overrides the content-type to the allowlisted image mime', function () {
    // Bytes are html and the "stored" content is deceptive, but the files row
    // declares image/png — the response must force that safe type.
    Storage::disk(config('filesystems.uploads'))->put('uploads/temp/org-1/polyglot.png', '<html><script>alert(1)</script></html>');
    File::factory()->permanent()->create([
        'disk' => config('filesystems.uploads'),
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/polyglot.png',
        'mime_type' => 'image/png',
    ]);

    $res = $this->get('/api/v1/media/uploads/temp/org-1/polyglot.png');

    $res->assertOk();
    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('Content-Type', 'image/png');
    expect($res->headers->get('Cache-Control'))->toContain('immutable');
    expect($res->headers->get('Content-Disposition'))->toContain('inline');
});

// ---------------------------------------------------------------------------
// Traversal / path guards
// ---------------------------------------------------------------------------

it('404s traversal and malformed paths', function (string $encoded) {
    $this->get('/api/v1/media/'.$encoded)->assertNotFound();
})->with([
    'dot-dot' => '..%2f..%2f..%2fetc%2fpasswd',
    'double-encoded' => 'branches%2f..%252f..%252fetc%2fpasswd',
    'traversal-into-prefix' => 'branches%2f..%2fuploads%2ftemp%2forg-1%2fsecret.jpg',
    'missing-object' => 'branches/does-not-exist.png',
]);

// ---------------------------------------------------------------------------
// #2175 — đường ĐỌC phải bám đích GHI (config), không bám tên disk ghi cứng
// ---------------------------------------------------------------------------

/**
 * Bài kiểm chính của #2175, viết theo HÀNH VI chứ không đọc mã nguồn.
 *
 * Cùng một key nằm trên CẢ HAI disk với byte khác nhau, nên "trả 200" chưa đủ
 * để qua bài: chỉ byte của disk mà `filesystems.uploads` chỉ định mới đúng.
 * Với `$disk = Storage::disk('s3')` ghi cứng, hàng `uploads=public` trả byte
 * của s3 và bài đỏ ngay ở phép so byte.
 */
it('#2175: stream byte từ disk mà filesystems.uploads chỉ định', function (string $uploads, string $other) {
    config()->set('filesystems.uploads', $uploads);
    Storage::fake($uploads);
    Storage::fake($other);

    Storage::disk($uploads)->put('branches/logos/shop.png', 'BYTES-ON-UPLOAD-DISK');
    Storage::disk($other)->put('branches/logos/shop.png', 'BYTES-ON-OTHER-DISK');

    $res = $this->get('/api/v1/media/branches/logos/shop.png');

    $res->assertOk();
    expect($res->streamedContent())->toBe('BYTES-ON-UPLOAD-DISK');
})->with([
    'uploads=public' => ['public', 's3'],
    'uploads=s3' => ['s3', 'public'],
]);

it('#2175: 404 khi object CHỈ nằm trên disk không phải đích upload', function () {
    config()->set('filesystems.uploads', 'public');
    Storage::fake('public');
    Storage::fake('s3');

    Storage::disk('s3')->put('branches/logos/only-on-s3.png', 'LEFTOVER');

    $this->get('/api/v1/media/branches/logos/only-on-s3.png')->assertNotFound();
});

it('#2175: cổng files-row khớp disk theo config', function (string $uploads) {
    config()->set('filesystems.uploads', $uploads);
    Storage::fake($uploads);

    Storage::disk($uploads)->put('uploads/temp/org-1/row-gate.jpg', 'ROW-GATE-BYTES');
    File::factory()->permanent()->create([
        'disk' => $uploads,
        'collection' => 'gallery',
        'path' => 'uploads/temp/org-1/row-gate.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $res = $this->get('/api/v1/media/uploads/temp/org-1/row-gate.jpg');

    $res->assertOk();
    expect($res->streamedContent())->toBe('ROW-GATE-BYTES');
})->with(['public', 's3']);
