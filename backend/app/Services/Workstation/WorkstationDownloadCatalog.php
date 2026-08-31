<?php

declare(strict_types=1);

namespace App\Services\Workstation;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Reads workstation release metadata staged under public/downloads/workstation/.
 *
 * Binaries are published by `.github/workflows/workstation-release.yml`. Used by
 * the public /downloads page and by the expected-build feed (assisted update:
 * absolute URLs + sha256 so the workstation can download without guessing).
 */
final class WorkstationDownloadCatalog
{
    private const MANIFEST_RELATIVE = 'downloads/workstation/manifest.json';

    /**
     * Where the manifest is READ from. The download URLs this class builds are
     * unaffected — Apache serves those out of `public/` either way.
     *
     * Configurable so tests point at a temp file instead of overwriting the
     * tracked `public/downloads/workstation/manifest.json` in the working tree
     * (#2428); an interrupted run used to leave a fake manifest behind.
     */
    private function manifestPath(): string
    {
        $configured = config('workstation.downloads.manifest_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : public_path(self::MANIFEST_RELATIVE);
    }

    /** @var list<array{id: string, label: string}> */
    public const PLATFORMS = [
        ['id' => 'linux-amd64', 'label' => 'Linux (x64)'],
        ['id' => 'linux-arm64', 'label' => 'Linux (ARM64)'],
        ['id' => 'darwin-amd64', 'label' => 'macOS (Intel)'],
        ['id' => 'darwin-arm64', 'label' => 'macOS (Apple Silicon)'],
        ['id' => 'windows-amd64.exe', 'label' => 'Windows (x64)'],
    ];

    /**
     * @return array{
     *   latest: ?string,
     *   updated_at: ?string,
     *   versions: list<array<string, mixed>>,
     *   archive_versions: list<array<string, mixed>>
     * }
     */
    public function read(): array
    {
        $path = $this->manifestPath();

        if (! File::isFile($path)) {
            return [
                'latest' => null,
                'updated_at' => null,
                'versions' => [],
                'archive_versions' => [],
            ];
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Workstation download manifest is not valid JSON.');
        }

        $versions = [];
        $archive = [];

        foreach ($decoded['versions'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $normalized = $this->normalizeVersion($entry);
            if ($normalized['archived']) {
                $archive[] = $normalized;
            } else {
                $versions[] = $normalized;
            }
        }

        return [
            'latest' => is_string($decoded['latest'] ?? null) ? $decoded['latest'] : null,
            'updated_at' => is_string($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : null,
            'versions' => $versions,
            'archive_versions' => $archive,
        ];
    }

    public function downloadUrl(string $version, string $filename): string
    {
        return '/downloads/workstation/'.rawurlencode($version).'/'.rawurlencode($filename);
    }

    /**
     * URL tải cho MÁY TRẠM đi qua /api/* — cùng con đường mọi byte khác của
     * nó. Trên production APP_URL (`tempo.godx.jp`) chạy hai CDN (#2453): chỉ
     * `/api/*` tới Laravel, nên URL file tĩnh dựng từ APP_URL 404 (đo
     * 2026-08-18, assisted update chết đúng lúc HQ ra lệnh cập nhật). Route
     * đích: DownloadFileController — public, throttle, chặn traversal.
     */
    private function assistedDownloadUrl(string $version, string $filename): string
    {
        return url('/api/v1/workstation/downloads/'.rawurlencode($version).'/'.rawurlencode($filename));
    }

    /**
     * KHÔNG có biến thể URL `/archive/` — file KHÔNG BAO GIỜ được move.
     *
     * Bản trước trả `/downloads/workstation/archive/<ver>/<file>` cho entry
     * mang `archived: true`, nhưng gắn cờ archive chỉ đổi manifest — trên đĩa
     * production mọi binary vẫn nằm nguyên tại `/downloads/workstation/<ver>/`
     * (routes/web.php nói thẳng: "The FILES under /downloads/workstation/ are
     * untouched"). Hệ quả đo được 2026-08-18 tại Tsukiji: expected-build trỏ
     * v0.8.31 (vừa bị 0.8.32 đẩy sang archived) → assisted update tải URL
     * /archive/ → 404 → máy trạm KHÔNG tự cập nhật được, đúng lúc HQ ra lệnh.
     * Đường ghim một bản cũ để HÃM bản lỗi (#3173) chết theo cùng cách.
     */

    /**
     * Resolve a version's platforms with absolute download URLs for assisted update.
     *
     * Searches both active and archived entries. Missing version → null (fail-safe:
     * expected-build still returns the HQ version warning; client falls back to
     * the /downloads page).
     *
     * @return array{
     *   version: string,
     *   platforms: list<array{id: string, url: string, sha256: string, size: int}>
     * }|null
     */
    public function packageForVersion(string $version): ?array
    {
        $catalog = $this->read();

        $entry = null;
        foreach (array_merge($catalog['versions'], $catalog['archive_versions']) as $candidate) {
            if (($candidate['version'] ?? '') === $version) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            return null;
        }

        $platforms = [];

        foreach ($entry['platforms'] ?? [] as $platform) {
            if (! is_array($platform)) {
                continue;
            }

            $id = (string) ($platform['id'] ?? '');
            $filename = (string) ($platform['filename'] ?? '');
            if ($id === '' || $filename === '') {
                continue;
            }

            // Archived hay không, file vẫn nằm ở cùng một chỗ — xem chú thích
            // phía trên downloadUrl().
            $platforms[] = [
                'id' => $id,
                'url' => $this->assistedDownloadUrl($version, $filename),
                'sha256' => (string) ($platform['sha256'] ?? ''),
                'size' => (int) ($platform['size'] ?? 0),
            ];
        }

        return [
            'version' => $version,
            'platforms' => $platforms,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeVersion(array $entry): array
    {
        $platforms = [];
        foreach ($entry['platforms'] ?? [] as $platform) {
            if (! is_array($platform)) {
                continue;
            }
            $bundle = null;
            if (isset($platform['bundle']) && is_array($platform['bundle'])) {
                $bundle = [
                    'filename' => (string) ($platform['bundle']['filename'] ?? ''),
                    'size' => (int) ($platform['bundle']['size'] ?? 0),
                    'sha256' => (string) ($platform['bundle']['sha256'] ?? ''),
                ];
            }

            $platforms[] = [
                'id' => (string) ($platform['id'] ?? ''),
                'filename' => (string) ($platform['filename'] ?? ''),
                'size' => (int) ($platform['size'] ?? 0),
                'sha256' => (string) ($platform['sha256'] ?? ''),
                'label' => $this->platformLabel((string) ($platform['id'] ?? '')),
                // Shop-facing zip/tar (start.bat / start.command). Null on older manifests.
                'bundle' => $bundle,
            ];
        }

        return [
            'version' => (string) ($entry['version'] ?? ''),
            'released_at' => (string) ($entry['released_at'] ?? ''),
            'commit' => (string) ($entry['commit'] ?? ''),
            'archived' => (bool) ($entry['archived'] ?? false),
            'platforms' => $platforms,
        ];
    }

    private function platformLabel(string $id): string
    {
        foreach (self::PLATFORMS as $platform) {
            if ($platform['id'] === $id) {
                return $platform['label'];
            }
        }

        return $id;
    }
}
