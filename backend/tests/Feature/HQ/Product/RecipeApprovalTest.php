<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Allergen;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\MaterialService;
use App\Services\Product\RecipeService;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->approver = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->approver, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

/**
 * Recipe factory helper — recipes table has no `created_by_id` column so
 * factory() cannot persist it, but the "cannot approve own" service guard
 * reads `$recipe->created_by_id`. We set it in-memory via setAttribute
 * where the test requires the guard to engage.
 */
function makeRecipe(array $attrs = []): Recipe
{
    // Plan-022 T18 — submit/approve now require ≥ 1 ingredient referencing
    // an existing material that is NOT the recipe's own output material
    // (self-reference rule). Materialise the output + ingredient as two
    // distinct rows in the right brand so the constraint validators are
    // happy out of the box. Tests that specifically check empty-ingredient
    // rejection still pass `ingredients => []` to override.
    $output = Material::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);
    $ingredient = Material::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);

    return Recipe::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => $output->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 50, 'unit' => 'g'],
        ],
    ], $attrs));
}

// =========================================================================
//  Submit for approval
// =========================================================================

describe('submit-for-approval', function () {
    it('transitions draft → pending and writes audit row', function () {
        $recipe = makeRecipe();

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/submit-for-approval")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'approval_status' => 'pending',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'Recipe',
            'auditable_id' => $recipe->id,
            'action' => 'recipe.submitted_for_approval',
        ]);
    });

    it('rejects the transition from an illegal state (approved → submit)', function () {
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Approved->value]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/submit-for-approval")
            ->assertUnprocessable();

        $response->assertJsonPath('error', 'INVALID_STATUS_TRANSITION')
            ->assertJsonPath('from', 'approved')
            ->assertJsonPath('action', 'submit for approval');
    });

    it('returns 401 when unauthenticated', function () {
        $recipe = makeRecipe();

        $this->postJson("{$this->baseUrl}/recipes/{$recipe->id}/submit-for-approval")
            ->assertUnauthorized();
    });
});

// =========================================================================
//  Approve
// =========================================================================

describe('approve', function () {
    it('transitions pending → approved and writes audit row', function () {
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'approval_status' => 'approved',
            'approved_by_id' => $this->approver->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'Recipe',
            'auditable_id' => $recipe->id,
            'action' => 'recipe.approved',
        ]);
    });

    it('blocks cannot-approve-own against a persisted created_by_id', function () {
        // plan-003 audit high — `recipes.created_by_id` now exists, so the
        // submitter is persisted and the guard reads a real column after a DB
        // roundtrip (not an in-memory setAttribute). This proves the guard is
        // no longer dead code.
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);
        $recipe->created_by_id = $this->user->id;
        $recipe->save();

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'created_by_id' => $this->user->id,
        ]);

        $service = app(RecipeService::class);

        // Reload from the database so the attribute comes off the persisted
        // column, not the in-memory instance.
        expect(fn () => $service->approve($recipe->fresh(), $this->user))
            ->toThrow(InvalidArgumentException::class, 'Cannot approve your own recipe submission.');

        // The recipe stays pending — the guard aborted before any transition.
        expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);

        // A different approver is still allowed through.
        $approved = $service->approve($recipe->fresh(), $this->approver);
        expect($approved->getApprovalStatus())->toBe(ApprovalStatusEnum::Approved);
    });

    it('stamps created_by_id from the authenticated submitter on create', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Stamped Recipe',
                'brand_id' => $this->brand->id,
                'is_active' => true,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    ['type' => 'material', 'material_id' => $material->id, 'quantity' => 5, 'unit' => 'g'],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('recipes', [
            'id' => $response->json('data.id'),
            'created_by_id' => $this->user->id,
        ]);
    });

    it('rejects the transition from draft (illegal)', function () {
        $recipe = makeRecipe();

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('from', 'draft')
            ->assertJsonPath('action', 'approve');
    });
});

// =========================================================================
//  Reject
// =========================================================================

describe('reject', function () {
    it('transitions pending → rejected with reason + audit row', function () {
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/reject", [
                'rejection_reason' => 'Missing allergen data for wheat.',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected');

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'approval_status' => 'rejected',
            'rejected_by_id' => $this->approver->id,
        ]);
        $this->assertDatabaseHas('recipe_translations', [
            'rejection_reason' => 'Missing allergen data for wheat.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'Recipe',
            'auditable_id' => $recipe->id,
            'action' => 'recipe.rejected',
        ]);
    });

    it('validates rejection_reason is required (not empty)', function () {
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/reject", [
                'rejection_reason' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason']);

        expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);
    });

    it('validates rejection_reason max length 1000', function () {
        $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/reject", [
                'rejection_reason' => str_repeat('a', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason']);
    });
});

