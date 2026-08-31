<?php

use App\Casts\RebaseStorageUrl;
use App\Models\Branch;

beforeEach(function () {
    $this->cast = new RebaseStorageUrl;
    $this->model = new Branch;
});

/**
 * Trỏ đích upload vào một disk và đặt URL công khai của chính disk đó — đúng
 * cặp mà cast phải đọc (#2175). Đồng thời đặt `s3.url` thành một host CŨ khác
 * hẳn, để bài nào vô tình đọc `filesystems.disks.s3.url` sẽ ra kết quả sai
 * thay vì tình cờ đúng.
 */
function useUploadDisk(string $disk, ?string $url): void
{
    config()->set('filesystems.uploads', $disk);
    config()->set("filesystems.disks.{$disk}.url", $url);

    if ($disk !== 's3') {
        config()->set('filesystems.disks.s3.url', 'https://stale-s3.example.com/tempo');
    }
}

it('rebases a stored trycloudflare URL when the live host has rotated', function () {
    useUploadDisk('s3', 'https://current-tunnel.trycloudflare.com/tempo');

    $stored = 'https://dead-tunnel.trycloudflare.com/tempo/branches/logos/abc.png';

    expect($this->cast->get($this->model, 'logo', $stored, []))
        ->toBe('https://current-tunnel.trycloudflare.com/tempo/branches/logos/abc.png');
});

it('handles stored URLs that do not include the /tempo base path', function () {
    useUploadDisk('s3', 'https://current-tunnel.trycloudflare.com/tempo');

    $stored = 'https://dead-tunnel.trycloudflare.com/branches/banners/xyz.jpg';

    expect($this->cast->get($this->model, 'img_branches', $stored, []))
        ->toBe('https://current-tunnel.trycloudflare.com/tempo/branches/banners/xyz.jpg');
});

it('rebases ANY stored host that differs from the live upload-disk host', function () {
    // The contract for #image fix follow-up: cast must not be specific to
    // trycloudflare.com. Whatever tunnel provider / CDN / localhost the
    // URL was seeded against, the cast snaps it back to the live host.
    useUploadDisk('s3', 'https://live-cdn.tempo.io/tempo');

    $cases = [
        // ngrok → live CDN
        'https://abcd.ngrok-free.dev/tempo/branches/logos/a.png' => 'https://live-cdn.tempo.io/tempo/branches/logos/a.png',
        // localhost dev → live CDN
        'http://localhost:9000/tempo/branches/banners/b.jpg' => 'https://live-cdn.tempo.io/tempo/branches/banners/b.jpg',
        // Older CDN → newer CDN
        'https://old-cdn.example.com/tempo/branches/logos/c.png' => 'https://live-cdn.tempo.io/tempo/branches/logos/c.png',
        // Stored URL without base path
        'https://something.test/branches/banners/d.jpg' => 'https://live-cdn.tempo.io/tempo/branches/banners/d.jpg',
    ];

    foreach ($cases as $stored => $expected) {
        expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($expected);
    }
});

it('passes through third-party CDN URLs that are not our stored objects', function () {
    // Regression: branch banners/logos can be seeded with a direct external
    // CDN URL (e.g. an Unsplash demo image). Its host differs from the live
    // host, but it is NOT one of our objects — rebasing it onto our storage
    // would 404 and the banner would silently break. Only `branches/`-prefixed
    // keys rebase.
    useUploadDisk('s3', 'https://current-tunnel.trycloudflare.com/tempo');

    $external = [
        'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1200&q=80',
        'https://cdn.example.com/assets/hero.png',
        'https://lh3.googleusercontent.com/a/abc123',
    ];

    foreach ($external as $stored) {
        expect($this->cast->get($this->model, 'img_branches', $stored, []))->toBe($stored);
    }
});

it('passes through values whose host already matches the live host (no-op)', function () {
    useUploadDisk('s3', 'https://live-cdn.tempo.io/tempo');

    // Already correct — preserve as-is, including any query string / port /
    // fragment that the storage helper might have added.
    $stored = 'https://live-cdn.tempo.io/tempo/branches/logos/abc.png?v=2';
    expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($stored);
});

