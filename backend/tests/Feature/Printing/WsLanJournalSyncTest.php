<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Print\TemplateStamp;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\Enums\UposPrinterStatus;
use Illuminate\Support\Str;

/**
 * plan-052 T1.2 (#1166) — the ws_lan journal, synced UP.
 *
 * Everything here defends ONE property: the workstation prints, and Cloud
 * writes down what happened. Cloud is never in the loop, never authoritative
 * about a queue it does not run, and never able to turn a shop's printing off
 * by being unreachable (RISKS PR2).
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

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function journalHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

function journalEntry(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'kind' => 'kitchen',
        'status' => 'printed',
        'confidence' => 'sent_only',
        'printed_at' => now()->subMinutes(5)->toIso8601String(),
        'attempts' => 1,
    ], $overrides);
}

function postJournal(array $jobs)
{
    return test()->withHeaders(journalHeaders())
        ->postJson('/api/v1/workstation/print-jobs', ['jobs' => $jobs]);
}

describe('P-07 the ledger records the REAL print time', function () {
    it('keeps the time the paper came out, not the time the batch synced', function () {
        // A shop that lost its internet at 20:00 and reconnected the next
        // morning must not have last night's tickets stamped 09:00 — that
        // drags every one of them into the wrong business day (#1091).
        $printedAt = now()->subHours(13);
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id, 'printed_at' => $printedAt->toIso8601String()])])
            ->assertStatus(202);

        $job = PrintJob::find($id);

        expect($job->printed_reported_at->timestamp)->toBe($printedAt->timestamp)
            ->and($job->created_at->timestamp)->toBeGreaterThan($printedAt->timestamp);
    });

    it('derives the TTL from the issue time so a stale ticket cannot look fresh', function () {
        $printedAt = now()->subHours(13);
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id, 'printed_at' => $printedAt->toIso8601String()])])
            ->assertStatus(202);

        expect(PrintJob::find($id)->expires_at->timestamp)
            ->toBe($printedAt->copy()->addMinutes(15)->timestamp);
    });

    it('stamps ws_lan as the transport even if the device claims otherwise', function () {
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id, 'transport' => 'cloudprnt'])])->assertStatus(202);

        expect(PrintJob::find($id)->transport)->toBe(PrintTransport::WsLan);
    });
});

describe('P-09 idempotency lives in the DB constraint', function () {
    it('accepts a replayed batch without doubling a row', function () {
        $entry = journalEntry();

        postJournal([$entry])->assertStatus(202)
            ->assertJsonPath('data.accepted', [$entry['id']]);

        $second = postJournal([$entry])->assertStatus(202);

        expect($second->json('data.accepted'))->toBe([])
            ->and($second->json('data.duplicates'))->toBe([$entry['id']])
            ->and(PrintJob::where('id', $entry['id'])->count())->toBe(1);
    });

    it('collapses a duplicate id repeated INSIDE one batch', function () {
        $entry = journalEntry();

        postJournal([$entry, $entry])->assertStatus(202);

        expect(PrintJob::where('id', $entry['id'])->count())->toBe(1);
    });

    it('does not let a replay overwrite the recorded fact', function () {
        $entry = journalEntry(['status' => 'printed']);
        postJournal([$entry])->assertStatus(202);

        // The device re-sends the same job id claiming it failed. The first
        // fact stands — a journal row is history, not a mutable opinion.
        postJournal([array_merge($entry, ['status' => 'failed', 'last_error' => 'paper_end'])])
            ->assertStatus(202);

        $job = PrintJob::find($entry['id']);
        expect($job->status)->toBe(PrintJobStatus::Printed)
            ->and($job->last_error)->toBeNull();
    });
});

describe('§1b Cloud never owns a ws_lan row', function () {
    it('refuses to transition a journal row', function () {
        $job = PrintJob::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        expect(fn () => $job->update(['status' => PrintJobStatus::Failed->value]))
            ->toThrow(LogicException::class, 'journal rows');
    });

    it('refuses to delete a journal row', function () {
        $job = PrintJob::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        expect(fn () => $job->delete())->toThrow(LogicException::class, 'append-only');
    });

    it('DOES let Cloud drive a cloudprnt row — that queue is genuinely Cloud-owned', function () {
        $job = PrintJob::factory()->cloudQueued()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        $job->update(['status' => PrintJobStatus::Delivering->value, 'attempts' => 1]);

        expect($job->fresh()->status)->toBe(PrintJobStatus::Delivering);
    });
});

describe('P-33 [HARD] confidence is never invented', function () {
    it('clamps a `confirmed` claim from a printer that cannot confirm anything', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            // escpos_generic: error_detect.level = none.
            'model_profile' => null,
        ]);

        $id = (string) Str::uuid();
        postJournal([journalEntry([
            'id' => $id,
            'printer_id' => $printer->id,
            'confidence' => 'confirmed',
        ])])->assertStatus(202);

        expect(PrintJob::find($id)->confidence)->toBe(PrintConfidence::SentOnly);
    });

    it('keeps `confirmed` when the machine really can report back', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'model_profile' => ['preset' => 'star_mcprint'],
        ]);

        $id = (string) Str::uuid();
        postJournal([journalEntry([
            'id' => $id,
            'printer_id' => $printer->id,
            'confidence' => 'confirmed',
        ])])->assertStatus(202);

        expect(PrintJob::find($id)->confidence)->toBe(PrintConfidence::Confirmed);
    });

    it('never promotes a stored sent_only row to confirmed', function () {
        $job = PrintJob::factory()->cloudQueued()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'confidence' => PrintConfidence::SentOnly->value,
        ]);

        expect(fn () => $job->update(['confidence' => PrintConfidence::Confirmed->value]))
            ->toThrow(LogicException::class, 'one-way');
    });
});

describe('T1.3 — printer status normalised to UPOS', function () {
    it('records the status the workstation observed onto the printer row', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'model_profile' => ['preset' => 'star_mcprint'],
            'last_status' => null,
        ]);

        $observedAt = now()->subMinutes(20);

        postJournal([journalEntry([
            'printer_id' => $printer->id,
            'printer_status' => 'paper_end',
            'status' => 'failed',
            'printed_at' => $observedAt->toIso8601String(),
        ])])->assertStatus(202);

        $printer->refresh();
        expect($printer->last_status)->toBe(UposPrinterStatus::PaperEnd)
            ->and($printer->last_seen_at->timestamp)->toBe($observedAt->timestamp);
    });

    it('keeps the newest observation when a batch carries several', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        postJournal([
            journalEntry([
                'printer_id' => $printer->id,
                'printer_status' => 'paper_end',
                'printed_at' => now()->subMinutes(30)->toIso8601String(),
            ]),
            journalEntry([
                'printer_id' => $printer->id,
                'printer_status' => 'ok',
                'printed_at' => now()->subMinutes(2)->toIso8601String(),
            ]),
        ])->assertStatus(202);

        expect($printer->fresh()->last_status)->toBe(UposPrinterStatus::Ok);
    });

    it('422s a status outside the UPOS vocabulary', function () {
        postJournal([journalEntry(['printer_status' => 'feeling_unwell'])])->assertStatus(422);
    });

    it('leaves last_status alone when the batch reports none', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'last_status' => UposPrinterStatus::CoverOpen->value,
        ]);

        postJournal([journalEntry(['printer_id' => $printer->id])])->assertStatus(202);

        expect($printer->fresh()->last_status)->toBe(UposPrinterStatus::CoverOpen);
    });

    it('blocks a money document when the machine is in a bad state', function () {
        // The rule the M2 preflight (P-34) will enforce: a receipt must not be
        // fired into a printer with the cover open.
        expect(UposPrinterStatus::CoverOpen->blocksMoneyDocument())->toBeTrue()
            ->and(UposPrinterStatus::PaperEnd->blocksMoneyDocument())->toBeTrue()
            ->and(UposPrinterStatus::Offline->blocksMoneyDocument())->toBeTrue()
            ->and(UposPrinterStatus::Ok->blocksMoneyDocument())->toBeFalse()
            // Near-end still prints — refusing here would stop a shop over a
            // warning that exists precisely to be printed through.
            ->and(UposPrinterStatus::PaperNearEnd->blocksMoneyDocument())->toBeFalse();
    });

    it('maps the workstation vocabulary onto UPOS', function () {
        expect(UposPrinterStatus::fromWorkstationStatus('online'))->toBe(UposPrinterStatus::Ok)
            // `printing` is busy, not broken.
            ->and(UposPrinterStatus::fromWorkstationStatus('printing'))->toBe(UposPrinterStatus::Ok)
            ->and(UposPrinterStatus::fromWorkstationStatus('offline'))->toBe(UposPrinterStatus::Offline)
            ->and(UposPrinterStatus::fromWorkstationStatus('error'))->toBe(UposPrinterStatus::Error)
            // Unknown → offline: assuming a machine we cannot read is fine is
            // how a dead printer stays green on a dashboard.
            ->and(UposPrinterStatus::fromWorkstationStatus('who knows'))->toBe(UposPrinterStatus::Offline);
    });
});

describe('journal ingest — validation and scope', function () {
    it('rejects an unauthenticated batch', function () {
        $this->postJson('/api/v1/workstation/print-jobs', ['jobs' => [journalEntry()]])
            ->assertUnauthorized();
    });

    it('422s an entry with no id — idempotency would have nothing to key on', function () {
        $entry = journalEntry();
        unset($entry['id']);

        postJournal([$entry])->assertStatus(422);
    });

    it('422s an unknown kind rather than silently losing its retry policy', function () {
        postJournal([journalEntry(['kind' => 'mystery'])])->assertStatus(422);
    });

    it('scopes every row to the device branch, ignoring any claim in the body', function () {
        $id = (string) Str::uuid();
        postJournal([journalEntry(['id' => $id, 'branch_id' => (string) Str::uuid()])])
            ->assertStatus(202);

        expect(PrintJob::find($id)->branch_id)->toBe($this->branch->id);
    });

    it('drops a printer_id that belongs to another branch instead of forging an FK', function () {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
        $foreign = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $otherBranch->id,
        ]);

        $id = (string) Str::uuid();
        postJournal([journalEntry(['id' => $id, 'printer_id' => $foreign->id])])->assertStatus(202);

        expect(PrintJob::find($id)->printer_id)->toBeNull();
    });

    it('records the actor and reason a reprint carried', function () {
        $id = (string) Str::uuid();

        postJournal([journalEntry([
            'id' => $id,
            'kind' => 'receipt',
            'reprint_no' => 2,
            'reprint_reason' => 'khách làm rách hoá đơn',
            'requested_via' => 'pos',
        ])])->assertStatus(202);

        $job = PrintJob::find($id);
        expect($job->reprint_no)->toBe(2)
            ->and($job->reprint_reason)->toBe('khách làm rách hoá đơn')
            ->and($job->requested_via)->toBe('pos');
    });
});

describe('TR-28 the sheet records WHICH layout drew it (#1171)', function () {
    it('stores the stamp the device sent, verbatim', function () {
        // Verbatim, not re-resolved. The workstation is the only tier that knows
        // — it is the tier that rendered — and Cloud re-resolving here would
        // answer with whatever is current TODAY, which for a sheet printed last
        // month is a different document.
        $id = (string) Str::uuid();

        postJournal([journalEntry([
            'id' => $id,
            'kind' => 'red_invoice',
            'template_version' => 'brand:7',
        ])])->assertStatus(202);

        expect(PrintJob::find($id)->template_version)->toBe('brand:7');
    });

    it('leaves the column NULL when the legacy formatter drew the sheet', function () {
        // NULL is a real state, not a gap: the workstation's old formatter is Go
        // CODE, not a published definition, so it carries no version at all.
        // Defaulting it to `system:0` would send a later reprint to the embedded
        // default for a sheet the formatter drew — the exact silent divergence
        // this column exists to prevent.
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id])])->assertStatus(202);

        expect(PrintJob::find($id)->template_version)->toBeNull();
    });

    it('collapses a blank stamp to NULL rather than storing an empty string', function () {
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id, 'template_version' => '   '])])->assertStatus(202);

        // '' would be neither an honest NULL nor a readable stamp, and every
        // reader downstream would then have to know about a third state.
        expect(PrintJob::find($id)->template_version)->toBeNull();
    });

    it('records the stamp on a FAILED print too', function () {
        // The ledger records attempts (§4). "We tried to draw it with brand:9" is
        // as true of a jam as of a clean sheet — and an investigation into a
        // wrong-looking slip needs it most when the first attempt went wrong.
        $id = (string) Str::uuid();

        postJournal([journalEntry([
            'id' => $id,
            'status' => 'failed',
            'last_error' => 'paper out',
            'template_version' => 'shop:12',
        ])])->assertStatus(202);

        $job = PrintJob::find($id);
        expect($job->status)->toBe(PrintJobStatus::Failed)
            ->and($job->template_version)->toBe('shop:12');
    });

    it('accepts a batch whose stamp is malformed instead of losing the history', function () {
        // Validation is thin here on purpose. These rows describe prints that
        // ALREADY HAPPENED and exist nowhere else; refusing an evening of them
        // over a provenance string is the trade this endpoint does not make. The
        // shape is checked where it is READ, and an unreadable stamp simply
        // resolves to "no layout recorded".
        $id = (string) Str::uuid();

        postJournal([journalEntry(['id' => $id, 'template_version' => 'not-a-stamp'])])
            ->assertStatus(202);

        $job = PrintJob::find($id);
        expect($job->template_version)->toBe('not-a-stamp');
        expect(TemplateStamp::parse($job->template_version))->toBeNull();
    });
});
