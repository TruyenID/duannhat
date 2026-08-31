<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\User;
use App\Policies\PrinterPolicy;
use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * plan-053 T5.4 follow-up (#1171) — ROTATING A CLOUDPRNT `print_token`.
 *
 * T5.4 made the token reveal-once (P-16), which closed a real hole: the omnify
 * base put `print_token` in every printer response, so the moment anything
 * minted one it would have been a shop-wide secret readable again tomorrow —
 * the property that turns a credential into a screenshot.
 *
 * But reveal-once with no second door strands the shop. The token is taped to
 * the machine; machines die, get swapped, and lose their card. Without rotation
 * the only recovery is deleting the printer row, which throws away its print
 * history with it.
 *
 * Rotation is deliberately IMMEDIATE — `CloudPrntService` matches the token
 * exactly, so the old value 401s from the next poll. A grace window would keep
 * serving whoever took the card, which is one of the two reasons to rotate at
 * all.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/printers";
});

function rotatableCloudPrntPrinter(string $orgId, Branch $shop): Printer
{
    return Printer::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $shop->id,
        'transport' => PrintTransport::CloudPrnt,
    ]);
}

it('R1: rotating mints a NEW token and reveals it exactly once', function () {
    $printer = rotatableCloudPrntPrinter($this->orgId, $this->shop);
    $before = $printer->refresh()->print_token;

    expect($before)->toBeString()->not->toBe('');

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$printer->id}/rotate-print-token")
        ->assertOk();

    $revealed = $response->json('data.print_token');

    expect($revealed)->toBeString()->not->toBe('');
    expect($revealed)->not->toBe($before, 'a rotation that returns the same token has rotated nothing');
    expect($printer->refresh()->print_token)->toBe($revealed);

    // Reveal-once: reading the printer back must NOT carry it. This is the half
    // that makes rotation safe to expose at all — without it, rotation would
    // just be a second way to publish the secret.
    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$printer->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.print_token');
});

it('R2: the OLD token stops authenticating immediately', function () {
    $printer = rotatableCloudPrntPrinter($this->orgId, $this->shop);
    $old = $printer->refresh()->print_token;

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$printer->id}/rotate-print-token")
        ->assertOk();

    // Straight at the CloudPRNT poll, not at a service method — the claim is
    // about what a machine on the shop floor experiences.
    $this->postJson("/api/v1/print/cloudprnt/{$old}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertStatus(401);
});

it('R3: the NEW token authenticates', function () {
    $printer = rotatableCloudPrntPrinter($this->orgId, $this->shop);

    $new = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$printer->id}/rotate-print-token")
        ->assertOk()
        ->json('data.print_token');

    $this->postJson("/api/v1/print/cloudprnt/{$new}", [
        'statusCode' => '200 OK',
        'printerMAC' => '00:11:62:00:00:01',
    ])->assertOk();
});

it('R4: a non-CloudPRNT printer is refused, not quietly given a useless token', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'transport' => PrintTransport::WsLan,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$printer->id}/rotate-print-token")
        ->assertStatus(422);

    // The message must say WHY. An operator handed "invalid request" goes to
    // look at the machine, which is fine.
    Assert::assertStringContainsString('ws_lan', json_encode($response->json()) ?: '');

    expect($printer->refresh()->print_token)->toBeNull();
});

it('R5: a printer in another org is not reachable', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
        'is_active' => true,
    ]);
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'slug' => 'foreign-shop',
        'is_active' => true,
    ]);
    $foreign = rotatableCloudPrntPrinter($otherOrgId, $otherShop);
    $before = $foreign->refresh()->print_token;

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$foreign->id}/rotate-print-token")
        ->assertStatus(404);

    expect($foreign->refresh()->print_token)->toBe(
        $before,
        'a failed authorization must not have rotated anything',
    );
});

it('R5b: the authorize() call is currently REDUNDANT with the query scope — measured, not assumed', function () {
    // Deleting `$this->authorize('update', $printer)` from the controller
    // changes nothing observable, and every test above still passes. That is not
    // a hole in the tests; it is what the code says today:
    //
    //   PrinterPolicy::update()  -> belongsToUserOrg($user, $printer)
    //   resolvePrinter()         -> ->where('organization_id', <caller's org>)
    //
    // Both answer the same question, so the 404 from scoping always arrives
    // before the 403 from the policy could.
    //
    // The call stays — every sibling action authorizes the same way, and the
    // policy is where a role condition would land. But it is pinned here so a
    // green suite is not read as evidence that authorization is exercised: the
    // day `update()` gains a real condition (manager-only, say), THIS is the
    // test that must grow a case, because nothing else would go red.
    $printer = rotatableCloudPrntPrinter($this->orgId, $this->shop);

    $outsider = User::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
    ]);

    expect(app(PrinterPolicy::class)->update($outsider, $printer))->toBeFalse();
    expect(app(PrinterPolicy::class)->update($this->user, $printer))->toBeTrue();
});

it('R6: an unauthenticated caller cannot rotate', function () {
    $printer = rotatableCloudPrntPrinter($this->orgId, $this->shop);
    $before = $printer->refresh()->print_token;

    $this->postJson("{$this->base}/{$printer->id}/rotate-print-token")
        ->assertStatus(401);

    expect($printer->refresh()->print_token)->toBe($before);
});