// =========================================================================
//  Resubmit from rejected
// =========================================================================

it('rejected → resubmit → pending retains rejection_reason', function () {
    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Rejected->value,
        'rejection_reason' => 'Prior rejection comment.',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/submit-for-approval")
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'pending')
        // The resubmit response must still carry the prior rejection reason so
        // the detail page can keep showing it after Rejected → Pending.
        ->assertJsonPath('data.rejection_reason', 'Prior rejection comment.');

    $recipe->refresh();
    expect($recipe->rejection_reason)->toBe('Prior rejection comment.');
    expect($recipe->rejected_by_id)->toBeNull();
    expect($recipe->rejected_at)->toBeNull();

    // And the GET-show endpoint (what the detail page loads) exposes it too.
    $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/recipes/{$recipe->id}")
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'pending')
        ->assertJsonPath('data.rejection_reason', 'Prior rejection comment.');
});

// =========================================================================
//  Two-tier re-approval — PUT with structural field change
// =========================================================================

it('auto-repends approved recipe when PUT changes a structural field', function () {
    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 10,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'output_quantity' => 99,
        ])
        ->assertOk();

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'Recipe',
        'auditable_id' => $recipe->id,
        'action' => 'recipe.auto_repending',
    ]);
});

it('leaves approved recipe alone on non-structural PUT (description)', function () {
    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'description' => 'Original description',
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'description' => 'Updated description only',
        ])
        ->assertOk();

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Approved);

    $this->assertDatabaseMissing('audit_logs', [
        'auditable_type' => 'Recipe',
        'auditable_id' => $recipe->id,
        'action' => 'recipe.auto_repending',
    ]);
});

// =========================================================================
//  Two-tier re-approval — cross-triggered by Material allergen change
// =========================================================================

it('auto-repends approved recipe when upstream material allergen set changes (non-empty delta)', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [],
        'allergen_rollup' => [],
    ]);

    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/materials/{$material->id}", [
            'allergen_ids' => [$allergen->id],
        ])
        ->assertOk();

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);

    $row = AuditLog::query()
        ->where('auditable_type', 'Recipe')
        ->where('auditable_id', $recipe->id)
        ->where('action', 'recipe.auto_repending')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->metadata['source'] ?? null)->toBe('material_allergen_change');
    expect($row->metadata['material_id'] ?? null)->toBe((string) $material->id);
});

it('does NOT repend approved recipe when material allergen change produces empty rollup delta', function () {
    // Two materials share the same allergen — dropping one allergen from
    // material A leaves the rollup unchanged because material B still
    // supplies it. Rollup delta == empty → no repend.
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $materialA = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $materialB = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $materialA->allergens()->attach($allergen->id);
    $materialB->allergens()->attach($allergen->id);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $materialA->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [['material_id' => $materialB->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw']],
        'allergen_rollup' => [(string) $allergen->id],
    ]);

    // Attach a brand-new allergen to materialA AND keep the original. The
    // rollup gains a new id, so this case would actually repend — not
    // what we want. Instead, keep materialA's allergens equal to current
    // set (sync with same list) → delta is empty.
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/materials/{$materialA->id}", [
            'allergen_ids' => [$allergen->id],
        ])
        ->assertOk();

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Approved);

    $this->assertDatabaseMissing('audit_logs', [
        'auditable_type' => 'Recipe',
        'auditable_id' => $recipe->id,
        'action' => 'recipe.auto_repending',
    ]);
});

// =========================================================================
//  Cross-org / 401
// =========================================================================

it('returns 403 when approving a recipe in another org', function () {
    $otherOrg = fake()->uuid();

    $recipe = Recipe::factory()->create([
        'organization_id' => $otherOrg,
        'brand_id' => $this->brand->id,
        'approval_status' => ApprovalStatusEnum::Pending->value,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/approve")
        ->assertForbidden();
});

// =========================================================================
//  Edge cases + error handling (gap-fill — plan-003 TESTS.md)
// =========================================================================

it('fires auto_repending exactly once when a PUT changes both structural + non-structural fields', function () {
    // Plan-022 T18 — ingredients now need real material_id refs.
    $flour = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'description' => 'old description',
        'ingredients' => [['type' => 'material', 'material_id' => $flour->id, 'quantity' => 1, 'unit' => 'kg']],
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'description' => 'new description',
            'ingredients' => [['type' => 'material', 'material_id' => $flour->id, 'quantity' => 2, 'unit' => 'kg']],
        ])
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'pending');

    $repends = AuditLog::where('auditable_type', 'Recipe')
        ->where('auditable_id', $recipe->id)
        ->where('action', 'recipe.auto_repending')
        ->count();

    expect($repends)->toBe(1);
});

it('returns 404 when accessing a soft-deleted Recipe workflow endpoint', function () {
    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Pending->value,
    ]);

    $recipe->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/approve")
        ->assertNotFound();
});

