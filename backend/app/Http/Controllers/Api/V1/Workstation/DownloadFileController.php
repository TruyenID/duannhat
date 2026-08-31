<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/v1/workstation/downloads/{version}/{filename} — phục vụ binary
 * phát hành QUA namespace /api/*.
 *
 * Vì sao không đưa máy trạm URL file tĩnh: production `tempo.godx.jp` chạy
 * hai CDN (#2453) — chỉ `/api/*` tới Laravel, `/` qua CloudFront/Next.js —
 * nên URL tuyệt đối dựng từ APP_URL cho `/downloads/...` trả 404 (đo
 * 2026-08-18 20:09 tại Tsukiji, assisted update chết ngay khi HQ ra lệnh
 * cập nhật). Máy trạm vốn chỉ biết MỘT base (cloud_api_url) và mọi byte khác
 * đã đi qua đó; cho file đi cùng đường thì không còn gì để cấu hình — không
 * env host thứ hai, không lệ thuộc CDN route ngoài /api/*.
 *
 * PUBLIC có chủ đích: downloader của máy trạm gửi request trần (chưa chắc đã
 * pair xong lúc cần tải), và các binary này vốn công khai trên trang
 * /downloads (#3222). Chỉ throttle chống lạm dụng.
 */
class DownloadFileController extends Controller
{
    public function __invoke(string $version, string $filename): BinaryFileResponse
    {
        // Không tin URL segment: version/filename phải khớp đúng bảng chữ cái
        // của artifact phát hành, chặn mọi hình thức path traversal trước khi
        // chạm filesystem.
        abort_unless(preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/', $version) === 1, 404);
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $filename) === 1, 404);

        $root = public_path('downloads/workstation');
        $path = $root.'/'.$version.'/'.$filename;

        $real = realpath($path);
        abort_if($real === false, 404);
        abort_unless(str_starts_with($real, realpath($root).DIRECTORY_SEPARATOR), 404);

        return response()->file($real, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // Binary của một version là bất biến — cache thoải mái.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
