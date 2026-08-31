<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Models\Role;
use App\Models\User;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrintJobJournalIngestService;
use App\Services\Printing\ReprintAdvisor;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

/**
 * plan-052 §4 / P-10 + P-10b (#1166) — reprint WARNS, it never BLOCKS.
 *
 * This file used to assert the opposite: that a cashier reprinting a receipt
 * without a typed reason got a 422. The owner reversed that on 2026-07-28, so
 * every one of those cases is rewritten here rather than deleted — the same
 * scenarios, asserting the behaviour that replaced the refusal:
 *
 *   1. it still prints (200, every role, every kind, reason or not);
 *   2. the ledger records WHO, and flags that no reason was given;
 *   3. copy N ≥ 2 carries the 「再印刷 #N」 mark (marker_will_print).
 *
 * The Go side proves point 3 on actual paper
 * (workstation/internal/service/print_reprint_marker_test.go); here we
 * prove Cloud always TELLS the client the mark is coming, so no POS can offer
 * a "silent copy".
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    (new IamSeeder)->run();
    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);

    $this->advisor = new ReprintAdvisor;
});

function reprintCashier(): User
{
    $user = User::factory()->create(['console_organization_id' => test()->orgId]);
    $role = Role::query()->where('slug', 'shop-staff')->first()
        ?? Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 20]);
    $user->assignRole($role, test()->orgId);

    return $user;
}

function reprintManager(): User
{
    $user = User::factory()->create(['console_organization_id' => test()->orgId]);
    $user->assignRole(Role::query()->where('slug', 'shop-manager')->firstOrFail(), test()->orgId);

    return $user;
}

/**
 * @param  list<array{code: string, severity: string, message: string, params?: array<string, mixed>}>  $warnings
 * @return list<string>
 */
function warningCodes(array $warnings): array
{
    return array_column($warnings, 'code');
}

describe('P-10 — the advisor never refuses', function () {
    it('lets a cashier reprint with no reason, and says why it is warning', function () {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintCashier(), null, $this->orgId, $this->branch->id,
        );

        expect($advice->allowed())->toBeTrue()
            ->and($advice->requiresReasonPrompt)->toBeTrue()
            ->and($advice->warnedWithoutReason)->toBeTrue()
            ->and($advice->reprintReason)->toBeNull()
            ->and($advice->markerWillPrint)->toBeTrue()
            ->and(warningCodes($advice->warnings))
            ->toContain('reprint_reason_missing', 'reprint_marker_will_print');
    });

    it('treats whitespace as no reason at all — but still prints', function () {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintCashier(), "   \n ", $this->orgId, $this->branch->id,
        );

        expect($advice->allowed())->toBeTrue()
            ->and($advice->warnedWithoutReason)->toBeTrue()
            ->and($advice->reprintReason)->toBeNull();
    });

    it('stops warning about the reason once the cashier gives one', function () {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintCashier(), 'khách làm rách hoá đơn', $this->orgId, $this->branch->id,
        );

        expect($advice->warnedWithoutReason)->toBeFalse()
            ->and($advice->reprintReason)->toBe('khách làm rách hoá đơn')
            ->and(warningCodes($advice->warnings))->not->toContain('reprint_reason_missing')
            // The mark is not negotiable — a reason does not buy a clean copy.
            ->and($advice->markerWillPrint)->toBeTrue();
    });

    it('softens the wording for a manager but records the same fact', function () {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintManager(), null, $this->orgId, $this->branch->id,
        );

        $reasonWarning = collect($advice->warnings)->firstWhere('code', 'reprint_reason_missing');

        expect($advice->actorIsManager)->toBeTrue()
            ->and($reasonWarning['severity'])->toBe('notice')
            // Role changes the volume, never the ledger.
            ->and($advice->warnedWithoutReason)->toBeTrue();
    });

    it('warns a cashier louder than a manager, and blocks neither', function () {
        $cashier = collect($this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintCashier(), null, $this->orgId, $this->branch->id,
        )->warnings)->firstWhere('code', 'reprint_reason_missing');

        expect($cashier['severity'])->toBe('warning');
    });

    it('never refuses any money-document kind', function (string $kind) {
        $advice = $this->advisor->advise(
            PrintJobKind::from($kind), 2, reprintCashier(), null, $this->orgId, $this->branch->id,
        );

        expect($advice->allowed())->toBeTrue()
            ->and($advice->isMoneyDocument)->toBeTrue()
            ->and($advice->warnedWithoutReason)->toBeTrue();
    })->with(['receipt', 'red_invoice', 'debt_slip']);

    it('handles an unknown actor without pretending to know them', function () {
        // Nobody signed in (device-token print). It still prints; the ledger
        // simply cannot name a human, which is an honest gap rather than a
        // reason to stop the shop.
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, null, null, $this->orgId, $this->branch->id,
        );

        expect($advice->allowed())->toBeTrue()
            ->and($advice->actorIsManager)->toBeFalse()
            ->and($advice->actorUserId)->toBeNull()
            ->and($advice->warnedWithoutReason)->toBeTrue();
    });

    it('keeps warning at every further copy — the count never resets', function (int $n) {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, $n, reprintCashier(), null, $this->orgId, $this->branch->id,
        );

        expect($advice->reprintNo)->toBe($n)
            ->and($advice->requiresReasonPrompt)->toBeTrue()
            ->and($advice->markerWillPrint)->toBeTrue();
    })->with([2, 3, 7, 99]);
});