it('returns 403 when a user reads allergens in another org brand', function () {
    $otherOrg = fake()->uuid();

    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrg,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$otherBrand->slug}/allergens")
        ->assertForbidden();
});

it('rolls back rollup recompute when the transaction fails mid-flight', function () {
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $recipe = makeRecipe([
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'allergen_rollup' => [],
    ]);

    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // Force the transaction to fail AFTER the rollup hook runs by stubbing
    // Material::update to throw via a simulated constraint violation. We do
    // this by sending an invalid allergen_ids shape that passes validation
    // but triggers a DB error on sync — use a non-uuid value wrapped in a
    // collection to defeat the validator's 'exists' rule by bypassing
    // validation entirely (call the service directly).
    $service = app(MaterialService::class);

    try {
        DB::transaction(function () use ($service, $material, $allergen) {
            $service->update($material, ['allergen_ids' => [$allergen->id]]);
            // Simulate a downstream failure after the rollup write.
            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Rollup should NOT have been persisted.
    expect($recipe->fresh()->allergen_rollup)->toBe([]);
});

// -------------------------------------------------------------------------
//  Gap-fill (plan-003 audit) — the rollback test above is VACUOUS: its
//  $material is not referenced by $recipe, so the rollup would stay [] with
//  OR without a rollback. This version wires the material as the recipe's
//  own output material (so a committed recompute genuinely changes the
//  rollup) and proves the abort reverts BOTH the pivot write and the
//  auto-repend, using a positive control to show the write is not a no-op.
// -------------------------------------------------------------------------

it('rolls back the pivot write AND the auto-repend when the surrounding transaction aborts (non-vacuous)', function () {
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // The material IS this recipe's rollup source (its output material), and
    // the recipe has no ingredients, so its rollup is derived solely from
    // $material's allergens. A committed allergen change therefore MUST move
    // the rollup off [] — which is exactly what makes the rollback assertion
    // load-bearing.
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [],
        'allergen_rollup' => [],
    ]);

    $service = app(MaterialService::class);

    // --- Rollback branch: mutate then throw inside the same transaction. ---
    try {
        DB::transaction(function () use ($service, $material, $allergen) {
            $service->update($material, ['allergen_ids' => [$allergen->id]]);
            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    $recipe->refresh();

    // Pivot sync reverted — the material has no allergen after rollback.
    $this->assertDatabaseMissing('material_allergens', [
        'material_id' => $material->id,
        'allergen_id' => $allergen->id,
    ]);
    // Rollup write reverted.
    expect($recipe->allergen_rollup)->toBe([]);
    // Auto-repend reverted — the recipe is still approved, not pending.
    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Approved);
    // No auto_repending audit row survived the rollback.
    $this->assertDatabaseMissing('audit_logs', [
        'auditable_type' => 'Recipe',
        'auditable_id' => $recipe->id,
        'action' => 'recipe.auto_repending',
    ]);

    // --- Positive control: the SAME operation, committed, DOES change state. ---
    // This is what proves the branch above rolled back rather than no-op'd.
    $service->update($material->fresh(), ['allergen_ids' => [$allergen->id]]);

    $recipe->refresh();
    $this->assertDatabaseHas('material_allergens', [
        'material_id' => $material->id,
        'allergen_id' => $allergen->id,
    ]);
    expect($recipe->allergen_rollup)->toBe([(string) $allergen->id]);
    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);
});

// -------------------------------------------------------------------------
//  Gap-fill (plan-003 audit) — cross-trigger repend when the changed
//  material is referenced by the recipe as an INGREDIENT (not as the
//  output material_id). The existing cross-trigger test only wires the
//  material through recipe.material_id, leaving the ingredients[] path of
//  AllergenRollupService::recipeReferencesAny untested.
// -------------------------------------------------------------------------

it('auto-repends approved recipe when an INGREDIENT-referenced material gains an allergen', function () {
    // Output material carries no allergens — the rollup delta must come purely
    // from the ingredient material so we know the ingredients[] path fired.
    $output = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $ingredient = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $output->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 50, 'unit' => 'g'],
        ],
        'allergen_rollup' => [],
    ]);

    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/materials/{$ingredient->id}", [
            'allergen_ids' => [$allergen->id],
        ])
        ->assertOk();

    $recipe->refresh();
    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);
    expect($recipe->allergen_rollup)->toBe([(string) $allergen->id]);

    $row = AuditLog::query()
        ->where('auditable_type', 'Recipe')
        ->where('auditable_id', $recipe->id)
        ->where('action', 'recipe.auto_repending')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->metadata['source'] ?? null)->toBe('material_allergen_change');
    // The audit points at the INGREDIENT material that actually changed.
    expect($row->metadata['material_id'] ?? null)->toBe((string) $ingredient->id);
});

