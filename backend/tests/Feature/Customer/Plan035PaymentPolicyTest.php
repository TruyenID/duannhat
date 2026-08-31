<?php

/**
 * plan-035 — payment policy + customer email + phone helper.
 *
 * Covers:
 *   - takeaway storeByBranch creates `confirmed` (plan-037 counter-pay review
 *     step) when prep_before_payment is the resolved default (true) and
 *     payment_method=counter.
 *   - same flow with shop override `prep_before_payment=false` creates `open`.
 *   - `customer_takeaway_email` round-trips through the response payload.
 *   - OrderPlacedMail is queued only when email is present.
 *   - getCartConfig exposes the effective_order_policy block.
 *   - EffectiveOrderPolicyService::deriveCountry handles the locale shapes
 *     that customer-web phone validation depends on.
 */

use App\Mail\OrderPlacedMail;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Rules\PhoneFormatForBranch;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => '00000000-aaaa-4aaa-aaaa-000000000035',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'locale' => 'vi-VN',
    ]);
    $this->sku = ProductSku::factory()->create();
});

it('deriveCountry parses the BCP-47 locale shapes customer-web sends', function () {
    expect(EffectiveOrderPolicyService::deriveCountry('vi-VN'))->toBe('VN');
    expect(EffectiveOrderPolicyService::deriveCountry('ja-JP'))->toBe('JP');
    expect(EffectiveOrderPolicyService::deriveCountry('en-GB'))->toBe('GB');
    expect(EffectiveOrderPolicyService::deriveCountry('vi'))->toBe('VN');
    expect(EffectiveOrderPolicyService::deriveCountry('ja'))->toBe('JP');
    expect(EffectiveOrderPolicyService::deriveCountry(null))->toBe('US');
});

it('exposes the effective policy + locale on /branches index', function () {
    $res = $this->getJson('/api/v1/customer/branches');

    $res->assertOk();
    $branch = collect($res->json('data'))->firstWhere('id', $this->branch->id);
    expect($branch)->not->toBeNull();
    expect($branch['locale'])->toBe('vi-VN');
    expect($branch['effective_order_policy']['phone_country'])->toBe('VN');
    expect($branch['effective_order_policy']['prep_before_payment'])->toBeTrue();
});

it('returns the same policy block on /branches/{slug}/cart-config', function () {
    $res = $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/cart-config");

    $res->assertOk()
        ->assertJsonPath('data.effective_order_policy.phone_country', 'VN')
        ->assertJsonPath('data.effective_order_policy.prep_before_payment', true);
});

it('shop prep_before_payment=false overrides the default and lands the order open', function () {
    Mail::fake();
    ShopOrderSetting::create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->organization->id,
        'prep_before_payment' => false,
    ]);

    $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_name' => 'Khách Plan-035',
        'customer_takeaway_phone' => '+84336909454',
        'customer_takeaway_email' => 'p035@example.com',
        'payment_method' => 'counter',
    ]);

    $res->assertCreated();
    expect($res->json('data.status'))->toBe(CustomerOrderStatusEnum::Open->value);
    expect($res->json('data.customer_takeaway_email'))->toBe('p035@example.com');
});

it('defaults to status=confirmed (counter-pay review) when no shop override + brand default true', function () {
    Mail::fake();

    $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_phone' => '+84336909454',
        'payment_method' => 'counter',
    ]);

    // plan-037 — a counter-pay takeaway order reaches the BE only after the
    // customer confirms on the FE review step, so it is created `confirmed`
    // (not the legacy plan-035 `pending`). See storeByBranch's status branch.
    $res->assertCreated();
    expect($res->json('data.status'))->toBe(CustomerOrderStatusEnum::Confirmed->value);
});

it('queues OrderPlacedMail only when the customer provided an email', function () {
    Mail::fake();

    // Without email — no mail dispatched.
    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_phone' => '+84336909454',
        'payment_method' => 'counter',
    ])->assertCreated();

    Mail::assertNothingQueued();

    // With email — one mail queued.
    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_phone' => '+84336909454',
        'customer_takeaway_email' => 'p035@example.com',
        'payment_method' => 'counter',
    ])->assertCreated();

    Mail::assertQueued(OrderPlacedMail::class, fn ($mail) => $mail->hasTo('p035@example.com'));
});

it('rejects an invalid phone number for the VN branch country', function () {
    Mail::fake();

    // 5 digits — not a valid VN national number.
    $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_name' => 'Khách Plan-035',
        'customer_takeaway_phone' => '12345',
        'payment_method' => 'counter',
    ]);

    $res->assertStatus(422)
        ->assertJsonValidationErrors('customer_takeaway_phone');

    Mail::assertNothingQueued();
});

it('rejects a JP mobile number on a VN branch (country mismatch)', function () {
    Mail::fake();

    // 090-1234-5678 is a valid *Japanese* mobile (11 digits) but not VN (10).
    $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_phone' => '090-1234-5678',
        'payment_method' => 'counter',
    ]);

    $res->assertStatus(422)
        ->assertJsonValidationErrors('customer_takeaway_phone');
});

it('accepts a valid VN phone in both national and international form', function () {
    Mail::fake();

    foreach (['+84336909454', '0336909454'] as $phone) {
        $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
            'customer_takeaway_phone' => $phone,
            'payment_method' => 'counter',
        ]);

        $res->assertCreated();
    }
});

it('PhoneFormatForBranch enforces the per-country numbering plan', function () {
    // VN — 9 significant digits after the trunk 0.
    expect(PhoneFormatForBranch::isValidForCountry('+84336909454', 'VN'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('0336909454', 'VN'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('09012345678', 'VN'))->toBeFalse(); // 11 digits
    expect(PhoneFormatForBranch::isValidForCountry('123', 'VN'))->toBeFalse();

    // JP — mobile 11 digits, landline 10, both with trunk 0.
    expect(PhoneFormatForBranch::isValidForCountry('090-1234-5678', 'JP'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('03-1234-5678', 'JP'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('+819012345678', 'JP'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('012', 'JP'))->toBeFalse(); // too short
    expect(PhoneFormatForBranch::isValidForCountry('+84336909454', 'JP'))->toBeFalse(); // VN intl code on JP branch

    // US — 10-digit NANP, area/exchange lead 2-9.
    expect(PhoneFormatForBranch::isValidForCountry('415-555-1234', 'US'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('1-415-555-1234', 'US'))->toBeTrue();
    expect(PhoneFormatForBranch::isValidForCountry('015-555-1234', 'US'))->toBeFalse(); // leading 0

    // Empty passes — presence handled by the request's nullable rule.
    expect(PhoneFormatForBranch::isValidForCountry('', 'VN'))->toBeFalse();
});

it('BrandOrderPolicy override flips the resolved default to false', function () {
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'default_prep_before_payment' => false,
    ]);
    EffectiveOrderPolicyService::forgetForBrand($this->brand->id);

    Mail::fake();
    $res = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        'customer_takeaway_phone' => '+84336909454',
        'payment_method' => 'counter',
    ]);

    $res->assertCreated();
    expect($res->json('data.status'))->toBe(CustomerOrderStatusEnum::Open->value);
});