describe('what is NOT even asked about', function () {
    it('never prompts on the FIRST print — it is not a reprint', function () {
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 1, reprintCashier(), null, $this->orgId, $this->branch->id,
        );

        expect($advice->requiresReasonPrompt)->toBeFalse()
            ->and($advice->warnedWithoutReason)->toBeFalse()
            // P-10b — copy 1 must NOT carry the mark, or the mark means nothing.
            ->and($advice->markerWillPrint)->toBeFalse()
            ->and($advice->warnings)->toBe([]);
    });

    it('never prompts on a kitchen ticket, at any copy number', function (string $kind) {
        $advice = $this->advisor->advise(
            PrintJobKind::from($kind), 5, reprintCashier(), null, $this->orgId, $this->branch->id,
        );

        expect($advice->requiresReasonPrompt)->toBeFalse()
            ->and($advice->warnedWithoutReason)->toBeFalse()
            ->and($this->advisor->requiresReasonPrompt(PrintJobKind::from($kind), 5))->toBeFalse();
    })->with(['kitchen', 'bar', 'label', 'report', 'diagnostic']);
});

describe('P-11 — reprint while the original is still in flight', function () {
    it('does not consult job state at all: the operator at the machine knows better', function () {
        // A half-fed slip is a fact the ledger cannot see. The advisor asks
        // about the DOCUMENT and the PERSON, never about whether some earlier
        // row reached a terminal status — and it answers "print" regardless.
        $advice = $this->advisor->advise(
            PrintJobKind::Receipt, 2, reprintCashier(), 'kẹt giấy nửa tờ', $this->orgId, $this->branch->id,
        );

        expect($advice->allowed())->toBeTrue();
    });
});

describe('POST /api/v1/pos/print-jobs/reprint-advice', function () {
    it('answers 200 to a cashier who omits the reason — with the warning payload', function () {
        $cashier = reprintCashier();

        $this->actingAs($cashier)
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.requires_reason_prompt', true)
            ->assertJsonPath('data.warned_without_reason', true)
            ->assertJsonPath('data.marker_will_print', true)
            ->assertJsonPath('data.reprint_reason', null)
            ->assertJsonPath('data.actor_user_id', $cashier->id)
            ->assertJsonPath('data.warnings.0.code', 'reprint_reason_missing')
            ->assertJsonPath('data.warnings.0.severity', 'warning');
    });

    it('keeps the legacy keys so an older pos-web build cannot block on them', function () {
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true);
    });

    it('serves the legacy /reprint-authorization path with the same 200 body', function () {
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-authorization', [
                'kind' => 'receipt',
                'reprint_no' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.warned_without_reason', true);
    });

    it('answers 200 to a cashier with a reason and echoes the actor for the ledger', function () {
        $cashier = reprintCashier();

        $this->actingAs($cashier)
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
                'reprint_reason' => 'máy kẹt giấy',
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.warned_without_reason', false)
            ->assertJsonPath('data.actor_user_id', $cashier->id)
            ->assertJsonPath('data.reprint_reason', 'máy kẹt giấy');
    });

    it('answers 200 to a manager without a reason', function () {
        $this->actingAs(reprintManager())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'red_invoice',
                'reprint_no' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.actor_is_manager', true);
    });

    it('has NO path that answers 4xx because of a role', function (string $kind) {
        // The lowest role there is, on the most sensitive document, on a
        // late copy, with nothing typed. Still 200.
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => $kind,
                'reprint_no' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true);
    })->with(['receipt', 'red_invoice', 'debt_slip']);

    it('reports a kitchen reprint as needing no prompt', function () {
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'kitchen',
                'reprint_no' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.requires_reason_prompt', false)
            ->assertJsonPath('data.gated', false);
    });

    it('still 422s an unknown document kind — that is a client bug, not a policy', function () {
        $this->actingAs(reprintManager())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'mystery',
                'reprint_no' => 2,
            ])
            ->assertStatus(422);
    });

    it('404s a print job that does not exist', function () {
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
                'print_job_id' => (string) Str::uuid(),
            ])
            ->assertStatus(404);
    });

    it('404s a print job that belongs to another shop', function () {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
        $foreign = PrintJob::factory()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
                'print_job_id' => $foreign->id,
            ])
            ->assertStatus(404);
    });

    it('404s a payment that does not exist in this shop', function () {
        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
                'payment_id' => (string) Str::uuid(),
            ])
            ->assertStatus(404);
    });

    it('advises normally on a print job that DOES belong to this shop', function () {
        $job = PrintJob::factory()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs(reprintCashier())
            ->withHeader('X-Shop-Slug', $this->branch->slug)
            ->postJson('/api/v1/pos/print-jobs/reprint-advice', [
                'kind' => 'receipt',
                'reprint_no' => 2,
                'print_job_id' => $job->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true);
    });
});