// =========================================================================
//  plan-003 audit (logic-risk) — approval transitions serialize under a row
//  lock. Two concurrent actors must not both pass assertApprovalStatus on the
//  same pending row and drive conflicting transitions. The service re-fetches
//  the recipe under `lockForUpdate` inside a transaction before asserting, so
//  a stale in-memory instance can never win a lost-update race.
// =========================================================================

it('serializes concurrent approve/reject — a stale approve loses to a committed reject', function () {
    $recipe = makeRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

    // Two independent model instances, both loaded while the row is `pending`.
    // This models two requests that each read the row before either writes —
    // the exact window the row lock must close.
    $staleForReject = Recipe::findOrFail($recipe->id);
    $staleForApprove = Recipe::findOrFail($recipe->id);

    $service = app(RecipeService::class);

    // Actor 1 rejects → row is now `rejected` in the database.
    $service->reject($staleForReject, $this->approver, 'Missing data.');

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Rejected);

    // Actor 2's in-memory instance still shows `pending`, but the lock-and-refetch
    // sees the committed `rejected` state and refuses the illegal transition.
    expect(fn () => $service->approve($staleForApprove, $this->approver))
        ->toThrow(InvalidStatusTransitionException::class);

    // The row stays rejected — no lost update.
    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Rejected);
    $this->assertDatabaseMissing('audit_logs', [
        'auditable_type' => 'Recipe',
        'auditable_id' => $recipe->id,
        'action' => 'recipe.approved',
    ]);
});

// =========================================================================
//  Plan-022 T18 — Recipe constraints
// =========================================================================

describe('T18 ingredient + output constraints', function () {
    it('rejects empty ingredients on submit-for-approval', function () {
        $recipe = makeRecipe(['ingredients' => []]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/submit-for-approval")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ingredients']);
    });

    it('rejects self-reference (ingredient = recipe.material_id)', function () {
        $output = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Self-Ref Recipe',
                'brand_id' => $this->brand->id,
                'material_id' => $output->id,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    ['type' => 'material', 'material_id' => $output->id, 'quantity' => 10, 'unit' => 'g'],
                ],
            ])
            ->assertUnprocessable();
    });

    it('rejects duplicate ingredient material in same recipe', function () {
        $ing = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Dup Recipe',
                'brand_id' => $this->brand->id,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    ['type' => 'material', 'material_id' => $ing->id, 'quantity' => 5, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $ing->id, 'quantity' => 10, 'unit' => 'g'],
                ],
            ])
            ->assertUnprocessable();
    });

    it('rejects ingredient.quantity <= 0', function () {
        $ing = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Zero Qty Recipe',
                'brand_id' => $this->brand->id,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    ['type' => 'material', 'material_id' => $ing->id, 'quantity' => 0, 'unit' => 'g'],
                ],
            ])
            ->assertUnprocessable();
    });

    it('rejects cross-brand ingredient', function () {
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $crossIng = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $otherBrand->id]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Cross-Brand Recipe',
                'brand_id' => $this->brand->id,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    ['type' => 'material', 'material_id' => $crossIng->id, 'quantity' => 5, 'unit' => 'g'],
                ],
            ])
            ->assertUnprocessable();
    });

    it('rejects ingredient.unit not registered in MaterialUnits', function () {
        $ing = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $ing->materialUnits()->create(['unit' => 'g', 'ratio' => 1.0, 'is_base' => true]);

        $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/recipes", [
                'name' => 'Bad Unit Recipe',
                'brand_id' => $this->brand->id,
                'output_quantity' => 100,
                'output_unit' => 'g',
                'ingredients' => [
                    // 'liter' is not registered for $ing (only 'g' is).
                    ['type' => 'material', 'material_id' => $ing->id, 'quantity' => 5, 'unit' => 'liter'],
                ],
            ])
            ->assertUnprocessable();
    });

    it('auto-syncs Material.yield_unit on first recipe approval (A4)', function () {
        // Output material starts as RAW (yield_unit = null).
        $output = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'yield_unit' => null,
        ]);
        $ing = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $recipe = makeRecipe([
            'material_id' => $output->id,
            'approval_status' => ApprovalStatusEnum::Pending->value,
            'output_quantity' => 500,
            'output_unit' => 'g',
            'ingredients' => [
                ['type' => 'material', 'material_id' => $ing->id, 'quantity' => 100, 'unit' => 'g'],
            ],
        ]);

        $this->actingAs($this->approver)
            ->postJson("{$this->baseUrl}/recipes/{$recipe->id}/approve")
            ->assertOk();

        $output->refresh();
        expect($output->yield_unit)->toBe('g');
        expect($output->materialUnits()->where('is_base', true)->value('unit'))->toBe('g');
    });
});
