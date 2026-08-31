<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $sharedOrgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create([
        'id' => $sharedOrgId,
        'console_organization_id' => $sharedOrgId,
    ]);
    $this->orgId = $sharedOrgId;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'key' => 'size',
        'name' => 'Size',
        'position' => 1,
    ]);

    $this->valueS = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
    ]);

    $this->valueM = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id,
        'value' => 'm',
        'label' => 'M',
        'position' => 2,
    ]);
});

function syncUrl(Brand $brand, ProductOption $option): string
{
    return "/api/v1/hq/{$brand->slug}/product-options/{$option->id}/sync-values";
}

describe('ProductOption sync-values', function () {
    it('renames a value label without touching the slug', function () {
        // SKU references value S → slug must stay
        ProductSku::factory()->withOptionValues($this->valueS)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'Small'],
                    ['id' => $this->valueM->id, 'label' => 'Medium'],
                ],
            ]);

        $response->assertOk();
        $this->valueS->refresh();
        expect($this->valueS->value)->toBe('s');     // slug unchanged
        expect($this->valueS->label)->toBe('Small'); // label changed
    });

    it('also renames the option name in the same call', function () {
        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'name' => 'Kích thước',
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'S'],
                    ['id' => $this->valueM->id, 'label' => 'M'],
                ],
            ]);

        $response->assertOk();
        $this->option->refresh();
        expect($this->option->name)->toBe('Kích thước');
        expect($this->option->key)->toBe('size'); // unchanged
    });

    it('inserts new values not yet in the option', function () {
        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'S'],
                    ['id' => $this->valueM->id, 'label' => 'M'],
                    ['value' => 'l', 'label' => 'L'],
                ],
            ]);

        $response->assertOk();
        $this->option->refresh();
        expect($this->option->values()->count())->toBe(3);
        expect($this->option->values()->where('value', 'l')->exists())->toBeTrue();
    });

    it('removes a value that is dropped from the submission when no SKU uses it', function () {
        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'S'],
                    // valueM dropped — no SKU references it, so should soft-delete
                ],
            ]);

        $response->assertOk();
        $this->valueM->refresh();
        expect($this->valueM->deleted_at)->not->toBeNull();
    });

    it('blocks removing a value that is still referenced by a SKU', function () {
        ProductSku::factory()->withOptionValues($this->valueM)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'S'],
                    // valueM dropped but a SKU uses it → 409
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'OPTION_VALUE_IN_USE');
        $this->valueM->refresh();
        expect($this->valueM->deleted_at)->toBeNull(); // rollback
    });

    it('rolls back rename + insert when a removal is blocked', function () {
        ProductSku::factory()->withOptionValues($this->valueM)->create([
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'name' => 'NewName',
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'Small'], // would rename
                    ['value' => 'l', 'label' => 'L'],               // would insert
                    // valueM dropped → blocked → rollback
                ],
            ])->assertStatus(409);

        $this->option->refresh();
        $this->valueS->refresh();
        expect($this->option->name)->toBe('Size');     // rollback
        expect($this->valueS->label)->toBe('S');       // rollback
        expect($this->option->values()->where('value', 'l')->exists())->toBeFalse();
    });

    it('rejects empty values array', function () {
        $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), ['values' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['values']);
    });

    it('renumbers position 1..N based on the submitted array order', function () {
        // valueS at position 1, valueM at position 2 — swap them.
        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueM->id, 'label' => 'M'],
                    ['id' => $this->valueS->id, 'label' => 'S'],
                ],
            ]);

        $response->assertOk();
        $this->valueM->refresh();
        $this->valueS->refresh();
        expect($this->valueM->position)->toBe(1);
        expect($this->valueS->position)->toBe(2);
    });

    it('returns values sorted by position from the api', function () {
        // Force the DB to store values out of order to prove the relation sorts.
        $this->valueS->update(['position' => 2]);
        $this->valueM->update(['position' => 1]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options");

        $response->assertOk();
        $values = $response->json('data.0.values');
        expect($values[0]['id'])->toBe($this->valueM->id);
        expect($values[1]['id'])->toBe($this->valueS->id);
    });

    it('places newly inserted values at the position implied by their array index', function () {
        $response = $this->actingAs($this->user)
            ->putJson(syncUrl($this->brand, $this->option), [
                'values' => [
                    ['id' => $this->valueS->id, 'label' => 'S'],
                    ['value' => 'l', 'label' => 'L'],  // index 1 → position 2
                    ['id' => $this->valueM->id, 'label' => 'M'],  // index 2 → position 3
                ],
            ]);

        $response->assertOk();
        $this->valueS->refresh();
        $this->valueM->refresh();
        expect($this->valueS->position)->toBe(1);
        expect($this->valueM->position)->toBe(3);

        $newValue = $this->option->values()->where('value', 'l')->first();
        expect($newValue)->not->toBeNull();
        expect($newValue->position)->toBe(2);
    });
});
