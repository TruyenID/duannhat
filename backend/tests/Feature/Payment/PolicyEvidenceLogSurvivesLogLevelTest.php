<?php

/**
 * #1871 — log bằng chứng của Gate 6 không được tắt bởi một quyết định vận hành
 * ở chỗ khác.
 *
 * Điều kiện ra của plan-055 Gate 6 (`plans/plan-055/artifacts/README.md`,
 * T4.2/T4.3) là đếm hai dòng log về 0 rồi mới bật cưỡng chế policy:
 *
 *   - `payment_policy_option_missing`      → `->warning()`
 *   - `payment_policy_alias_would_refuse`  → `->warning()`
 *
 * Channel `payment_orchestration` từng lấy mức từ `env('LOG_LEVEL', 'debug')`,
 * mà `deploy-xserver.yml` KHÔNG ghi `LOG_LEVEL` vào `.env` trên server — nên mức
 * thật không đọc được từ repo. Đặt `LOG_LEVEL=error` (chuyện thường khi siết
 * production) là hai dòng ấy thôi được ghi, hai lệnh `grep -c` trả `0 / 0`,
 * điều kiện ra thoả, và cú flip từ chối tiền ở mọi quầy còn chạy client cũ.
 *
 * `0` khi đó nghĩa là **chưa bao giờ ghi**, không phải **không có sự kiện** —
 * đúng hình dạng bốn cái bẫy mà chính runbook đó liệt kê.
 *
 * Bác bỏ được: trả `'level'` về `env('LOG_LEVEL', 'debug')` rồi chạy lại với
 * `LOG_LEVEL=error` ⇒ ca đầu ĐỎ.
 */

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Monolog\Level;

/**
 * #1921 — đặt biến môi trường qua KHO CỦA LARAVEL, không qua `putenv`.
 *
 * `putenv()` không tới được `env()`: kho env của Laravel là **immutable**, nó
 * chốt giá trị lúc boot và bỏ qua mọi thay đổi sau đó. Đo được:
 *
 *     putenv(...'=error') ⇒ getenv() = 'error'  nhưng  env() = 'info'
 *
 * Nên hai ca dưới đây trước #1921 KHÔNG kiểm được thứ chúng nói: ca thứ hai đỏ
 * vĩnh viễn trên mọi máy có dòng đó trong `.env` (mà `.env.example` có, từ
 * `67540ca58`), còn ca thứ nhất xanh vì mức mặc định `info` vốn đã bao gồm
 * `warning` — tức nó xanh kể cả khi biến không hề được đọc.
 *
 * `clear()` rồi `set()` là đường hợp lệ: `clear` gỡ khoá immutable cho đúng
 * biến đó, `set` ghi giá trị mới, và `env()` đọc được ngay.
 *
 * #2778 — NHƯNG chỉ đúng ở LẦN BOOT ĐẦU của tiến trình. Kho env của Laravel là
 * immutable và ghi nhớ mọi biến Dotenv đã nạp; một tiến trình paratest boot app
 * lại cho từng file test, nên từ lần boot thứ hai trở đi `set()` bị **từ chối
 * không một tiếng động**. Đo được trong lượt chạy `--parallel` (chạy lẻ thì
 * xanh vì đó là lần boot đầu):
 *
 *     DIAG env='info' | repo='info' | cfg='info' | _ENV='info' | _SERVER='info'
 *
 * tức `set(…, 'error')` không hề ăn, và ca thứ hai đo mức của `.env` chứ không
 * đo mức nó vừa đặt. Chỉ nổ khi `.env` CÓ dòng `PAYMENT_ORCHESTRATION_LOG_LEVEL`
 * (`.env.example` có, nên CI luôn có) — máy dev thiếu dòng đó thì xanh.
 *
 * Nên: ghi thẳng hai superglobal mà adapter của kho đọc, rồi **chứng minh nó
 * ăn**. Một lượt ghi env hỏng mà im lặng biến bài test bên dưới thành đo nhầm
 * thứ khác — đúng lớp lỗi mà chính hàm này sinh ra.
 */
function setPaymentLogEnv(string $key, ?string $value): void
{
    paymentLogEnvOriginal($key);

    $repo = Env::getRepository();
    $repo->clear($key);

    if ($value !== null) {
        $repo->set($key, $value);
    }

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    if (env($key) !== $value) {
        throw new RuntimeException(sprintf(
            'không đặt được %s=%s — env() vẫn trả %s. Ghi env hỏng mà im lặng '
            .'thì bài test bên dưới đo nhầm thứ khác (#2778).',
            $key,
            var_export($value, true),
            var_export(env($key), true),
        ));
    }
}

/**
 * Giá trị của biến TRƯỚC khi file này đụng vào — chốt một lần cho mỗi khoá.
 *
 * #2778 — `after()` phải TRẢ LẠI, không được ĐOÁN. Bản cũ đặt `LOG_LEVEL` về
 * `null` và `PAYMENT_ORCHESTRATION_LOG_LEVEL` về `'info'`, tức nó áp giá trị nó
 * tưởng là mặc định lên một tiến trình mà — khi chạy song song — còn hàng trăm
 * file test khác dùng chung.
 */
function paymentLogEnvOriginal(string $key): ?string
{
    static $original = [];

    if (! array_key_exists($key, $original)) {
        $value = Env::getRepository()->get($key);
        $original[$key] = $value === null || $value === false ? null : (string) $value;
    }

    return $original[$key];
}

/** Nạp lại `config/logging.php` từ file để `env()` được đọc lại. */
function reloadPaymentOrchestrationChannel(): void
{
    $channel = (include config_path('logging.php'))['channels']['payment_orchestration'];

    Config::set('logging.channels.payment_orchestration', $channel);
    app()->forgetInstance('log');
}

/** Mức thật Monolog dùng cho channel này, sau khi config đã resolve. */
function paymentOrchestrationHandlerLevel(): Level
{
    $logger = app('log')->channel('payment_orchestration')->getLogger();

    return $logger->getHandlers()[0]->getLevel();
}

it('#1871 vẫn ghi được `warning` khi LOG_LEVEL toàn cục bị siết lên error', function () {
    // Đúng cấu hình một người vận hành đặt để giảm nhiễu.
    setPaymentLogEnv('LOG_LEVEL', 'error');

    reloadPaymentOrchestrationChannel();

    expect(paymentOrchestrationHandlerLevel()->includes(Level::Warning))->toBeTrue(
        'channel bằng chứng phải ghi được `warning` bất kể LOG_LEVEL toàn cục'
    );
})->after(function () {
    setPaymentLogEnv('LOG_LEVEL', paymentLogEnvOriginal('LOG_LEVEL'));
});

it('#1871 vẫn có nút chỉnh riêng cho channel này', function () {
    // Ghim độc lập không có nghĩa là ghim CỨNG: vận hành vẫn phải hạ được mức
    // khi cần, chỉ là qua một biến nói rõ nó đang tắt cái gì.
    setPaymentLogEnv('PAYMENT_ORCHESTRATION_LOG_LEVEL', 'error');

    reloadPaymentOrchestrationChannel();

    expect(paymentOrchestrationHandlerLevel())->toBe(Level::Error);
})->after(function () {
    setPaymentLogEnv(
        'PAYMENT_ORCHESTRATION_LOG_LEVEL',
        paymentLogEnvOriginal('PAYMENT_ORCHESTRATION_LOG_LEVEL'),
    );
});
