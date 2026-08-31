<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\Offline\OfflineOrderSigningMessage;
use App\Services\Order\Offline\SelectionWire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #2860 — MỘT từ vựng chia bill.
 *
 * Bài ở đây canh hai thứ khác nhau và cả hai đều cần:
 *
 *  - **normalizer** — tên cũ vào, canonical ra, và chuỗi lạ thì NỔ;
 *  - **migration** — dữ liệu đã lưu được viết lại, ở cả ba chỗ.
 *
 * Phần migration chạy lệnh thật trên DB test thay vì đọc source, vì thứ dễ sai
 * không phải ánh xạ (bốn dòng, đọc là thấy) mà là **cú pháp JSON-path có chạy
 * trên driver hay không** — bản đầu viết `JSON_UNQUOTE` tay và nó chỉ có ở
 * MySQL, tức sẽ xanh khi đọc và nổ khi chạy.
 */
it('normalizer đưa mọi tên cũ về canonical', function (string $wire, ?OrderSplitMode $expected) {
    expect(OrderSplitMode::fromWire($wire))->toBe($expected);
})->with([
    // ba tên cho "chia đều", đến từ ba client khác nhau
    ['equal', OrderSplitMode::Even],        // pos-web + kiosk, trong metadata thanh toán
    ['by_people', OrderSplitMode::Even],    // customer-web, lên /split-mode
    ['split_even', OrderSplitMode::Even],   // trạng thái nội bộ kiosk
    ['even', OrderSplitMode::Even],         // canonical, phải đi qua nguyên vẹn
    // hai tên cho "chia theo số tiền tự nhập"
    ['custom', OrderSplitMode::ByAmount],
    ['by_amount', OrderSplitMode::ByAmount],
    // tên duy nhất chưa bao giờ trôi
    ['by_items', OrderSplitMode::ByItems],
]);

it('không có gì để nói thì trả null', function (?string $wire) {
    expect(OrderSplitMode::fromWire($wire))->toBeNull();
})->with([[null], ['']]);

it('chuỗi lạ thì NỔ, không âm thầm thành null', function () {
    // Fail-closed có chủ ý. Một chế độ chia bill không đọc được mà bị nuốt thành
    // "không chia" sẽ ghi một dòng tiền không ai giải thích được sau này — và nó
    // sẽ được tin, vì trông hệt như một khoản trả bình thường.
    expect(fn () => OrderSplitMode::fromWire('by_seat'))->toThrow(ValueError::class);
});

it('luật validate sinh từ enum phủ đúng canonical ∪ tên cũ', function () {
    $accepted = OrderSplitMode::acceptedWireValues();

    // Chiều KÊU: mọi canonical phải được nhận.
    foreach (OrderSplitMode::cases() as $case) {
        expect($accepted)->toContain($case->value);
    }

    // Chiều IM: và không được nhận thứ gì ngoài tập đã khai. Thiếu vế này thì
    // một giá trị lạ lọt vào `acceptedWireValues()` sẽ không ai biết.
    expect(array_diff($accepted, ['even', 'by_items', 'by_amount', 'equal', 'by_people', 'split_even', 'custom']))
        ->toBe([]);

    expect(OrderSplitMode::validationRule())->toStartWith('in:');
});

it('migration viết lại giá trị đã lưu ở CẢ BA chỗ', function () {
    $migration = require base_path(
        'database/migrations/2026_08_15_100000_manual_migration_canonicalize_split_mode_vocabulary.php'
    );

    $order = seedSplitVocabularyOrder();

    // Ba chỗ mang cùng từ vựng vì lịch sử đặt tên khác nhau. Chỗ thứ ba
    // (`split_type`, đường Stripe/PayPay) là chỗ dễ bỏ sót nhất: nó không tên
    // `split_mode` nên grep theo tên cột không thấy.
    DB::table('customer_orders')->where('id', $order->id)->update(['split_mode' => 'by_people']);
    $payA = seedSplitVocabularyPayment($order, ['split_mode' => 'equal', 'bill_index' => 0]);
    $payB = seedSplitVocabularyPayment($order, ['split_mode' => 'custom', 'label' => 'giữ nguyên tôi']);
    $payC = seedSplitVocabularyPayment($order, ['split_type' => 'by_people', 'split_count' => 3]);

    $migration->up();

    expect(DB::table('customer_orders')->where('id', $order->id)->value('split_mode'))->toBe('even');

    $a = json_decode((string) DB::table('order_payments')->where('id', $payA)->value('metadata'), true);
    $b = json_decode((string) DB::table('order_payments')->where('id', $payB)->value('metadata'), true);
    $c = json_decode((string) DB::table('order_payments')->where('id', $payC)->value('metadata'), true);

    expect($a['split_mode'])->toBe('even')
        ->and($b['split_mode'])->toBe('by_amount')
        ->and($c['split_type'])->toBe('even');

    // Các khoá khác của blob phải còn nguyên. Đây là lý do dùng cập nhật theo
    // JSON-path chứ không đọc-sửa-ghi cả blob.
    expect($a['bill_index'])->toBe(0)
        ->and($b['label'])->toBe('giữ nguyên tôi')
        ->and($c['split_count'])->toBe(3);
});

