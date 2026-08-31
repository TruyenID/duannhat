<?php

declare(strict_types=1);

/**
 * Every path that voids an ORDER owes the same cleanup — asserted at the source.
 *
 * This class of defect has now been found seven times, in four separate issues,
 * always the same way: the shop path gains a step and its twin does not.
 *
 *   #1276  three void paths never released the coupon
 *   #1283  four never returned deducted stock, and the LAN void never even
 *          marked its lines voided
 *   #1285  the LAN void never freed its table — so a later merge aborted with
 *          409 "Table is already occupied by another order", about an order
 *          that had been cancelled
 *   #1286  the LAN soft-delete did neither
 *
 * The cause is the shape of the code, not carelessness: each pair is two
 * independent functions implementing one business rule, so adding a step to one
 * has nothing forcing the other to follow. A behavioural test per path would
 * need a different fixture each time and would still only cover the paths that
 * exist today. This fails for the eighth one, written tomorrow.
 *
 * Companion to CouponReleasedOnEveryVoidPathTest, which guards the coupon half.
 *
 * #1590 — bài test này quét MÃ NGUỒN, nên nó nhạy với việc đổi từ vựng chứ không
 * chỉ nhạy với việc mất hành vi. Khi việc nhả bàn chuyển sang cổng
 * `TableOccupancy`, nó đỏ ngay ở `continueTableOrder()` — đúng như thiết kế.
 * Cách xử lý ĐÚNG là dạy nó từ vựng mới (thêm tên method của cổng), KHÔNG phải
 * nới điều kiện cho lỏng đi.
 */
it('voids the lines, returns the stock and frees the table on every order-void path', function () {
    $path = base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php');
    $lines = explode("\n", file_get_contents($path));

    // Every method that writes the ORDER-level voided status. Item-level voids
    // are a different obligation (they compensate per line) and are excluded by
    // keying on CustomerOrderStatusEnum rather than OrderItemStatusEnum.
    $voidSites = [];
    foreach ($lines as $number => $line) {
        if (str_contains($line, "'status' => CustomerOrderStatusEnum::Voided->value")) {
            $voidSites[] = $number;
        }
    }

    // If this stops finding sites the test has gone blind, not the code clean.
    expect(count($voidSites))->toBeGreaterThanOrEqual(6, 'found too few void sites — the scan is broken');

    $signatures = [];
    foreach ($lines as $number => $line) {
        if (preg_match('/^\s*(?:public|protected|private) function (\w+)\(/', $line, $m) === 1) {
            $signatures[$number] = $m[1];
        }
    }

    /** Method name + body for the site at $site. */
    $enclosing = function (int $site) use ($signatures, $lines): array {
        $start = null;
        $name = null;
        foreach ($signatures as $number => $fn) {
            if ($number < $site) {
                $start = $number;
                $name = $fn;
            }
        }
        if ($start === null) {
            return ['', ''];
        }
        $end = count($lines);
        foreach ($signatures as $number => $fn) {
            if ($number > $start) {
                $end = $number;
                break;
            }
        }

        $body = implode("\n", array_slice($lines, $start, $end - $start));

        // Strip comments before matching. Without this the gate is worthless:
        // these methods carry long comments EXPLAINING the cleanups (and naming
        // releaseOrderTables / compensateVoid while doing so), so a body that
        // only talks about the obligation reads as satisfying it. Verified by
        // deleting a real releaseOrderTables call — the first version of this
        // test stayed green.
        $body = preg_replace('#/\*.*?\*/#s', '', $body);
        $body = preg_replace('#^\s*//.*$#m', '', $body);

        return [$name, $body];
    };

    $obligations = [
        'marks its lines voided' => fn (string $body): bool => str_contains($body, 'OrderItemStatusEnum::Voided->value'),
        'returns deducted stock' => fn (string $body): bool => str_contains($body, 'compensateBulkVoidedLines')
            || str_contains($body, 'compensateVoid'),
        // #1590 — nghĩa vụ không đổi, TỪ VỰNG thì đổi. Việc nhả bàn giờ đi qua
        // cổng `TableOccupancy`, nên chuỗi `'current_order_id' => null` không
        // còn xuất hiện trong file này; nó nằm trong `EloquentTableOccupancy`.
        // Chấp nhận đúng HAI method nhả của cổng, không chấp nhận `tables()`
        // chung chung — `countHeldBy()` cũng gọi `tables()` mà không nhả gì.
        'frees its tables' => fn (string $body): bool => str_contains($body, 'releaseOrderTables')
            || str_contains($body, "'current_order_id' => null")
            || str_contains($body, 'releaseByOrder(')
            || str_contains($body, 'releaseByIds('),
    ];

    $missing = [];
    foreach ($voidSites as $site) {
        [$name, $body] = $enclosing($site);
        if ($name === '') {
            continue;
        }

        foreach ($obligations as $what => $satisfied) {
            if (! $satisfied($body)) {
                $missing[] = "{$name}() voids an order but never {$what}";
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([], implode("\n  ", [
        'An order-void path must undo everything the order was holding. Each of these',
        'has already shipped as a bug at least once:',
        '',
        '  lines   — left reading pending/preparing under a voided order, and invisible',
        '            to the #1257 repair sweep, which looks for voided lines (#1283)',
        '  stock   — deducted material never returned under on_add / on_preparing (#1283)',
        '  tables  — current_order_id left pointing at a voided order, so a later merge',
        '            409s "Table is already occupied by another order" (#1285)',
        '',
        ...array_unique($missing),
    ]));
});
