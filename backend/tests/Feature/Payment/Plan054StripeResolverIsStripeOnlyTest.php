<?php

declare(strict_types=1);

/**
 * plan-054 T3.6 — `CustomerWebStripeConnectionResolver` là đường **chỉ của
 * Stripe**, vĩnh viễn. Bài này ghim điều đó bằng máy vì lời văn đã trượt một
 * lần rồi: TASKS.md M3 vẫn nói "đổi tên thành CustomerWebGatewayConnectionResolver
 * + resolveAllForBranch()", tức là biến nó thành resolver dùng chung — đúng
 * hướng mà bài này chặn.
 *
 * Vì sao chặn, cụ thể: `resolveForOrder()` không trả null khi chi nhánh chưa có
 * policy. Nó rơi về `PaymentGatewayOrchestrationBootstrap::resolveStripeCustomerWebForOrder()`,
 * và bootstrap đó **TẠO** provider `stripe` + option `STRIPE_OPTION_CODE` +
 * connection nếu chưa có, rồi trả về id của chúng. Nên một caller PayPay lỡ
 * inject class này sẽ:
 *
 *   1. không thấy lỗi nào — nó luôn trả về một bộ id hợp lệ;
 *   2. đóng dấu `connection_id` / `option_id` **của Stripe** lên attempt PayPay;
 *   3. làm `payment_attempts` unique `(connection_id, environment, provider_object_id)`
 *      nằm sai chỗ, và mọi webhook PayPay về sau khớp attempt qua connection sẽ
 *      trượt.
 *
 * Đường PayPay có bootstrap riêng — `PayPayCustomerWebBootstrap::resolveForOrder()`.
 * Hai class cùng tên method, cùng chữ ký trả về, khác provider. Đó chính là lý
 * do nhầm lẫn ở đây rẻ tiền và im lặng.
 */

use App\Services\Payment\Orchestration\Internal\CustomerWebStripeConnectionResolver;
use App\Services\Payment\Orchestration\Internal\PaymentGatewayOrchestrationBootstrap;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;

uses()->group('payment');

/**
 * Mọi file nguồn mang tên PayPay — adapter, service, bootstrap, sweeper.
 *
 * @return list<string>
 */
function plan054PayPaySourceFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (stripos($file->getFilename(), 'paypay') !== false) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('tìm được tập file PayPay để quét', function () {
    // Bài quét chỉ có nghĩa khi nó thật sự nhìn thấy cái gì đó. Đổi tên thư mục
    // hay đổi quy ước đặt tên làm tập này rỗng thì bài dưới xanh vì không quét
    // gì cả — đó là loại rào tệ hơn không có rào.
    expect(plan054PayPaySourceFiles())->not->toBeEmpty();
    expect(count(plan054PayPaySourceFiles()))->toBeGreaterThanOrEqual(10);
});

it('không file PayPay nào chạm resolver của Stripe', function () {
    $offenders = [];

    foreach (plan054PayPaySourceFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (str_contains($source, 'CustomerWebStripeConnectionResolver')) {
            $offenders[] = str_replace(app_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([],
        'Đường PayPay phải dùng PayPayCustomerWebBootstrap::resolveForOrder(). '
        .'CustomerWebStripeConnectionResolver::resolveForOrder() KHÔNG trả null khi '
        .'thiếu policy — nó tự tạo connection Stripe rồi trả id của Stripe, nên nhầm '
        .'ở đây không sinh lỗi nào, chỉ sinh attempt PayPay đóng dấu Stripe. '
        .'File vi phạm: '.implode(', ', $offenders)
    );
});

it('fallback của resolver Stripe vẫn là bootstrap Stripe — tiền đề của bài trên', function () {
    // Bài trên chỉ đáng giá chừng nào fallback còn fabricate connection Stripe.
    // Nếu ai đó làm resolver trả null thay vì fallback, mối nguy biến mất và bài
    // trên thành nghi thức rỗng — lúc đó đọc lại T3.6 rồi hãy gỡ.
    $source = (string) file_get_contents(
        app_path('Services/Payment/Orchestration/Internal/CustomerWebStripeConnectionResolver.php')
    );

    expect(str_contains($source, '$this->bootstrap->resolveStripeCustomerWebForOrder('))->toBeTrue(
        'resolveForOrder() không còn rơi về bootstrap Stripe. Mối nguy T3.6 có thể đã '
        .'khác — đọc lại trước khi sửa hay gỡ bài quét ở trên.'
    );

    $bootstrap = (string) file_get_contents(
        app_path('Services/Payment/Orchestration/Internal/PaymentGatewayOrchestrationBootstrap.php')
    );

    expect(str_contains($bootstrap, 'PaymentGatewayProviderCodeEnum::Stripe->value'))->toBeTrue(
        'Bootstrap fallback thôi ghim provider Stripe — nếu nó thành đa-provider thì '
        .'lời cảnh báo trong docblock resolveForOrder() phải viết lại.'
    );
});

it('hai bootstrap là hai class khác nhau, cùng một chữ ký — nguồn của nhầm lẫn', function () {
    // Ghim lại chính cái làm T3.6 rẻ tiền: cùng tên method, cùng shape trả về.
    // Nếu một trong hai đổi shape, hợp đồng ngầm mà cả hai caller đang tin đã đổi.
    $stripe = new ReflectionMethod(PaymentGatewayOrchestrationBootstrap::class, 'resolveStripeCustomerWebForOrder');
    $paypay = new ReflectionMethod(PayPayCustomerWebBootstrap::class, 'resolveForOrder');

    expect($stripe->getNumberOfParameters())->toBe($paypay->getNumberOfParameters());
    expect((string) $stripe->getParameters()[0]->getType())
        ->toBe((string) $paypay->getParameters()[0]->getType());

    expect(class_exists(CustomerWebStripeConnectionResolver::class))->toBeTrue();
});
