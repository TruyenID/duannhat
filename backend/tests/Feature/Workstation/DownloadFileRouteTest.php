<?php

use Illuminate\Support\Facades\File;

/**
 * GET /api/v1/workstation/downloads/{version}/{filename} — đường tải binary
 * cho assisted update, đi qua /api/* vì đó là đường duy nhất chắc chắn tới
 * Laravel trên host hai CDN (#2453). Public + throttle; chặn traversal.
 */
beforeEach(function () {
    $this->root = public_path('downloads/workstation/v0.0.0');
    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/ws-server-test-bin', 'BINARY-BYTES');
});

afterEach(function () {
    File::deleteDirectory(public_path('downloads/workstation/v0.0.0'));
});

it('serves a released binary as an attachment', function () {
    $res = $this->get('/api/v1/workstation/downloads/v0.0.0/ws-server-test-bin');

    $res->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename="ws-server-test-bin"');
});

it('404s an unknown version or filename', function () {
    $this->get('/api/v1/workstation/downloads/v9.9.9/ws-server-test-bin')->assertNotFound();
    $this->get('/api/v1/workstation/downloads/v0.0.0/nope')->assertNotFound();
});

it('refuses traversal-shaped segments before touching the filesystem', function () {
    // Route param không khớp regex version/filename ⇒ 404, kể cả khi encode.
    $this->get('/api/v1/workstation/downloads/v0.0.0/..%2F..%2Findex.php')->assertNotFound();
    $this->get('/api/v1/workstation/downloads/not-a-version/ws-server-test-bin')->assertNotFound();
});
