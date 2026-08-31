<?php

use App\Mail\OrderPlacedMail;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'takeaway-contact-test-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->sku = ProductSku::factory()->create();
});

// =========================================================================
//  Takeaway write — new keys (happy path)
// =========================================================================

it('stores customer_takeaway_name and customer_takeaway_phone on takeaway order', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
        'customer_takeaway_phone' => '090-1234-5678',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_name', '田中太郎')
        ->assertJsonPath('data.customer_takeaway_phone', '090-1234-5678');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('田中太郎');
    expect($order->customer_takeaway_phone)->toBe('090-1234-5678');
});

it('stores payload contact even for logged-in customers (D3)', function () {
    $account = Customer::factory()->selfRegistered()->create([
        'first_name' => 'Account Name',
        'phone' => '080-0000-0000',
    ]);
    $token = $account->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => 'Different Person',
        'customer_takeaway_phone' => '090-9999-9999',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('Different Person');
    expect($order->customer_takeaway_phone)->toBe('090-9999-9999');
});

it('allows phone to be null while name is set', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
        'customer_takeaway_phone' => null,
        // Issue #603 — a guest needs at least one contact channel; an email
        // keeps this order valid while still exercising the phone=null path.
        'customer_takeaway_email' => 'tanaka@example.com',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('田中太郎');
    expect($order->customer_takeaway_phone)->toBeNull();
});

// =========================================================================
//  Takeaway write — legacy keys (backward compat)
// =========================================================================

it('coalesces legacy customer_name/customer_phone into takeaway columns (D7)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_name' => 'Legacy Name',
        'customer_phone' => '03-1234-5678',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('Legacy Name');
    expect($order->customer_takeaway_phone)->toBe('03-1234-5678');
});

it('prefers new keys over legacy keys when both are present (D7)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => 'New Name',
        'customer_takeaway_phone' => '090-1111-1111',
        'customer_name' => 'Legacy Name',
        'customer_phone' => '090-2222-2222',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('New Name');
    expect($order->customer_takeaway_phone)->toBe('090-1111-1111');
});

// =========================================================================
//  Dine-in ignore (D4)
// =========================================================================

it('does not persist takeaway contact fields on dine-in orders (D4)', function () {
    $response = $this->postJson('/api/v1/customer/tables/takeaway-contact-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => 'Should Be Ignored',
        'customer_takeaway_phone' => '090-0000-0000',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBeNull();
    expect($order->customer_takeaway_phone)->toBeNull();
});

// =========================================================================
//  Validation
// =========================================================================

it('returns 422 when customer_takeaway_name exceeds 255 chars', function () {
    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => str_repeat('a', 256),
    ])->assertStatus(422)->assertJsonValidationErrors('customer_takeaway_name');
});

it('returns 422 when customer_takeaway_phone exceeds 50 chars', function () {
    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_phone' => str_repeat('0', 51),
    ])->assertStatus(422)->assertJsonValidationErrors('customer_takeaway_phone');
});

// =========================================================================
//  Empty / whitespace contact normalization
//
//  Gap: an empty or whitespace-only contact must NOT be persisted as a
//  garbage string. Laravel's global ConvertEmptyStringsToNull + TrimStrings
//  middleware collapse "" and "   " to null BEFORE the controller coalesces,
//  so a blank field lands as NULL (nullable column) rather than silently
//  storing an empty/whitespace value. These lock in that normalization.
// =========================================================================

it('normalizes an empty-string takeaway name to null (not stored blank)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '',
        'customer_takeaway_phone' => '',
        // Issue #603 — a valid contact channel so this guest order clears the
        // contact guard; the blank name/phone still normalize to null.
        'customer_takeaway_email' => 'blank-name@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_name', null)
        ->assertJsonPath('data.customer_takeaway_phone', null);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBeNull();
    expect($order->customer_takeaway_phone)->toBeNull();
});

it('normalizes a whitespace-only takeaway name to null (trimmed then nulled)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '   ',
        'customer_takeaway_phone' => '   ',
        // Issue #603 — a valid contact channel so this guest order clears the
        // contact guard; the whitespace name/phone still normalize to null.
        'customer_takeaway_email' => 'whitespace-name@example.com',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBeNull();
    expect($order->customer_takeaway_phone)->toBeNull();
});

it('falls through to the legacy key when the new key is blank (empty → null → coalesce)', function () {
    // customer_takeaway_name is "" → middleware nulls it → the D7 coalesce
    // `?? $validated['customer_name']` then picks up the legacy value. A blank
    // new key must NOT shadow a populated legacy key with an empty string.
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '',
        'customer_name' => 'Legacy Fallback',
        // Issue #603 — legacy name is not a contact channel, so give this guest
        // order an email to clear the guard while the coalesce is exercised.
        'customer_takeaway_email' => 'legacy-fallback@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_name', 'Legacy Fallback');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_name)->toBe('Legacy Fallback');
});

// =========================================================================
//  Takeaway email persistence (plan-035 contact field, same coalesce path)
// =========================================================================

it('persists and echoes customer_takeaway_email on a takeaway order', function () {
    Mail::fake();

    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
        'customer_takeaway_email' => 'tanaka@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_email', 'tanaka@example.com');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_email)->toBe('tanaka@example.com');

    // An email on the order fires the queued OrderPlacedMail (plan-035).
    Mail::assertQueued(OrderPlacedMail::class);
});

it('coalesces legacy customer_email into the takeaway email column', function () {
    Mail::fake();

    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_email' => 'legacy@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_email', 'legacy@example.com');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_email)->toBe('legacy@example.com');
});

