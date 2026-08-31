<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'name' => 'Test Org',
        'slug' => 'test-org',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
});

function approvalRecipe(array $attrs = []): Recipe
{
    return Recipe::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [],
    ], $attrs));
}

it('assertApprovalStatus throws InvalidStatusTransitionException with structured fields on mismatch', function () {
    $recipe = approvalRecipe(['approval_status' => ApprovalStatusEnum::Draft->value]);

    try {
        $recipe->assertApprovalStatus([ApprovalStatusEnum::Pending], 'approve');
        $this->fail('Expected InvalidStatusTransitionException');
    } catch (InvalidStatusTransitionException $e) {
        expect($e->from)->toBe('draft');
        expect($e->action)->toBe('approve');
        expect($e->allowed)->toBe(['pending']);
        expect($e->subject)->toBe('recipe');
    }
});

it('assertApprovalStatus passes silently when current status is in the allowed set', function () {
    $recipe = approvalRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

    $recipe->assertApprovalStatus([ApprovalStatusEnum::Pending], 'approve');

    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);
});

it('markAsPending preserves rejection_reason while clearing rejected_by/rejected_at', function () {
    $reviewer = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $recipe = approvalRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);
    $recipe->markAsRejected($reviewer, 'Missing allergen data.');

    $recipe->refresh();
    expect($recipe->rejection_reason)->toBe('Missing allergen data.');
    expect($recipe->rejected_by_id)->not->toBeNull();

    $recipe->markAsPending();
    $recipe->refresh();

    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Pending);
    expect($recipe->rejected_by_id)->toBeNull();
    expect($recipe->rejected_at)->toBeNull();
    expect($recipe->rejection_reason)->toBe('Missing allergen data.');
});

it('markAsApproved sets approver + timestamp and clears rejected fields', function () {
    $reviewer = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $recipe = approvalRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

    // Pollute the rejected_* columns first to verify they get cleared.
    $recipe->markAsRejected($reviewer, 'bad');
    $recipe->markAsPending();

    $recipe->markAsApproved($this->user);
    $recipe->refresh();

    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Approved);
    expect($recipe->approved_by_id)->toBe($this->user->id);
    expect($recipe->approved_at)->not->toBeNull();
    expect($recipe->rejected_by_id)->toBeNull();
    expect($recipe->rejected_at)->toBeNull();
});

it('markAsRejected sets rejecter + timestamp + reason', function () {
    $recipe = approvalRecipe(['approval_status' => ApprovalStatusEnum::Pending->value]);

    $recipe->markAsRejected($this->user, 'Ingredients mismatch.');
    $recipe->refresh();

    expect($recipe->getApprovalStatus())->toBe(ApprovalStatusEnum::Rejected);
    expect($recipe->rejected_by_id)->toBe($this->user->id);
    expect($recipe->rejected_at)->not->toBeNull();
    expect($recipe->rejection_reason)->toBe('Ingredients mismatch.');
});
