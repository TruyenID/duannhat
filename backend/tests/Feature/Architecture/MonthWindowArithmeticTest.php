<?php

declare(strict_types=1);

/**
 * #1424 — architecture guard: subtract months from a MONTH BOUNDARY, never
 * from a day-of-month.
 *
 * Carbon overflows: a date that does not exist rolls forward into the next
 * month. So `->subMonths(11)` applied to the 31st lands on the 1st of the
 * month AFTER the one intended, and a following `->startOfMonth()` cannot
 * undo it — the window is now one month short.
 *
 *   2026-03-31 ->subMonths(11)->startOfMonth()  =  2025-05-01   ← 11 months
 *   2026-03-31 ->startOfMonth()->subMonths(11)  =  2025-04-01   ← 12 months
 *
 * This is the nastiest shape a reporting bug can take: nothing throws, the
 * report simply returns a smaller number, and it does so BY THE CALENDAR —
 * wrong in months whose target month is shorter, right in the others. It hid
 * in three separate places at once (POS revenue, HQ dashboard twice) and was
 * only found by reading, never by a failing test.
 *
 * The rule is mechanical, so it is enforced mechanically rather than left to
 * review: put the boundary call FIRST.
 */
use Carbon\CarbonImmutable;

const MONTH_OVERFLOW_PATTERN = '/->sub(Month|Months)\([^)]*\)\s*->\s*startOf(Month|Year)\(\)|->endOf(Month|Year)\(\)\s*->\s*sub(Month|Months|Year|Years)\(/';

function monthOverflowScan(): array
{
    $hits = [];
    $roots = [base_path('app')];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            foreach ($lines as $i => $line) {
                if (preg_match(MONTH_OVERFLOW_PATTERN, $line) === 1) {
                    $rel = str_replace(base_path().'/', '', $file->getPathname());
                    $hits[] = $rel.':'.($i + 1).'  '.trim($line);
                }
            }
        }
    }

    sort($hits);

    return $hits;
}

it('A1: no month window subtracts from a day-of-month', function () {
    $hits = monthOverflowScan();

    expect($hits)->toBe([], "Carbon tràn tháng — đổi thứ tự thành `startOfMonth()` rồi mới `subMonths()`:\n  ".implode("\n  ", $hits));
});

it('A2: the overflow this guard bans is real, not theoretical', function () {
    // Ghim chính hành vi Carbon mà luật trên dựa vào. Nếu Carbon đổi (hoặc ai
    // đó bật `useStrictMode`), test này đỏ ở chỗ giải thích được, thay vì luật
    // A1 lặng lẽ trở thành vô nghĩa.
    $end = CarbonImmutable::parse('2026-03-31');

    expect($end->subMonths(11)->startOfMonth()->toDateString())->toBe('2025-05-01')
        ->and($end->startOfMonth()->subMonths(11)->toDateString())->toBe('2025-04-01');

    // Và nó KHÔNG phải chuyện hiếm: sai ở mọi tháng mà tháng đích ngắn hơn.
    $wrongMonths = [];
    foreach (['2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31'] as $day) {
        $d = CarbonImmutable::parse($day);
        if ($d->subMonths(11)->startOfMonth()->ne($d->startOfMonth()->subMonths(11))) {
            $wrongMonths[] = $day;
        }
    }

    expect($wrongMonths)->not->toBeEmpty();
});
