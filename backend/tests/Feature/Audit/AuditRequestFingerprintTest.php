<?php

/**
 * #2522 — the device fingerprint every audit row carries.
 *
 * The 人形町店 C-6 investigation could prove four separate requests each added a
 * bowl of pho, and could NOT prove whether they came from one phone or four.
 * That difference decided a product ruling (whether to merge same-SKU adds and
 * kitchen slips), and nothing in the system could answer it: no HTTP access log
 * on the host, and `table_sessions` records no devices.
 *
 * `AuditsActivity::logAudit()` is the one chokepoint every audited write passes
 * through, and it already carried `request_id`. These pin the two fields added
 * beside it, and the three ways the addition could have gone wrong.
 */

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Facades\Route;

uses()->group('audit');

beforeEach(function () {
    $orgId = '00000000-0000-0000-0000-000000000001';

    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->zone = Zone::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->table = Table::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'audit-fingerprint-token',
        'is_active' => true,
        'status' => 'free',
    ]);
    $this->sku = ProductSku::factory()->create();
});

/** @return array<string, mixed> */
function orderAuditMetadata(): array
{
    $order = CustomerOrder::query()->latest('created_at')->firstOrFail();

    return (array) AuditLog::query()
        ->where('auditable_id', $order->id)
        ->orderBy('created_at')
        ->firstOrFail()
        ->metadata;
}

it('records the caller user agent on an audited write', function () {
    // The field that actually discriminates. Four phones on a shop's wifi share
    // ONE public IP, so this — not `ip` — is what separates "one customer
    // tapped four times" from "four people each ordered".
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) TempoCustomer/1.2'])
        ->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])->assertStatus(201);

    expect(orderAuditMetadata()['user_agent'] ?? null)
        ->toBe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) TempoCustomer/1.2');
});

it('keeps the request id it already carried, and records NO ip', function () {
    // Two claims in one place because they are the same decision seen from
    // both sides. `request_id` was the only field here before #2522 and every
    // investigation runbook correlates on it, so it must survive.
    //
    // `ip` must NOT (#2554). `audit_logs` has no prune job at all, and this
    // trait fires on every audited write including guest customers — so the
    // field was personal data accumulating without bound, bought for a value
    // that cannot even separate four phones sharing a shop's wifi. Asserted as
    // an ABSENCE rather than left untested: "we decided not to collect this"
    // is a claim, and an untested claim is how it comes back.
    $this->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    $metadata = orderAuditMetadata();

    expect($metadata['request_id'] ?? null)->not->toBeNull()
        ->and($metadata)->not->toHaveKey('ip');
});

it('truncates a hostile user agent instead of storing it whole', function () {
    // A User-Agent is attacker-controlled free text landing in a JSON column on
    // EVERY audited write. Without a cap, one request can bloat the table.
    // MULTIBYTE on purpose. With ASCII, `mb_substr` and `mb_strcut` produce the
    // same bytes, so an ASCII probe passes either way and pins nothing — the
    // first version of this test did exactly that, and a reverse-test caught
    // it. `あ` is 3 bytes, so 512 characters would be 1536.
    $this->withHeaders(['User-Agent' => str_repeat('あ', 2000)])
        ->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])->assertStatus(201);

    // Bytes, not characters: the cap exists to bound what lands in the JSON
    // column, and `mb_substr` (what this used to be) counts characters — 512
    // multibyte ones are ~2 KB, which is not a cap on anything that matters.
    // The byte count is exact, and VALIDITY is what actually pins the choice.
    // Measured on this tree with a 2000×`あ` header:
    //
    //   mb_strcut  → 510 bytes, valid UTF-8,   json_encode ok
    //   substr     → 512 bytes, BROKEN UTF-8,  json_encode === false
    //   mb_substr  → 1536 bytes, valid UTF-8,  json_encode ok
    //
    // A `<= 512` assertion passes on `substr` — the exact mistake someone makes
    // while "removing the mbstring dependency". And a broken byte is not
    // cosmetic here: `json_encode` returns false, the `metadata` JSON cast
    // cannot write, and `logAudit` SWALLOWS the Throwable — so the whole audit
    // row disappears, silently, on a request whose header the attacker chose.
    $ua = orderAuditMetadata()['user_agent'] ?? '';

    expect(strlen($ua))->toBe(510)
        ->and(mb_check_encoding($ua, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($ua))->toBe(170);
});

it('writes no user_agent key at all when the caller sent none', function () {
    // The `!== ''` guard. An empty string in the JSON column is worse than an
    // absent key: it reads as "we recorded a UA and it was blank".
    $this->withHeaders(['User-Agent' => ''])
        ->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])->assertStatus(201);

    $metadata = orderAuditMetadata();

    expect($metadata)->not->toHaveKey('user_agent')
        ->and($metadata['request_id'] ?? null)->not->toBeNull();
});

it('lets an explicit caller value win over the fingerprint', function () {
    // The fingerprint is merged UNDER the caller's metadata. A relay or webhook
    // forwarder that knows a better `ip` than the socket peer must keep its
    // own — otherwise adding this helper would silently overwrite a more
    // accurate value that some caller had already gone to the trouble of
    // resolving.
    //
    // Asserted from INSIDE a real request, not from a bare call: outside one
    // the fingerprint is skipped entirely (see the console test below), so the
    // caller's values would survive for the wrong reason and this would pass
    // without exercising the precedence at all.
    Route::middleware('api')->post('/__test/audit-precedence', function () {
        CustomerOrder::query()->latest('created_at')->firstOrFail()
            // The integer key is the ONLY case where `+=` and `array_merge`
            // differ: `array_merge` renumbers it to 0. Without this the switch
            // is an unpinned claim in a comment.
            ->logAudit('probe', [7 => 'int-key', 'user_agent' => 'relay/1.0']);

        return response()->noContent();
    });

    $this->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    $this->withHeaders(['User-Agent' => 'real-browser/9'])
        ->postJson('/__test/audit-precedence')
        ->assertNoContent();

    $metadata = (array) AuditLog::query()->where('action', 'probe')->sole()->metadata;

    expect($metadata['user_agent'])->toBe('relay/1.0')
        ->and($metadata[7] ?? null)->toBe('int-key')
        // …while the field the caller did NOT set still comes from the request,
        // proving the merge is per-key and not all-or-nothing.
        ->and($metadata['request_id'] ?? null)->not->toBeNull();
});

