<?php

/**
 * Plan 015 — Arch tests for OrderItemTopping ownership.
 *
 * OrderItemTopping is an append-only snapshot. The only writer should be
 * `CustomerOrderService::addItems` (Phase 2 entry point). Any other code
 * path that writes — controllers, sidecar services, blue-sky helpers —
 * silently breaks the snapshot invariant (price drift, missing audit,
 * cross-line bleed).
 *
 * This test asserts: no controller, no sidecar service, and no model
 * factory uses `OrderItemTopping::create` / `::updateOrCreate` /
 * `::insert` outside the canonical service path.
 */
/**
 * Người ghi được PHÉP. Tiền tố đường dẫn, khớp bằng `str_starts_with`.
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI — bánh cóc bên dưới cưỡng chế.
 *
 * `app/Omnify/Modules/OrderItemTopping/` đã rời khỏi đây 2026-08-18. Nó là một
 * tiền tố THƯ MỤC, tức cho phép trọn cả module sinh tự động, mà đo lại thì module
 * đó chứa 6 file (Locales · Models · Policies · Requests ×2 · Resources), KHÔNG
 * có service và **0 lệnh ghi** `OrderItemTopping::create|updateOrCreate|insert`.
 * Nó không trừ gì hôm nay; cái nó làm là cho phép TRƯỚC — lần regen nào sinh ra
 * một service base có lệnh ghi sẽ đi thẳng qua rào này, im lặng, đúng chỗ rào
 * sinh ra để canh.
 *
 * @var list<string>
 */
const ORDER_ITEM_TOPPING_ALLOWED_WRITERS = [
    // Canonical writers (Plan 047 order persistence boundary).
    'app/Services/Order/Internal/Concerns/WritesCustomerOrders.php',
    'app/Services/Order/Internal/EloquentOrderPersistence.php',
];

/**
 * Mã của một file PHP, ĐÃ BỎ comment.
 *
 * Chỉ soi CODE, không soi văn xuôi: một docblock nhắc tên method
 * (`App\Services\Order\Contracts\PricedToppingSelection` giải thích vì sao `rows`
 * giữ hình dạng mảng thô) không ghi gì vào `order_item_toppings`, nhưng regex
 * trên nguyên file thì không phân biệt được — và test đỏ vì một câu giải thích
 * là test dạy người ta gỡ lời giải thích.
 */
function orderItemToppingCodeWithoutComments(string $path): string
{
    return implode('', array_map(
        fn (array|string $token) => is_string($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? (is_string($token) ? $token : $token[1])
            : '',
        token_get_all(file_get_contents($path)),
    ));
}

/**
 * @return list<string> "<đường dẫn tương đối> → OrderItemTopping::<method>"
 *                      cho MỌI file dưới `app/`, kể cả người ghi được phép
 */
function orderItemToppingWriteSites(): array
{
    $sites = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($rii as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = str_replace(base_path().'/', '', $file->getPathname());
        $contents = orderItemToppingCodeWithoutComments($file->getPathname());

        foreach (['create', 'updateOrCreate', 'insert'] as $method) {
            if (preg_match('/\bOrderItemTopping::'.$method.'\s*\(/', $contents)) {
                $sites[] = "{$rel} → OrderItemTopping::{$method}";
            }
        }
    }

    sort($sites);

    return $sites;
}

test('OrderItemTopping writes only happen inside CustomerOrderService', function () {
    $offenders = [];

    foreach (orderItemToppingWriteSites() as $site) {
        $rel = explode(' → ', $site)[0];

        foreach (ORDER_ITEM_TOPPING_ALLOWED_WRITERS as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                continue 2;
            }
        }

        $offenders[] = $site;
    }

    expect($offenders)->toBeEmpty(
        'OrderItemTopping must only be written inside CustomerOrderService::addItems. Offenders: '.PHP_EOL.implode(PHP_EOL, $offenders),
    );
});

/**
 * BÁNH CÓC — danh sách người ghi được phép CHỈ ĐƯỢC CO LẠI.
 *
 * Một mục ở đây là giấy phép ghi vào một bảng snapshot append-only. Giấy phép
 * cấp cho một chỗ KHÔNG ghi gì thì không phải giấy phép thừa vô hại: nó là một
 * ô trống đã ký sẵn, và với tiền tố THƯ MỤC thì nó ký sẵn cho mọi file chưa ra
 * đời bên dưới — kể cả file do generator sinh ở lượt regen sau.
 */
test('bánh cóc — người ghi được phép mà KHÔNG ghi gì thì phải bị xoá', function () {
    $sites = orderItemToppingWriteSites();

    // Bộ quét hỏng ⇒ 0 site ⇒ bánh cóc tố oan mọi mục, còn bài chính thì xanh
    // vì không đo gì. Ghim mẫu số trước.
    // (Một file góp TỐI ĐA một site mỗi method, nên hai người ghi chuẩn = 2.)
    expect(count($sites))->toBeGreaterThanOrEqual(2, 'bộ quét không thấy lệnh ghi nào — regex/token hỏng, không phải danh sách');

    foreach (ORDER_ITEM_TOPPING_ALLOWED_WRITERS as $prefix) {
        $used = array_filter($sites, static fn (string $s): bool => str_starts_with($s, $prefix));

        expect($used)->not->toBeEmpty(implode("\n", [
            "Người ghi được phép `{$prefix}` KHÔNG chứa lệnh ghi OrderItemTopping nào.",
            'Xoá mục đó khỏi ORDER_ITEM_TOPPING_ALLOWED_WRITERS.',
            '',
            'Một giấy phép không dùng tới KHÔNG vô hại — nhất là khi nó là tiền tố',
            'THƯ MỤC: nó ký sẵn cho mọi file chưa ra đời bên dưới, gồm cả file do',
            'generator sinh ở lượt regen sau.',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));
    }
});
