<?php

/**
 * plan-055 T2.3 (#1838) — the branch list has to be VISIBLE.
 *
 * The preconditions were computed and then rendered nowhere: the table printed
 * only `condition_met`, so the coverage number and the list of branches that
 * cannot take a payment existed solely under `--json`. The whole premise of this
 * command is that it is where a human reads the state before flipping a flag —
 * and the one number that decides "is it safe" was the one they could not see.
 *
 * Caught in review, which is exactly the kind of half-delivery a fresh reader
 * notices and the author does not.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentPolicyRevision;
use Illuminate\Support\Str;

it('#1838 prints the unmet precondition and the offending branches in the human table path', function () {
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
        'name' => 'Readiness Output Org',
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $organizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    // Has a revision, cannot take a payment — the state the flip must not miss.
    PaymentPolicyRevision::factory()->create(['branch_id' => $branch->id, 'revision' => 1]);

    $this->artisan('payments:legacy-removal-readiness')
        ->expectsOutputToContain('precondition NOT met — policy_revision_coverage')
        ->expectsOutputToContain('Readiness Output Org')
        ->expectsOutputToContain((string) $branch->id)
        ->assertExitCode(0);
});
