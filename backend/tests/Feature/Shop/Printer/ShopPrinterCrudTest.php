<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\User;
use App\Omnify\Enums\PrinterRoleEnum;
use Illuminate\Support\Str;

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

    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/printers";
});

// =============================================================================
// A — index
// =============================================================================

it('returns 401 on index when unauthenticated', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('only returns printers belonging to the shop in the URL', function () {
    Printer::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('exposes exactly the roles the workstation can dispatch to', function () {
    // Guards against Cloud offering a role the workstation would never route a
    // job to. The canonical list is `PrinterRoles` in
    // workstation/internal/printer/manager.go — four roles; `label_printer`
    // exists as a DeviceType there but is deliberately NOT a printer role.
    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('meta.available_roles', [
            PrinterRoleEnum::KitchenPrinter->value,
            PrinterRoleEnum::BarPrinter->value,
            PrinterRoleEnum::HallPrinter->value,
            PrinterRoleEnum::ReceiptPrinter->value,
        ]);
});

it('returns a multi-role printer with every role it holds', function () {
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'roles' => [
            PrinterRoleEnum::KitchenPrinter->value,
            PrinterRoleEnum::ReceiptPrinter->value,
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.roles', [
            PrinterRoleEnum::KitchenPrinter->value,
            PrinterRoleEnum::ReceiptPrinter->value,
        ]);
});

it('filters by role using JSON array membership', function () {
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'roles' => [PrinterRoleEnum::BarPrinter->value],
    ]);
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base.'?role='.PrinterRoleEnum::BarPrinter->value)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// =============================================================================
// B — store
// =============================================================================

it('creates a printer scoped to the shop in the URL', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Kitchen 1',
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
        'connection_type' => 'network',
        'address' => '192.168.1.100:9100',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Kitchen 1')
        ->assertJsonPath('data.address', '192.168.1.100:9100');

    $this->assertDatabaseHas('printers', [
        'name' => 'Kitchen 1',
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
    ]);
});

it('persists roles as an array so one printer can serve several roles', function () {
    $roles = [
        PrinterRoleEnum::KitchenPrinter->value,
        PrinterRoleEnum::ReceiptPrinter->value,
    ];

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'All-in-one',
        'roles' => $roles,
        'connection_type' => 'network',
        'address' => '10.0.0.5:9100',
    ])->assertCreated();

    expect(Printer::where('name', 'All-in-one')->first()->roles)->toBe($roles);
});

it('rejects a printer with no role', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Roleless',
        'roles' => [],
        'connection_type' => 'network',
        'address' => '192.168.1.100:9100',
    ])->assertUnprocessable()->assertJsonValidationErrors('roles');
});

it('rejects an unknown role', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Bogus',
        'roles' => ['espresso_printer'],
        'connection_type' => 'network',
        'address' => '192.168.1.100:9100',
    ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
});

it('rejects a duplicate name within the same shop', function () {
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Kitchen 1',
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Kitchen 1',
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
        'connection_type' => 'network',
        'address' => '192.168.1.101:9100',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('allows the same printer name in a different shop', function () {
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'name' => 'Kitchen 1',
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Kitchen 1',
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
        'connection_type' => 'network',
        'address' => '192.168.1.101:9100',
    ])->assertCreated();
});

it('rejects a paper width the ESC/POS formatter has no layout for', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Odd paper',
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
        'connection_type' => 'network',
        'address' => '192.168.1.100:9100',
        'paper_width' => 112,
    ])->assertUnprocessable()->assertJsonValidationErrors('paper_width');
});

// =============================================================================
// C — update
// =============================================================================

it('updates the address only, keeping the stored connection type', function () {
    // The common real-world edit: the printer got a new DHCP lease. The client
    // sends address alone, so the address rule must fall back to the record's
    // existing connection_type instead of assuming network.
    $printer = Printer::factory()->usb()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$printer->id}", ['address' => '/dev/usb/lp1'])
        ->assertOk()
        ->assertJsonPath('data.address', '/dev/usb/lp1');
});

it('rejects a network address on an address-only update of a usb printer', function () {
    $printer = Printer::factory()->usb()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$printer->id}", ['address' => '192.168.1.100:9100'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');
});

it('allows a printer to keep its own name on update', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Kitchen 1',
    ]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$printer->id}", [
            'name' => 'Kitchen 1',
            'paper_width' => 58,
        ])
        ->assertOk()
        ->assertJsonPath('data.paper_width', 58);
});

// =============================================================================
// D — tenant isolation
// =============================================================================

it('404s when updating a printer that belongs to another shop', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->patchJson("{$this->base}/{$printer->id}", ['paper_width' => 58])
        ->assertNotFound();
});

it('404s when showing a printer that belongs to another shop', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$printer->id}")
        ->assertNotFound();
});

// =============================================================================
// E — delete
// =============================================================================

it('soft deletes a printer', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$printer->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('printers', ['id' => $printer->id]);
});

it('keeps a soft-deleted printer name reserved', function () {
    // The (branch_id, name) unique index has no deleted_at component — same as
    // `devices` — so a soft-deleted row still holds its name. Recovering the
    // printer means restoring it, not re-creating it under the same name.
    // Asserted explicitly so a future migration that scopes the index to
    // non-deleted rows shows up here as an intentional behaviour change.
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Kitchen 1',
    ]);
    $printer->delete();

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Kitchen 1',
        'roles' => [PrinterRoleEnum::KitchenPrinter->value],
        'connection_type' => 'network',
        'address' => '192.168.1.100:9100',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('restores a soft-deleted printer', function () {
    $printer = Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $printer->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$printer->id}/restore")
        ->assertOk();

    $this->assertNotSoftDeleted('printers', ['id' => $printer->id]);
});
