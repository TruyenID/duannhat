<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use App\Models\User;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Support\Str;

/**
 * plan-052 M2 / T2.2 — POST /shops/{shop}/print-jobs/{job}/resolve.
 *
 * The endpoint records what a HUMAN did about a job the pipeline could not
 * settle. Three properties are load-bearing:
 *
 *   - **It is not a retry button.** `resolution` has two values and neither of
 *     them re-sends anything. A money document that needs another copy goes
 *     through the reprint gate so it earns 「Bản in #N」 and an actor (P-10,
 *     RISKS PR1).
 *   - **It never mutates the ledger row.** Resolutions live in their own
 *     append-only table precisely so a `ws_lan` journal fact stays untouchable
 *     (DESIGN §1b) — the model would throw if we tried.
 *   - **Manager-only, with a reason.** #1124 governance: an audit line has to
 *     say who and why, and a space bar is not a why.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'resolve-shop',
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);

    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'resolve-other-shop',
        'is_active' => true,
    ]);

    // org-admin — a manager by the #1124 matrix.
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    // A cashier holds `staff`: enough for the shop-scoping middleware to let
    // them in (so the POLICY is what denies them, not the route ring).
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole('staff', $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/print-jobs";
});

function resolvableJob(array $overrides = [], ?string $branchId = null): PrintJob
{
    return PrintJob::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => $branchId ?? test()->shop->id,
        'transport' => PrintTransport::WsLan->value,
        'kind' => PrintJobKind::Receipt->value,
        'status' => PrintJobStatus::NeedsAttention->value,
        'printed_reported_at' => now()->subMinutes(5),
    ], $overrides));
}

function resolvePayload(array $overrides = []): array
{
    return array_merge([
        'resolution' => 'printed_by_hand',
        'reason' => 'wrote the receipt out by hand for the customer',
    ], $overrides);
}

describe('authorization matrix', function () {
    it('401s an anonymous caller', function () {
        $job = resolvableJob();

        $this->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())->assertUnauthorized();
    });

    it('403s a cashier — they may read the ledger but not close a line in it', function () {
        $job = resolvableJob();

        $this->actingAs($this->cashier)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
            ->assertForbidden();

        expect(PrintJobResolution::query()->count())->toBe(0);
    });

    it('lets a manager resolve', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
            ->assertCreated();

        expect(PrintJobResolution::query()->count())->toBe(1);
    });

    it('lets a branch-scoped shop-manager resolve their own shop', function () {
        $shopManager = User::factory()->create(['console_organization_id' => $this->orgId]);
        $shopManager->assignRole('shop-manager', $this->orgId, $this->shop->id);

        $job = resolvableJob();

        $this->actingAs($shopManager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
            ->assertCreated();
    });

    it('404s a job that belongs to another shop — existence is information too', function () {
        $foreign = resolvableJob([], $this->otherShop->id);

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$foreign->id}/resolve", resolvePayload())
            ->assertNotFound();

        expect(PrintJobResolution::query()->count())->toBe(0);
    });

    it('404s a job from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
        $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
        $foreignBranch = Branch::factory()->create([
            'console_organization_id' => $otherOrgId,
            'console_brand_id' => $otherBrand->console_brand_id,
        ]);

        $foreign = PrintJob::factory()->create([
            'organization_id' => $otherOrgId,
            'branch_id' => $foreignBranch->id,
            'status' => PrintJobStatus::NeedsAttention->value,
        ]);

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$foreign->id}/resolve", resolvePayload())
            ->assertNotFound();
    });
});

describe('validation — a resolution without a why is not an audit trail', function () {
    it('422s a missing reason', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", ['resolution' => 'printed_by_hand'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    });

    it('422s a whitespace-only reason — a space bar is not a why', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['reason' => "   \t  "]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        expect(PrintJobResolution::query()->count())->toBe(0);
    });

    it('422s a missing or unknown resolution', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", ['reason' => 'because'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['resolution' => 'shrug']))
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');
    });

    it('refuses `reprint` as a resolution — that is a different door with a different gate', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['resolution' => 'reprint']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resolution');
    });
});

describe('the write itself', function () {
    it('records the actor, the reason and the moment', function () {
        $job = resolvableJob();

        $response = $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['reason' => 'hand-written for the customer']))
            ->assertCreated();

        $row = PrintJobResolution::query()->where('print_job_id', $job->id)->firstOrFail();

        expect($row->resolved_by_id)->toBe($this->manager->id)
            ->and($row->reason)->toBe('hand-written for the customer')
            ->and($row->resolution->value)->toBe('printed_by_hand')
            ->and($row->resolved_at)->not->toBeNull()
            ->and($response->json('data.resolution.resolved_by_id'))->toBe($this->manager->id);
    });

    it('leaves the ws_lan ledger row completely untouched (§1b)', function () {
        $job = resolvableJob();
        $before = PrintJob::query()->whereKey($job->id)->first()->toArray();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
            ->assertCreated();

        expect(PrintJob::query()->whereKey($job->id)->first()->toArray())->toEqual($before);
    });

    it('never changes the job status or bumps attempts', function () {
        $job = resolvableJob(['status' => PrintJobStatus::Failed->value, 'attempts' => 2]);

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['resolution' => 'discarded']))
            ->assertCreated();

        expect($job->fresh()->status)->toBe(PrintJobStatus::Failed)
            ->and($job->fresh()->attempts)->toBe(2);
    });

    it('is idempotent — the FIRST decision stands', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['reason' => 'first call']))
            ->assertCreated();

        $secondManager = User::factory()->create(['console_organization_id' => $this->orgId]);
        grantOrgAccess($secondManager, $this->orgId);

        $second = $this->actingAs($secondManager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload([
                'resolution' => 'discarded',
                'reason' => 'second call tries to rewrite history',
            ]))
            ->assertOk();

        expect(PrintJobResolution::query()->count())->toBe(1)
            ->and($second->json('data.resolution.reason'))->toBe('first call')
            ->and($second->json('data.resolution.resolution'))->toBe('printed_by_hand')
            ->and($second->json('data.resolution.resolved_by_id'))->toBe($this->manager->id);
    });

    it('409s a job the ledger already reports as printed', function () {
        $job = resolvableJob(['status' => PrintJobStatus::Printed->value]);

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRINT_JOB_ALREADY_PRINTED');

        expect(PrintJobResolution::query()->count())->toBe(0);
    });

    it('accepts every other status — failed, expired, needs_attention, queued, delivering', function () {
        foreach ([
            PrintJobStatus::Failed,
            PrintJobStatus::Expired,
            PrintJobStatus::NeedsAttention,
            PrintJobStatus::Queued,
            PrintJobStatus::Delivering,
        ] as $status) {
            $job = resolvableJob(['status' => $status->value]);

            $this->actingAs($this->manager)
                ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload())
                ->assertCreated();
        }

        expect(PrintJobResolution::query()->count())->toBe(5);
    });

    it('surfaces the resolution on the list and detail views', function () {
        $job = resolvableJob();

        $this->actingAs($this->manager)
            ->postJson("{$this->base}/{$job->id}/resolve", resolvePayload(['reason' => 'printed from the backup roll']))
            ->assertCreated();

        $this->actingAs($this->manager)->getJson("{$this->base}/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.resolution.reason', 'printed from the backup roll');

        $this->actingAs($this->manager)->getJson("{$this->base}?unresolved_only=1")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});