it('migration chạy lại không đổi gì thêm', function () {
    // Đường deploy chạy `migrate --force` không người trông. Một migration dữ
    // liệu chạy hai lần phải cho cùng kết quả — và cái bẫy thật là ánh xạ bắc
    // cầu: nếu `MAP` có `a=>b` và `b=>c` thì lượt hai sẽ đẩy tiếp. Ở đây không
    // có, và bài này giữ cho nó không xuất hiện.
    $migration = require base_path(
        'database/migrations/2026_08_15_100000_manual_migration_canonicalize_split_mode_vocabulary.php'
    );

    $order = seedSplitVocabularyOrder();
    DB::table('customer_orders')->where('id', $order->id)->update(['split_mode' => 'custom']);
    $pay = seedSplitVocabularyPayment($order, ['split_mode' => 'equal']);

    $migration->up();
    $first = DB::table('customer_orders')->where('id', $order->id)->value('split_mode');
    $firstMeta = DB::table('order_payments')->where('id', $pay)->value('metadata');

    $migration->up();

    expect(DB::table('customer_orders')->where('id', $order->id)->value('split_mode'))->toBe($first)
        ->and(DB::table('order_payments')->where('id', $pay)->value('metadata'))->toBe($firstMeta)
        ->and($first)->toBe('by_amount');
});

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'split-vocab-shop',
    ]);
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

/**
 * Đơn trần — bài ở đây đo TỪ VỰNG, không đo tiền, nên không cần dòng món.
 *
 * Tên hàm mang tiền tố riêng có chủ ý: helper khai trong một file test mà file
 * test khác gọi được là cái đã chặn `pest --parallel` một lần (#2778). Đặt tên
 * riêng thì một trùng tên tình cờ cũng không thành sự cố.
 */
function seedSplitVocabularyOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-SV-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'checkout',
        'subtotal' => 3000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 3000, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

/** @param array<string, mixed> $metadata */
function seedSplitVocabularyPayment(CustomerOrder $order, array $metadata): string
{
    $payment = OrderPayment::create([
        'payment_code' => 'PAY-SV-'.Str::random(6),
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => 1000,
        'status' => 'succeeded',
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'metadata' => $metadata,
    ]);

    return (string) $payment->id;
}

it('#2860 digest ký theo CHUỖI THIẾT BỊ GỬI, không theo bản đã chuẩn hoá', function () {
    // Bài đắt nhất của cả lượt đổi từ vựng, và nó suýt lọt.
    //
    // `split_mode` nằm TRONG signed bytes của đơn bán offline. Chữ ký phủ lên
    // thứ thiết bị gửi, nên nếu Cloud chuẩn hoá `equal` → `even` TRƯỚC khi dựng
    // lại message thì message dựng lại khác message đã ký ⇒ chữ ký fail ⇒ **mọi
    // đơn offline của máy chưa cập nhật bị từ chối**. Fleet là hai máy Windows
    // không tự cập nhật, nên "chưa cập nhật" là trạng thái mặc định, không phải
    // ngoại lệ.
    //
    // Production Go hiện chưa bao giờ ký `split_mode` (đo ở
    // `offline_selection_builder.go`: luôn nil), nên đây là bẫy chưa nổ. Bài
    // này giữ cho nó đừng nổ.
    $wire = [
        'lines' => [['line_id' => (string) Str::uuid(), 'product_sku_id' => (string) Str::uuid(), 'quantity' => 1]],
        'order_type' => 'spot',
        'split_mode' => 'equal',
    ];

    $selection = SelectionWire::parse($wire);

    // Tầng domain thấy giá trị canonical…
    expect($selection->splitMode)->toBe(OrderSplitMode::Even);
    // …còn digest thấy đúng chuỗi thiết bị đã ký.
    expect($selection->splitModeWire)->toBe('equal');

    $digestRaw = OfflineOrderSigningMessage::selectionDigest($selection);

    $canonicalWire = $wire;
    $canonicalWire['split_mode'] = 'even';
    $digestCanonical = OfflineOrderSigningMessage::selectionDigest(
        SelectionWire::parse($canonicalWire)
    );

    // Hai chuỗi khác nhau PHẢI cho hai digest khác nhau. Bằng nhau nghĩa là
    // digest đang đọc bản đã chuẩn hoá — đúng cái lỗi bài này canh.
    expect($digestRaw)->not->toBe($digestCanonical);
});