it('records NO caller fields for a write that did not come from a request', function () {
    // The defect this test was written to catch, found while extending this
    // file. A console run still has a bound `request`, and it answers:
    //
    //     ip()        === '127.0.0.1'
    //     userAgent() === 'Symfony'
    //
    // (measured with `artisan tinker`, not assumed). Without a gate, every
    // scheduled command and queue job would stamp its audit rows with a caller
    // that never existed — and 127.0.0.1 reads like a real answer, so nobody
    // would question it. That is the same failure as trusting a CDN edge
    // address, except self-inflicted.
    $order = CustomerOrder::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    // No HTTP request ran, so no `request_id` attribute exists — the same state
    // a scheduled command is in.
    $order->logAudit('console_probe');

    $metadata = (array) AuditLog::query()->where('action', 'console_probe')->sole()->metadata;

    expect($metadata)->not->toHaveKey('user_agent');
});

it('fingerprints every audited model, not just orders', function () {
    // `AuditsActivity` is a shared trait: the value of putting the fingerprint
    // there instead of in the order path is that it covers writes nobody
    // thought about while fixing #2522. A coupon write is one of those.
    Route::middleware('api')->post('/__test/audit-other-model', function () {
        Coupon::factory()->create(['organization_id' => '00000000-0000-0000-0000-000000000001']);

        return response()->noContent();
    });

    $this->withHeaders(['User-Agent' => 'other-model-probe/1'])
        ->postJson('/__test/audit-other-model')
        ->assertNoContent();

    $metadata = (array) AuditLog::query()
        ->where('auditable_type', (new Coupon)->getMorphClass())
        ->firstOrFail()
        ->metadata;

    expect($metadata['user_agent'] ?? null)->toBe('other-model-probe/1')
        ->and($metadata['request_id'] ?? null)->not->toBeNull();
});

it('keeps the caller fields on an UPDATE, not only on the create', function () {
    // `bootAuditsActivity` writes through four closures (created, updated,
    // deleted, restored). This one covers `updated`; the block below covers
    // `deleted` + `restored`. Saying which are covered rather than gesturing at
    // "all four" — the earlier wording claimed a scope this file did not have.
    $this->postJson('/api/v1/customer/tables/audit-fingerprint-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    $order = CustomerOrder::query()->latest('created_at')->firstOrFail();

    Route::middleware('api')->post('/__test/audit-update', function () use ($order) {
        $order->forceFill(['guest_count' => 4])->save();

        return response()->noContent();
    });

    $this->withHeaders(['User-Agent' => 'update-probe/1'])
        ->postJson('/__test/audit-update')
        ->assertNoContent();

    // Select by WHAT CHANGED, not by recency. Creating the order already wrote
    // several `updated` rows of its own, `created_at` has second granularity so
    // they tie, and the test client's default User-Agent is literally
    // "Symfony" — so `latest()` here silently graded one of those rows and the
    // assertion failed against a value that looked like a bug in the feature.
    // It was a bug in the query.
    $metadata = (array) AuditLog::query()
        ->where('auditable_id', $order->id)
        ->where('action', 'updated')
        ->get()
        ->first(fn (AuditLog $row) => array_key_exists('guest_count', (array) ($row->metadata['changes'] ?? [])))
        ?->metadata;

    expect($metadata['user_agent'] ?? null)->toBe('update-probe/1')
        // The change set the audit already carried must survive the merge.
        ->and($metadata['changes']['guest_count'] ?? null)->toBe(4);
});

it('fingerprints a soft delete and the restore that follows', function () {
    // The two closures the file used to claim without exercising. `Coupon` is
    // used because it carries SoftDeletes — and that is not incidental: the
    // `deleted` closure calls `$model->isForceDeleting()`, which only exists on
    // the SoftDeletes trait, and it sits OUTSIDE `logAudit`'s try/catch. On a
    // model without the trait, deleting the INSTANCE would raise
    // BadMethodCallException and break the delete itself, not merely lose the
    // audit row. Nothing in `app/` hits that today (deletes go through the
    // query builder, which fires no model events), but nothing guards it
    // either — noted here rather than left for whoever changes a delete path.
    $coupon = null;

    Route::middleware('api')->post('/__test/audit-lifecycle', function () use (&$coupon) {
        $coupon = Coupon::factory()->create(['organization_id' => '00000000-0000-0000-0000-000000000001']);
        $coupon->delete();
        $coupon->restore();

        return response()->noContent();
    });

    $this->withHeaders(['User-Agent' => 'lifecycle-probe/1'])
        ->postJson('/__test/audit-lifecycle')
        ->assertNoContent();

    foreach (['deleted', 'restored'] as $action) {
        $metadata = (array) AuditLog::query()
            ->where('auditable_type', (new Coupon)->getMorphClass())
            ->where('action', $action)
            ->sole()
            ->metadata;

        expect($metadata['user_agent'] ?? null)->toBe('lifecycle-probe/1', "action={$action}")
            ->and($metadata['request_id'] ?? null)->not->toBeNull("action={$action}");
    }
});
