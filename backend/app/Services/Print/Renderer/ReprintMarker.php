<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 2 (#1932) — DẤU TRÊN GIẤY.
 *
 * Đối ứng của `printReprintMarker` (workstation `print_reprint_marker.go`).
 *
 * Chủ sản phẩm chốt ngày 2026-07-28: hệ thống KHÔNG BAO GIỜ được từ chối in —
 * thu ngân đứng quầy với máy in kẹt giấy và khách đang đợi phải in lại được,
 * hết. Dòng này là thứ THAY CHO lời từ chối đó, và là thứ duy nhất trong cả
 * thiết kế thật sự chặn được điều mà lời từ chối nhắm tới: hai tờ giấy mà cả
 * hai đều trông như bản gốc.
 *
 * Vì vậy nó CỐ Ý không phụ thuộc vai trò, lý do, kết nối mạng hay bất cứ thứ gì
 * quán cấu hình được. Bản in thứ NHẤT không mang dấu — nếu bản nào cũng mang
 * thì cái dấu chẳng nói gì.
 *
 * ── Vì sao là một class riêng, và ai sở hữu nó ────────────────────────────
 *
 * Họ **docs** sở hữu (`emitDocReprintMarker`); họ **bill** mượn lại. Bên Go
 * cũng đúng một hàm dùng chung cho cả formatter cũ lẫn renderer template, chính
 * xác để hai bên không thể in ra hai cái dấu khác nhau.
 *
 * ── Bề rộng đo bằng CỘT, không phải mã điểm ───────────────────────────────
 *
 * 「再印刷 #2」 là 7 mã điểm nhưng **9 cột** trên đầu in nhiệt. Căn phải theo mã
 * điểm sẽ đẩy dấu ra khỏi mép giấy ở mọi phiếu tiếng Nhật — nên đây là chỗ
 * dùng `displayWidth`, khác với phần thân hoá đơn GTGT vốn đo bằng mã điểm.
 *
 * vi/en giữ ASCII ("BAN IN") có lý do: nửa fleet dùng máy in không có ROM
 * kanji và sẽ in ra một hàng ô vuông đúng chỗ cái dấu — tệ hơn hẳn việc dấu đó
 * bằng tiếng Anh.
 */
final class ReprintMarker
{
    /**
     * @param  int  $reprintNo  số bản in. `< 2` ⇒ KHÔNG in gì (bản gốc không mang dấu)
     * @param  string  $locale  locale của CẤU HÌNH quán — chọn chữ trên dấu
     */
    public static function emit(Escpos $encoder, int $width, int $reprintNo, string $locale): void
    {
        if ($reprintNo < 2) {
            return;
        }

        $mark = PrintLabels::forLocale($locale)->reprintMark.' #'.$reprintNo;

        $encoder->line(Layout::spaces($width - Layout::displayWidth($mark)).$mark);
    }
}