describe('§4 point 2 — the ledger records WHO, on every print', function () {
    beforeEach(function () {
        $this->ingest = app(PrintJobJournalIngestService::class);
        $this->cashier = reprintCashier();
    });

    it('stamps actor + channel on the FIRST print, not just reprints', function () {
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'receipt',
            'status' => 'printed',
            'reprint_no' => 1,
            'requested_by_id' => $this->cashier->id,
            'requested_via' => 'pos',
            'printed_at' => now()->toIso8601String(),
        ]]);

        $job = PrintJob::query()->findOrFail($id);

        expect($job->requested_by_id)->toBe($this->cashier->id)
            ->and($job->requested_via)->toBe('pos')
            ->and($job->warnedWithoutReason())->toBeFalse();
    });

    it('flags a money reprint that arrived with no reason, and keeps the reason null', function () {
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'receipt',
            'status' => 'printed',
            'reprint_no' => 2,
            'requested_by_id' => $this->cashier->id,
            'requested_via' => 'pos',
            'printed_at' => now()->toIso8601String(),
        ]]);

        $job = PrintJob::query()->findOrFail($id);

        expect($job->warnedWithoutReason())->toBeTrue()
            ->and($job->reprint_reason)->toBeNull()
            ->and($job->requested_by_id)->toBe($this->cashier->id)
            ->and($job->reprint_no)->toBe(2);
    });

    it('does not flag a reprint that carried a reason', function () {
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'debt_slip',
            'status' => 'printed',
            'reprint_no' => 3,
            'reprint_reason' => 'khách yêu cầu bản thứ hai',
            'requested_via' => 'pos',
        ]]);

        $job = PrintJob::query()->findOrFail($id);

        expect($job->warnedWithoutReason())->toBeFalse()
            ->and($job->reprint_reason)->toBe('khách yêu cầu bản thứ hai');
    });

    it('never flags a kitchen ticket, however many copies came out', function () {
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'kitchen',
            'status' => 'printed',
            'reprint_no' => 6,
        ]]);

        expect(PrintJob::query()->findOrFail($id)->warnedWithoutReason())->toBeFalse();
    });

    it('derives the flag even when an older workstation never sends it', function () {
        // The whole point of deriving rather than trusting: a binary shipped
        // before this ruling knows nothing about the flag, and the shop that
        // runs it must still be auditable.
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'red_invoice',
            'status' => 'printed',
            'reprint_no' => 2,
        ]]);

        expect(PrintJob::query()->findOrFail($id)->warnedWithoutReason())->toBeTrue();
    });

    it('drops an actor Cloud has never heard of instead of losing the whole batch', function () {
        // `requested_by_id` is a foreign key and a batch is ONE insert, so a
        // single stale id from a tablet that remembers a deleted user would
        // otherwise reject an entire evening of prints — rows that exist
        // nowhere else. The journal records; it never refuses.
        $good = (string) Str::uuid();
        $orphan = (string) Str::uuid();

        $result = $this->ingest->ingest($this->org->id, $this->branch->id, [
            [
                'id' => $good,
                'kind' => 'receipt',
                'status' => 'printed',
                'reprint_no' => 1,
                'requested_by_id' => $this->cashier->id,
                'requested_via' => 'pos',
            ],
            [
                'id' => $orphan,
                'kind' => 'receipt',
                'status' => 'printed',
                'reprint_no' => 2,
                'requested_by_id' => (string) Str::uuid(), // no such user
                'requested_via' => 'pos',
            ],
        ]);

        expect($result['accepted'])->toHaveCount(2)
            ->and(PrintJob::query()->findOrFail($good)->requested_by_id)->toBe($this->cashier->id)
            // The print survives; only the name is unknown — and the rest of
            // the trail (channel, count, missing-reason flag) is intact.
            ->and(PrintJob::query()->findOrFail($orphan)->requested_by_id)->toBeNull()
            ->and(PrintJob::query()->findOrFail($orphan)->requested_via)->toBe('pos')
            ->and(PrintJob::query()->findOrFail($orphan)->warnedWithoutReason())->toBeTrue();
    });

    it('defaults the channel to `workstation` rather than leaving the trail blank', function () {
        $id = (string) Str::uuid();

        $this->ingest->ingest($this->org->id, $this->branch->id, [[
            'id' => $id,
            'kind' => 'receipt',
            'status' => 'printed',
            'reprint_no' => 1,
        ]]);

        expect(PrintJob::query()->findOrFail($id))
            ->requested_via->toBe('workstation')
            ->transport->toBe(PrintTransport::WsLan);
    });
});
