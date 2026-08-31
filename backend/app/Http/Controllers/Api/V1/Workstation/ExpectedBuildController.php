<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Services\Workstation\WorkstationDownloadCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * #1806 S4 — HQ đẩy xuống "bản phát hành mong đợi".
 *
 * ## Feed RIÊNG, không nhét vào `branch`
 *
 * Feed `branch` mang cấu hình quán: giờ mở cửa, máy in, thuế. Phiên bản phần
 * mềm đổi theo một nhịp hoàn toàn khác — mỗi lần ta phát hành, không phải mỗi
 * lần chủ quán sửa cài đặt. Gộp chúng làm hai thứ kéo nhau invalidate cache và
 * làm cả hai khó suy luận.
 *
 * ## Fail-safe, không fail-closed
 *
 * Không cấu hình gì ⇒ trả `version: null` ⇒ máy trạm **không cảnh báo**. Endpoint
 * này không bao giờ trả lỗi vì thiếu cấu hình: một quán mất đường tới HQ vẫn
 * phải bán được hàng, và biến "không biết mình có cũ không" thành "không bán
 * được" là tự tạo sự cố tệ hơn thứ đang cảnh báo.
 *
 * ## Chỉ NÓI + cung cấp artifact, không ép nâng cấp
 *
 * Không có trường nào ở đây ra lệnh nâng cấp, và đó là chủ đích. Field `package`
 * (khi version có trong manifest công khai) chỉ cung cấp URL tuyệt đối + sha256
 * để client tải assisted — cài/khởi động lại vẫn do người vận hành bấm, và chỉ
 * khi không còn ca mở. Thiếu entry trong manifest ⇒ `package: null` (fail-safe:
 * vẫn cảnh báo lệch version; UI hướng sang trang `/downloads`).
 */
final class ExpectedBuildController extends Controller
{
    public function __construct(
        private readonly WorkstationDownloadCatalog $downloadCatalog,
    ) {}

    #[OA\Get(
        path: '/api/v1/workstation/expected-build',
        summary: 'Bản phát hành mà HQ mong đợi máy trạm đang chạy (#1806 S4)',
        tags: ['Workstation'],
        responses: [new OA\Response(response: 200, description: 'Có thể trả version = null')],
    )]
    public function index(Request $request): JsonResponse
    {
        $cfg = $this->expectedBuildFor($request);

        $version = is_string($cfg['version'] ?? null) && trim($cfg['version']) !== ''
            ? trim($cfg['version'])
            : null;

        return response()->json([
            'data' => [
                'version' => $version,
                // Mức đi kèm phiên bản, không suy từ khoảng cách phiên bản —
                // xem `config/workstation.php`.
                'severity' => $version === null ? null : $this->normalizeSeverity($cfg['severity'] ?? null),
                'reason' => $version === null ? null : ($cfg['reason'] ?? null),
                // Artifact cho assisted download — không phải lệnh nâng cấp.
                'package' => $version === null ? null : $this->resolvePackage($version),
                // #2635 — cờ RIÊNG cho tự-cài không người trông trong cửa sổ
                // đêm của quán. KHÔNG suy từ severity (xem config/workstation
                // .php); không có version thì không có gì để tự cài ⇒ false.
                'auto_apply' => $version !== null && $this->normalizeAutoApply($cfg['auto_apply'] ?? null),
            ],
        ]);
    }

    /**
     * Một chỗ đọc DUY NHẤT cho giá trị mong đợi.
     *
     * Hôm nay là toàn nền tảng. Rollout theo nhóm — lý do chính khiến ruling
     * chọn hướng HQ-đẩy-xuống — cần một giá trị per-org, và khi làm thì đây là
     * chỗ duy nhất phải sửa. Ghi ra để người tiếp theo không phải đi tìm, và để
     * không ai nhầm ý định với hiện trạng.
     *
     * @return array<string, mixed>
     */
    private function expectedBuildFor(Request $request): array
    {
        return (array) config('workstation.expected_build', []);
    }

    private function normalizeSeverity(mixed $value): string
    {
        $severity = is_string($value) ? strtolower(trim($value)) : '';

        // Giá trị lạ hạ về `info`, không ném lỗi: một lỗi gõ trong cấu hình
        // không được phép làm câm cả feed — nhưng cũng không được phép tự
        // nâng thành `critical` và dạy người vận hành bỏ qua màu đỏ.
        return in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : 'info';
    }

    /**
     * Cờ tự-cài chuẩn hoá về bool, fail-safe về `false`.
     *
     * `.env` cho ra chuỗi ("true"/"1"/"false"/""), config cache có thể giữ bool
     * thật. Giá trị lạ hạ về `false` — chiều an toàn là KHÔNG khởi động lại máy
     * khi không ai đứng đó, ngược với severity (hạ về `info` để không câm feed).
     */
    private function normalizeAutoApply(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value)
            && filter_var((string) $value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{
     *   version: string,
     *   platforms: list<array{id: string, url: string, sha256: string, size: int}>
     * }|null
     */
    private function resolvePackage(string $version): ?array
    {
        try {
            return $this->downloadCatalog->packageForVersion($version);
        } catch (Throwable) {
            // Manifest hỏng / thiếu không được phép làm câm cả feed expected-build.
            return null;
        }
    }
}
