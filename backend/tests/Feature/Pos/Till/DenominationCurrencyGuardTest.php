<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/*
 * #555 L4 — denomination counting summed every denomination's face value into
 * counted_cash regardless of the shift currency. A $100 bill counted in a ¥
 * shift added 100 to the yen total and silently corrupted the cash variance.
 * persistDenominationCounts now rejects a set-and-mismatched currency_code;
 * a NULL currency_code denomination is generic and adopts the shift currency.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);
});

function openWith(array $counts): TillSession
{
    return app(TillSessionService::class)->open([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'opening_counts' => $counts,
        'opened_by_id' => (string) Str::uuid(),
    ]);
}

it('rejects a foreign-currency denomination in a JPY shift', function () {
    $usd = Denomination::factory()->create(['currency_code' => 'USD', 'value' => 100, 'kind' => 'note']);

    expect(fn () => openWith([
        ['denomination_id' => $usd->id, 'quantity' => 3],
    ]))->toThrow(function (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422);
        $body = $e->getResponse()->getData(true);
        expect($body['code'])->toBe('DENOMINATION_CURRENCY_MISMATCH');
        expect($body['denomination_currency'])->toBe('USD');
        expect($body['shift_currency'])->toBe('JPY');
    });

    // Transaction rolled back — no half-open session left the till stamped.
    expect(TillSession::query()->count())->toBe(0);
});

it('accepts a matching-currency denomination', function () {
    $jpy = Denomination::factory()->jpy1000()->create();

    $session = openWith([['denomination_id' => $jpy->id, 'quantity' => 5]]);

    expect((float) $session->opening_float_amount)->toBe(5000.0);
});

it('accepts a case-insensitive currency match', function () {
    $jpyLower = Denomination::factory()->create(['currency_code' => 'jpy', 'value' => 500, 'kind' => 'coin']);

    $session = openWith([['denomination_id' => $jpyLower->id, 'quantity' => 4]]);

    expect((float) $session->opening_float_amount)->toBe(2000.0);
});
