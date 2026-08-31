<?php

declare(strict_types=1);

use App\Services\Workstation\WorkstationDownloadCatalog;
use Illuminate\Support\Facades\File;

/**
 * The workstation download PAGE moved to admin-web (Next). Laravel keeps the
 * two URLs alive as permanent redirects, because the old address is in
 * circulation: the workstation's own out-of-date warning tells a shop to "tải
 * bản mới từ trang /downloads", and shops have it written down.
 *
 * What is NOT tested here any more — rendering — is tested on the Next side
 * (`web/admin/src/app/downloads/`). What is still tested here is the catalogue
 * service, because the expected-build feed keeps reading it.
 */
it('301s /downloads to the Next page', function (): void {
    config()->set('workstation.downloads.page_url', 'https://admin.example.test/downloads');

    $response = $this->get('/downloads');

    $response->assertStatus(301);
    $response->assertRedirect('https://admin.example.test/downloads');
});

it('301s the /ws-downloads alias to the same place', function (): void {
    // The alias exists for hosts where Apache DirectorySlash beats the
    // public/.htaccess rule. It must not drift away from the canonical route.
    config()->set('workstation.downloads.page_url', 'https://admin.example.test/downloads');

    $response = $this->get('/ws-downloads');

    $response->assertStatus(301);
    $response->assertRedirect('https://admin.example.test/downloads');
});

it('refuses to guess a destination when the page URL is unconfigured', function (): void {
    // A 301 is cached by every browser that sees it, so a guessed host would be
    // both wrong and sticky. Fail loudly instead — and still say where the
    // files are, since they never moved.
    config()->set('workstation.downloads.page_url', null);

    $response = $this->get('/downloads');

    $response->assertStatus(503);
    $response->assertSee('/downloads/workstation/manifest.json', false);
});

/**
 * #2428 — the manifest is read from a temp file, never from the working tree.
 * `public/downloads/workstation/manifest.json` is TRACKED (the .gitignore
 * un-ignores it), and these tests used to overwrite it — and the missing-file
 * case below simply DELETED it with no restore at all, so one full-suite run
 * left a tracked file deleted in whatever tree it ran in.
 */
it('reads an empty catalog when manifest is missing', function (): void {
    // Point at a path that does not exist — do NOT delete the real manifest.
    config()->set(
        'workstation.downloads.manifest_path',
        sys_get_temp_dir().'/ws-manifest-absent-'.uniqid().'.json',
    );

    $catalog = app(WorkstationDownloadCatalog::class);
    $data = $catalog->read();

    expect($data['latest'])->toBeNull()
        ->and($data['versions'])->toBe([]);
});

it('still resolves download URLs for a published build', function (): void {
    // The redirect above moved the PAGE. These URLs are what the Next page
    // links to and what the assisted-update feed hands the workstation, so a
    // change of shape here is a change of contract.
    $path = tempnam(sys_get_temp_dir(), 'ws-downloads-manifest-').'.json';
    File::put($path, json_encode([
        'latest' => 'v0.3.0',
        'updated_at' => '2026-08-10T03:00:00Z',
        'versions' => [
            [
                'version' => 'v0.3.0',
                'released_at' => '2026-08-10T03:00:00Z',
                'commit' => 'abc123def456',
                'archived' => false,
                'platforms' => [[
                    'id' => 'windows-amd64.exe',
                    'filename' => 'ws-server-windows-amd64.exe',
                    'size' => 33_000_000,
                    'sha256' => str_repeat('a', 64),
                ]],
            ],
            [
                'version' => 'v0.2.0',
                'released_at' => '2026-07-01T00:00:00Z',
                'commit' => 'deadbeef0000',
                'archived' => true,
                'platforms' => [[
                    'id' => 'windows-amd64.exe',
                    'filename' => 'ws-server-windows-amd64.exe',
                    'size' => 100,
                    'sha256' => str_repeat('b', 64),
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    config()->set('workstation.downloads.manifest_path', $path);

    try {
        $catalog = app(WorkstationDownloadCatalog::class);

        // Một dạng URL duy nhất — archiveDownloadUrl đã gỡ: file không bao giờ
        // được move vào /archive/, và URL đó 404 trên production (2026-08-18).
        expect($catalog->downloadUrl('v0.3.0', 'ws-server-windows-amd64.exe'))
            ->toBe('/downloads/workstation/v0.3.0/ws-server-windows-amd64.exe')
            ->and($catalog->downloadUrl('v0.2.0', 'ws-server-windows-amd64.exe'))
            ->toBe('/downloads/workstation/v0.2.0/ws-server-windows-amd64.exe');

        $data = $catalog->read();
        expect($data['latest'])->toBe('v0.3.0')
            ->and($data['versions'])->toHaveCount(1)
            ->and($data['archive_versions'])->toHaveCount(1);
    } finally {
        File::delete($path);
    }
});
