<?php

declare(strict_types=1);

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderOrder;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\SystemTemplateDefaults;

/**
 * #2000 — bệnh #1949 một tầng sâu hơn: FIELD, không phải block.
 *
 * ## Lỗ hổng bài này bịt
 *
 * `CatalogRenderableRatchetTest` (#1949) so catalog BLOCK với registry: một block
 * trong catalog mà không emitter nào vẽ thì đỏ. Nó không nhìn xuống tầng `fields`
 * của khối `params`.
 *
 * Nên chuyện y hệt vẫn xảy ra, và đang xảy ra. Có HAI renderer:
 *
 *   `SlipComposer`  — bản XEM TRƯỚC trong trình soạn mẫu. Đọc `block['fields']`
 *                     tổng quát và vẽ MỌI field được khai.
 *   emitter ESC/POS — bản IN THẬT. `emitHeader` (`store_info`) không hề đọc
 *                     `fields`; `emitOrderMeta` có đọc nhưng `default => null`.
 *
 * Nghĩa là có một bề mặt **cho người ta xem thứ sẽ không xảy ra**. Bản mặc định
 * của `receipt` khai `store_sub_name`; tên brand hiện trong xem trước và không
 * bao giờ ra giấy.
 *
 * Đo ra thì nó rộng hơn thế: với CẢ 12 kind có plan, bật bất kỳ field cửa hàng
 * nào lên cũng không đổi một byte. Danh sách `fields` của khối này hiện là đồ
 * trang trí đối với bản in — không phải một field bị sót, mà là cả cơ chế chưa
 * được nối.
 *
 * Ba cổng hiện có đều mù, đúng ba lý do #1949 đã nêu cho tầng block: hai registry
 * khớp NHAU nên cùng thiếu vẫn xanh; parity so HASH nên hai bên cùng bỏ qua vẫn
 * khớp; lượt render thử lúc publish CHẠY THÀNH CÔNG, nó chỉ không vẽ gì.
 *
 * ## Phép đo là HÀNH VI, không phải đọc emitter
 *
 * Khai một field mà tờ giấy không đổi ⇒ không ai vẽ nó. Render hai lần — có và
 * không có field đó — rồi so byte.
 *
 * Cách này không cần biết emitter nào xử lý gì, không tin danh sách nào, và
 * không hỏng khi ai đó port một emitter sang chỗ khác. Nó đo đúng thứ người dùng
 * gặp: bật lên thì giấy có đổi không.
 *
 * ## Ghim NỢ, không đòi sạch
 *
 * Danh sách dưới là hiện trạng ĐO ĐƯỢC. Chỉ được CO LẠI:
 *
 *   - khai thêm một field không ai vẽ  ⇒ ĐỎ
 *   - vẽ được một field đang nợ mà quên gỡ khỏi danh sách ⇒ ĐỎ
 */

/**
 * Dữ liệu render với MỌI ô liên quan đã điền.
 *
 * Ô rỗng làm phép đo nói dối: một field được vẽ đúng nhưng giá trị rỗng cũng
 * khiến byte không đổi, và nó sẽ bị đếm nhầm là "không ai vẽ".
 */
function ratchetRenderData(string $kind): PrintRenderData
{
    return new PrintRenderData(
        kind: $kind,
        config: new PrintJobConfig(
            storeName: 'RATCHET-STORE',
            storeSubName: 'RATCHET-SUBNAME',
            storeAddress: 'RATCHET-ADDRESS',
            storePhone: 'RATCHET-PHONE',
            storeOrganization: 'RATCHET-ORG',
            currency: '¥',
            currencyCode: 'JPY',
            locale: 'ja',
        ),
        order: new PrintRenderOrder(orderCode: 'RATCHET-2026-0001', orderType: 'dine_in', tableNumber: 'R-9'),
        total: 1000,
    );
}

/**
 * Field nào của `store_info` mà BẬT LÊN cũng không đổi tờ giấy.
 *
 * Phạm vi cố ý hẹp: chỉ khối `store_info`, và chỉ bốn field cửa hàng mà
 * `param_fields` chào. Mở rộng ra mọi field sẽ trộn vào một thứ thứ ba không
 * phải phát hiện — field mà FIXTURE không có dữ liệu (khách hàng, ca thu ngân)
 * cũng khiến byte không đổi, và bị đếm nhầm thành "không ai vẽ".
 *
 * Đo bằng cách so `fields = [X]` với `fields = []`: đó đúng là câu hỏi người
 * dùng gặp — bật nó lên thì giấy có đổi không.
 *
 * @return list<string>
 */
