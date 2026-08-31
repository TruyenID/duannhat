<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `PrintRenderProfile`
 * (workstation `internal/service/print_renderer.go`).
 *
 * Đặc tính của MÁY IN cho lượt render này, tách khỏi bối cảnh cửa hàng
 * ({@see PrintJobConfig}) và khỏi dữ liệu đơn ({@see PrintRenderData}).
 *
 * ── CỐ Ý THIẾU hai trường mà Go có ────────────────────────────────────────
 *
 * Go còn `TextMode` và `TextModeForBlock` — cắt phiếu thành đoạn native/raster
 * cho máy không có ROM kanji (TR-36). Ở đây KHÔNG có, và đó không phải bỏ sót:
 * T5.3 ghi rõ raster **ngoài phạm vi** vì chính Go cũng chưa có bộ mã hoá
 * bitmap thật (`internal/printer`, thuộc plan-052). Dựng một seam raster ở PHP
 * bây giờ là chế ra một hợp đồng không bên nào thực hiện được, rồi slice sau sẽ
 * phải gỡ.
 *
 * Việc CHIA ĐOẠN thì vẫn giữ ({@see PrintRenderResult}) — nó là bất biến "ghép
 * các đoạn lại phải ra đúng phiếu", và bất biến ấy có giá trị trước khi có
 * raster. Chỉ phần QUYẾT ĐỊNH chế độ là chưa có.
 */
final class PrintRenderProfile
{
    public function __construct(
        /** Số cột thật của máy in (32 = 58mm, 48 = 80mm). 0 → xem ladder ở PrintRenderer. */
        public readonly int $columns = 0,
        /** Tên cuộn giấy ("58mm"/"80mm") để tra bảng `paper` của definition. */
        public readonly string $paper = '',
        /**
         * #1950 — cách KẾT THÚC tờ giấy, lấy từ profile của máy in.
         *
         * Đối ứng của `PrintRenderProfile.Finishing *escpos.Finishing` bên Go,
         * kể cả ở chỗ nó là **con trỏ / nullable**: `null` nghĩa là "không có
         * profile" và tái hiện ĐÚNG byte hôm nay (`fullCut()` = `ESC d 3`), nên
         * cổng golden 126 ô và cổng parity Go↔PHP 117 ô không đổi một byte.
         *
         * Có set thì nó sửa ba thứ mà một lệnh cắt mù luôn làm sai: máy khai
         * `gs_v_partial` nhận cắt DÍNH (mẩu giấy giữ tờ phiếu khỏi rơi xuống
         * sàn), máy `none` (tear-bar) thôi nhận lệnh cắt nó không có dao để thi
         * hành, và máy `auto_cut_per_job` thôi nhả thêm một tờ TRẮNG sau mỗi
         * phiếu.
         *
         * Trước #1950 Cloud không có ô này, nên `CloudPrntJobRenderer` — thứ
         * dựng byte cho một máy CÓ THẬT — phát `ESC d 3` cho mọi máy, y hệt
         * workstation trước khi nó được sửa.
         */
        public readonly ?Finishing $finishing = null,
    ) {}
}
