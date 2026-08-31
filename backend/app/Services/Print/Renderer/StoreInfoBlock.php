<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * #2000 bước 2 — các dòng cửa hàng khai thêm trong `store_info.fields`.
 *
 * MỘT nơi cho cả ba họ phiếu (bill · docs · shift). Không phải để gọn: ba bản
 * sao của cùng một vòng lặp là ba cơ hội để một bản quên một field, và kiểu hỏng
 * đó không kêu ở đâu — nó chỉ làm một loại phiếu thiếu một dòng.
 *
 * ## Vì sao `store_name` KHÔNG đi qua đây
 *
 * Mỗi họ vẫn tự in tên quán vô điều kiện, như hôm nay. Cho nó theo `fields`
 * nghĩa là cho phép publish một phiếu KHÔNG TÊN QUÁN — mà chính các emitter đó
 * đã có bản dự phòng `'Store'` để tránh đúng chuyện ấy. Một field mà bỏ đi cũng
 * không được phép có hiệu lực thì không nên giả vờ là tuỳ chọn, nên nó ở lại
 * danh sách nợ của `ParamFieldRenderableRatchetTest`
 * kèm lý do, thay vì được "sửa" bằng một nhánh giả.
 *
 * ## `store_phone` đã có đường (#2000 bước 3)
 *
 * Cloud gửi `phone` trong feed branch từ lâu; bước 3 dựng `PrintJobConfig::
 * $storePhone` và cho máy trạm lưu nó, nên khai field này giờ ra giấy thật.
 */
final class StoreInfoBlock
{
    /**
     * Các dòng đứng TRƯỚC dòng "chi nhánh + tiêu đề".
     *
     * Thứ tự in là thứ tự KHAI trong definition, không phải thứ tự của hàm này:
     * người thiết kế phiếu quyết định địa chỉ đứng trên hay dưới tên thương hiệu.
     *
     * Điểm cắt là vị trí của `store_name` trong `fields`. Không thêm ô cấu hình
     * nào cho việc này: thứ tự khai VỐN ĐÃ nói lên bố cục, và một ô riêng kiểu
     * `header_above: [...]` sẽ là nguồn sự thật thứ hai cho cùng một câu hỏi.
     *
     * Quy ước hoá đơn Nhật đặt 法人名 và thương hiệu lên trên tên cửa hàng, còn
     * địa chỉ và TEL xuống dưới — đúng thứ tự người ta sẽ khai một cách tự nhiên.
     */
    public static function emitAbove(PrintRenderContext $ctx): void
    {
        self::emit($ctx, before: true);
    }

    /** Các dòng đứng SAU dòng "chi nhánh + tiêu đề". */
    public static function emitBelow(PrintRenderContext $ctx): void
    {
        self::emit($ctx, before: false);
    }

    private static function emit(PrintRenderContext $ctx, bool $before): void
    {
        $block = null;
        foreach ($ctx->definition['blocks'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === 'store_info') {
                $block = $candidate;
                break;
            }
        }

        if ($block === null || ($block['enabled'] ?? true) !== true) {
            return;
        }

        $fields = $block['fields'] ?? [];
        if (! is_array($fields)) {
            return;
        }

        // `store_name` không được in ở đây (xem docblock lớp) — nó chỉ là MỐC.
        // Không có nó trong danh sách thì mọi field coi như đứng dưới, giữ đúng
        // hành vi trước khi có phép cắt này.
        $split = array_search('store_name', $fields, true);
        $pivot = $split === false ? -1 : (int) $split;

        foreach ($fields as $index => $field) {
            if (($index < $pivot) !== $before) {
                continue;
            }

            $value = match ($field) {
                'store_organization' => $ctx->config->storeOrganization,
                'store_sub_name' => $ctx->config->storeSubName,
                'store_address' => $ctx->config->storeAddress,
                'store_phone' => $ctx->config->storePhone,
                default => '',
            };

            if ($value !== '') {
                $ctx->encoder->line($value);
            }
        }
    }
}