it('prefers the new email key over the legacy email key', function () {
    Mail::fake();

    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_email' => 'new@example.com',
        'customer_email' => 'legacy@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_email', 'new@example.com');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_email)->toBe('new@example.com');
});

it('returns 422 when customer_takeaway_email is not a valid email', function () {
    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_email' => 'not-an-email',
    ])->assertStatus(422)->assertJsonValidationErrors('customer_takeaway_email');
});

it('does not queue OrderPlacedMail when no takeaway email is provided', function () {
    Mail::fake();

    $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
        // Issue #603 — clear the guest contact guard with a phone (NOT email —
        // an email would queue OrderPlacedMail and defeat this assertion).
        'customer_takeaway_phone' => '090-1234-5678',
    ])->assertStatus(201);

    Mail::assertNothingQueued();
});

// =========================================================================
//  D8 — dine-in response must NOT echo takeaway contact fields
//
//  D4 already asserts the DB columns stay null on a dine-in POST. D8 is the
//  response-shape half: store() (table-QR) deliberately omits the takeaway
//  keys from its `data` object, and it must not persist the email either.
// =========================================================================

it('omits takeaway contact keys from the dine-in order response (D8)', function () {
    $response = $this->postJson('/api/v1/customer/tables/takeaway-contact-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => 'Should Be Ignored',
        'customer_takeaway_phone' => '090-0000-0000',
        'customer_takeaway_email' => 'ignored@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonMissingPath('data.customer_takeaway_name')
        ->assertJsonMissingPath('data.customer_takeaway_phone')
        ->assertJsonMissingPath('data.customer_takeaway_email');
});

it('does not persist customer_takeaway_email on a dine-in order (D4)', function () {
    Mail::fake();

    $this->postJson('/api/v1/customer/tables/takeaway-contact-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_email' => 'ignored@example.com',
    ])->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_email)->toBeNull();

    // Dine-in never fires the takeaway placed-mail regardless of a stray email.
    Mail::assertNothingQueued();
});

// =========================================================================
//  Coupon apply on takeaway create — regression guard
//
//  Pay-first takeaway orders are created as `confirmed`. The coupon apply
//  runs inside the same create transaction, so `confirmed` must count as a
//  modifiable status — otherwise the whole order rolls back with
//  order_not_modifiable ("Cannot apply or release a coupon when order is
//  [confirmed].") the moment a customer confirms a takeaway order carrying
//  a coupon code.
// =========================================================================

it('applies coupon_code on a confirmed takeaway order create', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 100000]);

    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'TAKEAWAY15',
        'discount_type' => 'fixed',
        'discount_value' => 15000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
        // Issue #603 — clear the guest contact guard; a phone keeps this an
        // email-free order so the test stays focused on coupon math.
        'customer_takeaway_phone' => '090-1234-5678',
        'coupon_code' => 'TAKEAWAY15',
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->status->value)->toBe('confirmed');
    // Discount must reflect the item subtotal, not a stale 0 — regression
    // guard for the double bug: the modifiable-guard rejected `confirmed`,
    // and even past it the discount clamped to a stale subtotal of 0.
    expect((float) $order->discount_amount)->toBe(15000.0);
    expect($order->coupon_code_snapshot)->toBe('TAKEAWAY15');
    expect($order->coupon_id)->toBe($coupon->id);
    expect((float) $order->total_amount)->toBe(85000.0);
    expect($coupon->fresh()->times_used)->toBe(1);
});

// =========================================================================
//  Issue #603 — guest contact guard
//
//  Defense-in-depth: a *guest* takeaway order (no customer_id) must carry at
//  least one reachable contact channel — phone OR email — so staff can reach
//  the customer. Logged-in customers fall back to their account profile (D3)
//  and stay exempt. The guard lives in createBranchOrder() and returns a
//  stable `error: TAKEAWAY_CONTACT_REQUIRED` with a 422.
// =========================================================================

it('rejects a guest takeaway order with no phone and no email (#603)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_name' => '田中太郎',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'TAKEAWAY_CONTACT_REQUIRED');

    expect(CustomerOrder::count())->toBe(0);
});

it('accepts a guest takeaway order with email only (#603, plan-035)', function () {
    Mail::fake();

    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_email' => 'email-only@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_email', 'email-only@example.com');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_phone)->toBeNull();
    expect($order->customer_takeaway_email)->toBe('email-only@example.com');
});

it('accepts a guest takeaway order with phone only (#603)', function () {
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_phone' => '090-1234-5678',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_takeaway_phone', '090-1234-5678');

    $order = CustomerOrder::latest()->first();
    expect($order->customer_takeaway_phone)->toBe('090-1234-5678');
    expect($order->customer_takeaway_email)->toBeNull();
});

it('allows a logged-in customer to omit takeaway contact entirely (#603, D3)', function () {
    $account = Customer::factory()->selfRegistered()->create([
        'first_name' => 'Account Name',
        'phone' => '080-0000-0000',
    ]);
    $token = $account->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_id)->toBe($account->id);
    expect($order->customer_takeaway_phone)->toBeNull();
    expect($order->customer_takeaway_email)->toBeNull();
});

it('rejects a guest takeaway order with whitespace-only phone and email (#603)', function () {
    // Global TrimStrings + ConvertEmptyStringsToNull collapse "   " to null
    // before the controller, so blank() catches these the same as omitted.
    $response = $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
        'customer_takeaway_phone' => '   ',
        'customer_takeaway_email' => '   ',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'TAKEAWAY_CONTACT_REQUIRED');

    expect(CustomerOrder::count())->toBe(0);
});
