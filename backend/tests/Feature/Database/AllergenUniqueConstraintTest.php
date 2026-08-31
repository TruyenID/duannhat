<?php

use App\Models\Allergen;
use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Verifies the allergens UNIQUE constraint is scoped per-organization.
 *
 * Bug: original migration declared UNIQUE(code, jurisdiction) globally,
 * which conflicted with the schema's documented org-scoped intent and
 * broke AllergenSeeder once a second organization existed (#321).
 */
test('two organizations can hold the same (code, jurisdiction) allergen row', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Allergen::create([
        'organization_id' => $orgA->id,
        'code' => 'shrimp',
        'jurisdiction' => 'jp',
        'severity' => 'mandatory',
        'is_active' => true,
    ]);

    // Should NOT throw — different organization scope.
    $second = Allergen::create([
        'organization_id' => $orgB->id,
        'code' => 'shrimp',
        'jurisdiction' => 'jp',
        'severity' => 'mandatory',
        'is_active' => true,
    ]);

    expect($second->exists)->toBeTrue();
    expect(Allergen::where('code', 'shrimp')->where('jurisdiction', 'jp')->count())->toBe(2);
});

test('same organization cannot hold duplicate (code, jurisdiction)', function () {
    $org = Organization::factory()->create();

    Allergen::create([
        'organization_id' => $org->id,
        'code' => 'milk',
        'jurisdiction' => 'jp',
        'severity' => 'mandatory',
        'is_active' => true,
    ]);

    expect(fn () => Allergen::create([
        'organization_id' => $org->id,
        'code' => 'milk',
        'jurisdiction' => 'jp',
        'severity' => 'mandatory',
        'is_active' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