function fieldsThatDrawNothing(PrintTemplateKind $kind): array
{
    $definition = app(SystemTemplateDefaults::class)->forKind($kind);
    $renderer = app(PrintRenderer::class);
    $profile = new PrintRenderProfile(columns: 48);

    $render = static function (array $def) use ($renderer, $kind, $profile): ?string {
        try {
            return $renderer->render($def, ratchetRenderData($kind->value), $profile, 'ja')->bytes();
        } catch (Throwable) {
            // Kind cần dữ liệu mà bài này không dựng (ca thu ngân, hoá đơn đỏ…).
            // Bỏ qua chứ đừng đoán: một ngoại lệ ở đây nói về FIXTURE, không nói
            // gì về việc emitter có vẽ field hay không.
            return null;
        }
    };

    $baseline = $render($definition);
    if ($baseline === null) {
        return [];
    }

    // Bốn field cửa hàng mà catalog chào cho khối `store_info`.
    $storeFields = array_values(array_filter(
        (array) config('print_blocks.param_fields'),
        static fn ($f) => str_starts_with((string) $f, 'store_'),
    ));

    $missing = [];

    foreach ($definition['blocks'] ?? [] as $index => $block) {
        if (! is_array($block) || ($block['id'] ?? null) !== 'store_info') {
            continue;
        }
        if (($block['enabled'] ?? true) !== true) {
            continue;
        }

        $empty = $definition;
        $empty['blocks'][$index]['fields'] = [];
        $emptyBytes = $render($empty);
        if ($emptyBytes === null) {
            continue;
        }

        foreach ($storeFields as $field) {
            $only = $definition;
            $only['blocks'][$index]['fields'] = [$field];

            $bytes = $render($only);
            if ($bytes !== null && $bytes === $emptyBytes) {
                $missing[] = (string) $field;
            }
        }
    }

    sort($missing);

    return array_values(array_unique($missing));
}

it('không field nào được khai mà tờ giấy KHÔNG đổi', function () {
    /**
     * Nợ đã biết, ĐO ĐƯỢC chứ không gõ tay. Bước 2 co nó từ BỐN field xuống HAI;
     * bước 3 co tiếp xuống MỘT. `store_sub_name`, `store_address`, `store_phone`
     * đều ra giấy khi definition khai chúng.
     *
     * `store_name` là cái cuối cùng, và nó ở lại vì LÝ DO THIẾT KẾ, không phải
     * vì chưa làm tới: nó vẫn in vô điều kiện. Cho nó theo `fields` nghĩa là cho
     * phép publish một phiếu KHÔNG TÊN QUÁN — mà chính emitter đó đã có bản dự
     * phòng `'Store'` để tránh đúng chuyện ấy. Phép đo hỏi "bật field lên có đổi
     * giấy không" và câu trả lời là không, nên nó nằm đây; "sửa" bằng một nhánh
     * giả sẽ tệ hơn là để một món nợ có tên.
     *
     * Nếu bao giờ danh sách này rỗng, hãy nghĩ kỹ trước khi mừng: nhiều khả năng
     * ai đó vừa cho `store_name` tắt được.
     *
     * `kitchen` GIA NHẬP danh sách khi phiếu bếp và phiếu hall được đưa về CÙNG
     * một template (khác nhau đúng ở QR). Trước đó nó vắng mặt vì không có plan
     * nên render ném; giờ nó render bình thường và mang đúng món nợ
     * `store_name` như mười hai kind kia. Đây là một dòng THÊM vào vì vùng phủ
     * rộng ra, không phải một lỗi mới.
     */
    $storeFields = ['store_name'];

    $debt = array_fill_keys([
        'chain_report', 'debt_slip', 'delta_qr', 'kitchen',
        'qualified_simplified_invoice',
        'receipt', 'red_invoice', 'remaining', 'runner',
        'shift_open', 'shift_report', 'table_paid', 'vat_invoice',
    ], $storeFields);

    $found = [];

    foreach (PrintTemplateKind::cases() as $kind) {
        $missing = fieldsThatDrawNothing($kind);
        if ($missing !== []) {
            $found[$kind->value] = $missing;
        }
    }

    ksort($found);
    ksort($debt);

    expect($found)->toBe($debt, implode("\n", [
        'Tập field-được-khai-mà-không-vẽ-gì đã đổi.',
        '',
        'Đo được:   '.json_encode($found, JSON_UNESCAPED_UNICODE),
        'Đang ghim: '.json_encode($debt, JSON_UNESCAPED_UNICODE),
        '',
        'Thêm vào ⇒ bạn vừa khai một field không ai vẽ. Nó HIỆN trong bản xem',
        '           trước của trình soạn mẫu rồi biến mất trên giấy, không lỗi ở',
        '           đâu — người phát hiện là chủ quán cầm tờ hoá đơn thiếu dòng.',
        'Bớt đi  ⇒ bạn vừa sửa một cái: gỡ nó khỏi danh sách trên.',
    ]));
});
