<?php

/**
 * #2806 — the branch's pay-at-counter switches reach customer-web.
 *
 * Adding a column does NOT make it reachable over HTTP, and the failure is
 * silent: service-level tests keep passing while the feature is dead on the
 * real path (#2622). These tests therefore go through the endpoint
 * customer-web actually calls, `GET /customer/branches/{slug}/payment-context`,
 * and nowhere else.
 *
 * The defaults are the load-bearing part. Both flags read `true` for a branch
 * with no `shop_order_settings` row, which is what makes this safe to deploy:
 * every existing branch keeps behaving exactly as it did before the switches
 * existed. A regression that flipped them to `false` would hide a payment
 * channel from every guest of every shop that never opened the settings
 * screen — and on a branch with no online gateway, that leaves a checkout with
 * no way to pay at all.
 */

use App\Models\ShopOrderSetting;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

function counterPayContext(PaymentPolicyApiFixtures $fixtures)
{
    return test()->getJson('/api/v1/customer/branches/'.$fixtures->shop->slug.'/payment-context');
}

it('defaults the CHANNEL on and the QR OFF when the branch has no settings row', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();

    // The premise of the assertion, stated rather than assumed: with no row at
    // all, the value can only come from the controller's own fallback.
    expect(ShopOrderSetting::where('branch_id', $fixtures->shop->id)->exists())->toBeFalse();

    // #3206 — hai công tắc nay trả lời KHÁC NHAU, và đó là điểm của bài này:
    // kênh thanh toán tại quầy vẫn được MỜI (`enabled` true — câu trả lời chủ
    // dự án đã yêu cầu sau ba lần lật), còn QR thì KHÔNG hiện.
    //
    // Nhóm "chưa có hàng settings" là nhóm đông nhất khi mở chi nhánh mới, nên
    // nếu chỉ đổi default của CỘT mà quên fallback này thì yêu cầu "bỏ QR"
    // không đạt đúng ở chỗ nó cần đạt nhất.
    counterPayContext($fixtures)
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', true)
        ->assertJsonPath('data.counter_pay_show_qr', false);
});

it('reports the channel off when the shop turned it off', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();

    ShopOrderSetting::create([
        'organization_id' => $fixtures->organization->id,
        'branch_id' => $fixtures->shop->id,
        'counter_pay_enabled' => false,
        // #3206 — đặt TƯỜNG MINH `true`, không để nó rơi về default nữa.
        //
        // Bài này ghim tính ĐỘC LẬP của hai công tắc: tắt kênh không được kéo QR
        // xuống theo. Từ khi default của QR cũng là `false`, một assert
        // `show_qr === false` không còn phân biệt được "độc lập" với "bị kéo
        // theo" — cả hai cho cùng kết quả, và bài test thành rỗng nghĩa trong im
        // lặng. Giá trị tường minh giữ cho nó đo đúng thứ nó nói.
        'counter_pay_show_qr' => true,
    ]);

    counterPayContext($fixtures)
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', false)
        ->assertJsonPath('data.counter_pay_show_qr', true);
});

it('reports the QR off while the channel stays on — the case #2797 could not express', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();

    ShopOrderSetting::create([
        'organization_id' => $fixtures->organization->id,
        'branch_id' => $fixtures->shop->id,
        'counter_pay_show_qr' => false,
    ]);

    counterPayContext($fixtures)
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', true)
        ->assertJsonPath('data.counter_pay_show_qr', false);
});

it('survives a settings row that names neither column', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();

    // A row exists but nobody has opened the counter-pay card. The column
    // defaults must carry it, not the controller's `?? true` — this is the case
    // that separates "the DDL default landed" from "the null-coalesce is
    // covering for a missing default".
    ShopOrderSetting::create([
        'organization_id' => $fixtures->organization->id,
        'branch_id' => $fixtures->shop->id,
    ]);

    counterPayContext($fixtures)
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', true)
        ->assertJsonPath('data.counter_pay_show_qr', false);

    $row = ShopOrderSetting::where('branch_id', $fixtures->shop->id)->first();
    expect($row->counter_pay_enabled)->toBeTrue();
    // #3206 — giá trị này đến từ DDL default, không phải từ null-coalesce: hàng
    // vừa được tạo mà không khai cột nào.
    expect($row->counter_pay_show_qr)->toBeFalse();
});

it('#3206 KHÔNG đụng chi nhánh đã CHỌN hiện QR', function () {
    // Vế đắt nhất của cả bản vá. Đo production 2026-08-18: 17 hàng
    // `shop_order_settings`, trong đó MỘT hàng đã là false — tức cột này là
    // lựa chọn thật của quán, không phải một giá trị chưa ai chạm.
    //
    // Đổi mặc định vì thế chỉ được chạm hàng MỚI. Một bản vá "dọn dẹp" tiện tay
    // backfill 16 hàng còn lại sẽ cướp lựa chọn của họ — đúng thứ CLAUDE.md cấm,
    // và ngày 2026-08-11 nó đã tốn hai lần dịch vụ thật.
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();

    ShopOrderSetting::create([
        'organization_id' => $fixtures->organization->id,
        'branch_id' => $fixtures->shop->id,
        'counter_pay_show_qr' => true,
    ]);

    counterPayContext($fixtures)
        ->assertOk()
        ->assertJsonPath('data.counter_pay_show_qr', true);

    expect(ShopOrderSetting::where('branch_id', $fixtures->shop->id)->first()->counter_pay_show_qr)
        ->toBeTrue();
});