it('passes through values that are not full http(s) URLs (relative paths, data URIs)', function () {
    useUploadDisk('s3', 'https://current.trycloudflare.com/tempo');

    $cases = [
        '/local/relative/path.png',
        'branches/logos/abc.png',
        'data:image/png;base64,iVBORw0KGgo=',
    ];

    foreach ($cases as $stored) {
        expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($stored);
    }
});

it('passes through null and empty values', function () {
    expect($this->cast->get($this->model, 'logo', null, []))->toBeNull();
    expect($this->cast->get($this->model, 'logo', '', []))->toBe('');
});

it('passes through when the upload disk publishes no url (nothing to rebase onto)', function () {
    useUploadDisk('s3', '');

    $stored = 'https://dead-tunnel.trycloudflare.com/tempo/x.png';

    expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($stored);
});

it('passes through when the upload disk url is malformed (no scheme://host)', function () {
    // Defensive: if config injected something nonsensical, prefer keeping
    // the stored URL over emitting garbage.
    useUploadDisk('s3', 'not-a-url');

    $stored = 'https://dead-tunnel.trycloudflare.com/tempo/x.png';

    expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($stored);
});

it('writes values unchanged so seeders can store fully-qualified URLs', function () {
    useUploadDisk('s3', 'https://live-cdn.tempo.io/tempo');

    $value = 'https://dead-tunnel.trycloudflare.com/tempo/x.png';

    expect($this->cast->set($this->model, 'logo', $value, []))->toBe($value);
});

// ---------------------------------------------------------------------------
// #2175 — base phải đến từ đích GHI (`filesystems.uploads`), không từ `s3`
// ---------------------------------------------------------------------------

/**
 * Đây là hình dạng THẬT của prod: `UPLOADS_DISK` mặc định `public`, nên ảnh
 * tải lên sau #2163 nằm ở `{APP_URL}/storage/branches/…`. Cast cũ đọc
 * `filesystems.disks.s3.url` nên không bao giờ dựng lại được host cho chúng —
 * lưới an toàn "đổi host vẫn xem được ảnh" đơn giản là TẮT với mọi ảnh mới.
 */
it('#2175: rebase ảnh trên disk public khi host công khai đã đổi', function () {
    useUploadDisk('public', 'https://api-new.tempo.example/storage');

    $stored = 'https://dead-tunnel.trycloudflare.com/storage/branches/logos/abc.png';

    expect($this->cast->get($this->model, 'logo', $stored, []))
        ->toBe('https://api-new.tempo.example/storage/branches/logos/abc.png');
});

it('#2175: KHÔNG dùng s3.url khi đích upload là disk khác', function () {
    // `s3.url` được useUploadDisk() đặt thành một host cũ hoàn toàn khác. Bản
    // cũ lấy base từ đó ⇒ hoặc dựng ra URL trỏ vào kho không chứa object,
    // hoặc (như thực tế) không khớp prefix và trả nguyên giá trị chết.
    useUploadDisk('public', 'https://api-new.tempo.example/storage');

    $stored = 'https://dead-tunnel.trycloudflare.com/storage/branches/banners/x.jpg';

    $result = (string) $this->cast->get($this->model, 'logo', $stored, []);

    // `toContain` nhận needle biến thiên — truyền thông báo vào đó biến nó
    // thành needle thứ hai và `not` khi đó luôn đúng. Dùng str_contains.
    expect(str_contains($result, 'stale-s3.example.com'))->toBeFalse(
        'Cast vẫn lấy base từ filesystems.disks.s3.url thay vì đích upload.',
    );
    expect($result)->toStartWith('https://api-new.tempo.example/storage/');
});

it('#2175: pass-through khi đích upload là disk private không có url', function () {
    // `local` là disk private, không publish url — không có host nào để rebase.
    config()->set('filesystems.uploads', 'local');

    $stored = 'https://dead-tunnel.trycloudflare.com/storage/branches/logos/abc.png';

    expect($this->cast->get($this->model, 'logo', $stored, []))->toBe($stored);
});
