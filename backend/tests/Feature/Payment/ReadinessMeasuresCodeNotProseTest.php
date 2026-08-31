<?php

/**
 * #1822 — cổng readiness phải đo CODE, không đo văn xuôi.
 *
 * `codePresent()` từng là `str_contains($contents, $class)` trần. Nghĩa là một
 * dòng docblock kiểu *"tách ra từ PaymentStatusCompatibility khi class đó bị
 * xoá"* đủ để cổng báo `code_present = true` — tức **việc ghi lại một lần xoá
 * làm cổng nói rằng chưa xoá**.
 *
 * Đã xảy ra thật trong chính PR xoá shim: `PaymentPollStatus` giải thích nguồn
 * gốc của mình, và cổng lập tức báo class cũ vẫn còn.
 *
 * Đây là đúng loại lỗi mà file `LegacyRemovalReadiness` sinh ra để chống — một
 * phép đo trông như bằng chứng nhưng đo nhầm thứ — và nó sai theo hướng xấu
 * nhất: cổng kêu oan sẽ được người ta học cách bỏ qua, rồi lần kêu thật cũng bị
 * bỏ qua theo.
 *
 * GIỚI HẠN ĐÃ ĐO, ghi ra để không ai đọc file này rộng hơn nó phủ: bốn ca dưới
 * gọi thẳng `mentionsInCode()` qua reflection, nên chúng ghim **hành vi của
 * method**, KHÔNG ghim việc `sourceFilesMentioning()` có gọi nó hay không. Đo
 * thật: gỡ lời gọi trong `sourceFilesMentioning()` ⇒ file này vẫn XANH, chỉ
 * `LegacyRemovalReadinessTest` (ca `code_present` phải false) đỏ.
 *
 * Nói cách khác cặp này phủ đủ, nhưng mỗi file phủ một nửa — đừng xoá nửa kia.
 */

use App\Services\Payment\Observation\LegacyRemovalReadiness;

/** Gọi private static qua reflection — đây là bất biến nội bộ, không phải API. */
function mentionsInCode(string $source, string $class): bool
{
    $m = new ReflectionMethod(LegacyRemovalReadiness::class, 'mentionsInCode');
    $m->setAccessible(true);

    return $m->invoke(null, $source, $class);
}

it('#1822 KHÔNG tính tên class nhắc trong docblock', function () {
    $source = <<<'PHP'
    <?php
    /**
     * Tách ra từ PaymentStatusCompatibility khi class đó bị xoá (#1822).
     */
    final class PaymentPollStatus {}
    PHP;

    expect(mentionsInCode($source, 'PaymentStatusCompatibility'))->toBeFalse();
});

it('#1822 KHÔNG tính tên class nhắc trong comment một dòng', function () {
    $source = "<?php\n// dùng PaymentStatusCompatibility ở đây trước khi xoá\nclass X {}\n";

    expect(mentionsInCode($source, 'PaymentStatusCompatibility'))->toBeFalse();
});

it('#1822 VẪN tính khi tên class nằm trong code thật', function () {
    // Chiều ngược lại, và là nửa quan trọng hơn: nếu chỉ ghim "bỏ comment" thì
    // một cài đặt luôn trả `false` cũng qua, và cổng sẽ im lặng vĩnh viễn.
    $useStatement = "<?php\nuse App\\Support\\PaymentStatusCompatibility;\nclass X {}\n";
    $staticCall = "<?php\nclass X { public function y() { return PaymentStatusCompatibility::forKioskPoll(null); } }\n";

    expect(mentionsInCode($useStatement, 'PaymentStatusCompatibility'))->toBeTrue()
        ->and(mentionsInCode($staticCall, 'PaymentStatusCompatibility'))->toBeTrue();
});

it('#1822 tính được cả khi tên class nằm trong CHUỖI — đó là code, không phải văn xuôi', function () {
    // `config/domain_mutation.php` và các allowlist khai class bằng chuỗi. Bỏ
    // sót chúng thì cổng lại nói dối theo chiều ngược: báo đã xoá trong khi một
    // đăng ký còn trỏ tới class.
    $source = "<?php\nreturn ['writer' => 'App\\\\Support\\\\PaymentStatusCompatibility'];\n";

    expect(mentionsInCode($source, 'PaymentStatusCompatibility'))->toBeTrue();
});
