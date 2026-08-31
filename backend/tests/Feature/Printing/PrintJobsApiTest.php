<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * plan-052 M2 / T2.2 — GET /api/v1/shops/{shopSlug}/print-jobs.
 *
 * Two things this suite cares about more than the filters:
 *
 *   - **Tenant isolation.** A print job carries order codes and printer names.
 *     The plan-038 debts leak came from exactly one missing branch predicate
 *     on an aggregate, so every listing path here is probed from the outside.
 *   - **P-33 `confidence`.** The API must state, per row, whether "printed"
 *     means "the machine confirmed it" or only "the bytes left". A screen that
 *     cannot tell the two apart is a screen that lies to an operator.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'print-jobs-shop',
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);

    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'print-jobs-other-shop',
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/print-jobs";
});

function apiJob(array $overrides = [], ?string $branchId = null, ?string $orgId = null): PrintJob
{
    return PrintJob::factory()->create(array_merge([
        'organization_id' => $orgId ?? test()->orgId,
        'branch_id' => $branchId ?? test()->shop->id,
        'transport' => PrintTransport::WsLan->value,
        'kind' => PrintJobKind::Kitchen->value,
        'status' => PrintJobStatus::Printed->value,
        'confidence' => PrintConfidence::SentOnly->value,
        'printed_reported_at' => now()->subMinutes(5),
    ], $overrides));
}

describe('access', function () {
    it('401s an anonymous caller', function () {
        $this->getJson($this->base)->assertUnauthorized();
    });

    it('404s a shop slug that does not exist', function () {
        $this->actingAs($this->user)->getJson('/api/v1/shops/no-such-shop/print-jobs')->assertNotFound();
    });

    it('lets a cashier READ the ledger — they are the one standing at the machine', function () {
        $cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
        $cashier->assignRole('staff', $this->orgId);

        apiJob();

        $this->actingAs($cashier)->getJson($this->base)->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('tenant isolation', function () {
    it('never lists another shop of the same organization', function () {
        apiJob();
        apiJob([], $this->otherShop->id);

        $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.branch_id'))->toBe($this->shop->id);
    });

    it('never lists another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
        $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
        $foreignBranch = Branch::factory()->create([
            'console_organization_id' => $otherOrgId,
            'console_brand_id' => $otherBrand->console_brand_id,
        ]);

        apiJob([], $foreignBranch->id, $otherOrgId);

        $this->actingAs($this->user)->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
    });

    it('404s show for a job id that belongs to another shop', function () {
        $foreign = apiJob([], $this->otherShop->id);

        $this->actingAs($this->user)->getJson("{$this->base}/{$foreign->id}")->assertNotFound();
    });

    it('404s show for a job id that does not exist', function () {
        $this->actingAs($this->user)->getJson("{$this->base}/".Str::uuid())->assertNotFound();
    });
});

describe('P-33 — confidence is exposed, never collapsed', function () {
    it('distinguishes printed(sent_only) from printed(confirmed)', function () {
        $sent = apiJob(['status' => PrintJobStatus::Printed->value, 'confidence' => PrintConfidence::SentOnly->value]);
        $confirmed = apiJob(['status' => PrintJobStatus::Printed->value, 'confidence' => PrintConfidence::Confirmed->value]);

        $rows = collect($this->actingAs($this->user)->getJson($this->base)->assertOk()->json('data'))
            ->keyBy('id');

        expect($rows[$sent->id]['confidence'])->toBe('sent_only')
            ->and($rows[$sent->id]['confidence_label'])->toBe('printed_sent_only')
            ->and($rows[$confirmed->id]['confidence'])->toBe('confirmed')
            ->and($rows[$confirmed->id]['confidence_label'])->toBe('printed_confirmed');
    });

    it('labels a non-printed row by its status, not by its confidence', function () {
        $job = apiJob([
            'status' => PrintJobStatus::NeedsAttention->value,
            'confidence' => PrintConfidence::SentOnly->value,
        ]);

        $row = $this->actingAs($this->user)->getJson("{$this->base}/{$job->id}")->assertOk()->json('data');

        expect($row['confidence_label'])->toBe('needs_attention');
    });

    it('offers no route that turns sent_only into confirmed', function () {
        $job = apiJob(['status' => PrintJobStatus::Printed->value, 'confidence' => PrintConfidence::SentOnly->value]);

        // There is no confidence write endpoint; the model refuses the
        // promotion even from inside the app (M1 invariant, re-asserted here
        // because M2 added the first Cloud-side writer of print_jobs).
        expect(fn () => $job->update(['transport' => PrintTransport::CloudPrnt->value]))
            ->toThrow(LogicException::class);

        $cloudJob = apiJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Printed->value,
            'confidence' => PrintConfidence::SentOnly->value,
        ]);

        expect(fn () => $cloudJob->update(['confidence' => PrintConfidence::Confirmed->value]))
            ->toThrow(LogicException::class);
    });
});

describe('filters', function () {
    it('filters by a single status', function () {
        $failed = apiJob(['status' => PrintJobStatus::Failed->value]);
        apiJob(['status' => PrintJobStatus::Printed->value]);

        $response = $this->actingAs($this->user)->getJson("{$this->base}?status=failed")->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($failed->id);
    });

    it('filters by several statuses at once', function () {
        apiJob(['status' => PrintJobStatus::Failed->value]);
        apiJob(['status' => PrintJobStatus::NeedsAttention->value]);
        apiJob(['status' => PrintJobStatus::Printed->value]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}?status[]=failed&status[]=needs_attention")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('accepts a comma-separated status list too', function () {
        apiJob(['status' => PrintJobStatus::Failed->value]);
        apiJob(['status' => PrintJobStatus::Printed->value]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}?status=failed,printed")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters by kind', function () {
        $receipt = apiJob(['kind' => PrintJobKind::Receipt->value]);
        apiJob(['kind' => PrintJobKind::Kitchen->value]);

        $response = $this->actingAs($this->user)->getJson("{$this->base}?kind=receipt")->assertOk();

        expect($response->json('data.0.id'))->toBe($receipt->id)
            ->and($response->json('data.0.is_money_document'))->toBeTrue();
    });

    it('filters by transport and by confidence', function () {
        apiJob(['transport' => PrintTransport::WsLan->value, 'confidence' => PrintConfidence::SentOnly->value]);
        $cloud = apiJob(['transport' => PrintTransport::CloudPrnt->value, 'confidence' => PrintConfidence::Confirmed->value]);

        $this->actingAs($this->user)->getJson("{$this->base}?transport=cloudprnt")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cloud->id);

        $this->actingAs($this->user)->getJson("{$this->base}?confidence=confirmed")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cloud->id);
    });

    it('filters by printer', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
        ]);

        $onPrinter = apiJob(['printer_id' => $printer->id]);
        apiJob(['printer_id' => null]);

        $response = $this->actingAs($this->user)->getJson("{$this->base}?printer_id={$printer->id}")->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($onPrinter->id)
            ->and($response->json('data.0.printer_name'))->toBe($printer->name);
    });

    it('filters money documents only', function () {
        apiJob(['kind' => PrintJobKind::Receipt->value]);
        apiJob(['kind' => PrintJobKind::RedInvoice->value]);
        apiJob(['kind' => PrintJobKind::Kitchen->value]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}?money_documents_only=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('combines filters (status AND kind AND transport)', function () {
        $match = apiJob([
            'status' => PrintJobStatus::NeedsAttention->value,
            'kind' => PrintJobKind::Receipt->value,
            'transport' => PrintTransport::WsLan->value,
        ]);
        apiJob(['status' => PrintJobStatus::NeedsAttention->value, 'kind' => PrintJobKind::Kitchen->value]);
        apiJob(['status' => PrintJobStatus::Printed->value, 'kind' => PrintJobKind::Receipt->value]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}?status=needs_attention&kind=receipt&transport=ws_lan")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($match->id);
    });

    it('422s an unknown status instead of quietly returning everything', function () {
        $this->actingAs($this->user)->getJson("{$this->base}?status=exploded")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status.0');
    });

    it('422s a malformed date and a reversed range', function () {
        $this->actingAs($this->user)->getJson("{$this->base}?from=yesterday")
            ->assertUnprocessable()->assertJsonValidationErrors('from');

        $this->actingAs($this->user)->getJson("{$this->base}?from=2026-07-28&to=2026-07-27")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    });
});

describe('#1091 — the date filter is the BRANCH day, not the viewer day', function () {
    it('puts one UTC instant on different business days for shops in different timezones', function () {
        // 2026-07-27 16:30 UTC = the 28th 01:30 in Tokyo, the 27th 23:30 in
        // Hanoi, and the 27th in UTC. Same instant, three business days.
        $instant = CarbonImmutable::parse('2026-07-27T16:30:00Z');

        $branches = [
            'Asia/Tokyo' => '2026-07-28',
            'Asia/Ho_Chi_Minh' => '2026-07-27',
            'UTC' => '2026-07-27',
        ];

        foreach ($branches as $tz => $expectedBusinessDate) {
            $branch = Branch::factory()->create([
                'console_organization_id' => $this->orgId,
                'console_brand_id' => $this->brand->console_brand_id,
                'slug' => 'tz-'.Str::slug($tz),
                'timezone' => $tz,
                'is_active' => true,
            ]);

            apiJob(['printed_reported_at' => $instant], $branch->id);

            $base = "/api/v1/shops/{$branch->slug}/print-jobs";

            $this->actingAs($this->user)
                ->getJson("{$base}?from={$expectedBusinessDate}&to={$expectedBusinessDate}")
                ->assertOk()
                ->assertJsonCount(1, 'data');

            $wrongDay = $expectedBusinessDate === '2026-07-27' ? '2026-07-28' : '2026-07-27';

            $this->actingAs($this->user)
                ->getJson("{$base}?from={$wrongDay}&to={$wrongDay}")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    });

    it('dates a job by the paper, not by the sync (P-07)', function () {
        // Printed the previous evening at the shop, synced this morning.
        $printedAt = CarbonImmutable::parse('2026-07-27T11:00:00Z'); // 20:00 Tokyo, the 27th
        $syncedAt = CarbonImmutable::parse('2026-07-28T01:00:00Z');  // 10:00 Tokyo, the 28th

        apiJob(['printed_reported_at' => $printedAt, 'created_at' => $syncedAt]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}?from=2026-07-27&to=2026-07-27")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->user)
            ->getJson("{$this->base}?from=2026-07-28&to=2026-07-28")
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('includes both edges of an inclusive range', function () {
        apiJob(['printed_reported_at' => CarbonImmutable::parse('2026-07-26T02:00:00Z')]); // 26th JST
        apiJob(['printed_reported_at' => CarbonImmutable::parse('2026-07-28T02:00:00Z')]); // 28th JST
        apiJob(['printed_reported_at' => CarbonImmutable::parse('2026-07-29T02:00:00Z')]); // 29th JST

        $this->actingAs($this->user)
            ->getJson("{$this->base}?from=2026-07-26&to=2026-07-28")
            ->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('pagination and ordering', function () {
    it('paginates, and the last page is short but present', function () {
        for ($i = 0; $i < 7; $i++) {
            apiJob(['printed_reported_at' => now()->subMinutes($i)]);
        }

        $page1 = $this->actingAs($this->user)->getJson("{$this->base}?per_page=3")->assertOk();
        expect($page1->json('data'))->toHaveCount(3)
            ->and($page1->json('meta.total'))->toBe(7)
            ->and($page1->json('meta.last_page'))->toBe(3);

        $page3 = $this->actingAs($this->user)->getJson("{$this->base}?per_page=3&page=3")->assertOk();
        expect($page3->json('data'))->toHaveCount(1);
    });

    it('returns an empty page past the end rather than an error', function () {
        apiJob();

        $response = $this->actingAs($this->user)->getJson("{$this->base}?per_page=3&page=99")->assertOk();

        expect($response->json('data'))->toBe([])
            ->and($response->json('meta.total'))->toBe(1);
    });

    it('caps per_page at 100', function () {
        $this->actingAs($this->user)->getJson("{$this->base}?per_page=5000")->assertUnprocessable();
    });

    it('orders newest event first and breaks ties stably', function () {
        // Three jobs at the SAME instant: without the id tiebreak, MySQL is
        // free to return them in a different order per page, and a row can be
        // skipped or repeated as an operator pages through.
        $sameInstant = now()->subMinutes(5);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = apiJob(['printed_reported_at' => $sameInstant])->id;
        }
        $newest = apiJob(['printed_reported_at' => now()->subMinute()]);

        $first = $this->actingAs($this->user)->getJson("{$this->base}?per_page=2")->json('data.*.id');
        $second = $this->actingAs($this->user)->getJson("{$this->base}?per_page=2&page=2")->json('data.*.id');

        expect($first[0])->toBe($newest->id)
            ->and(array_merge($first, $second))->toHaveCount(4)
            // no row appears on both pages
            ->and(count(array_unique(array_merge($first, $second))))->toBe(4);

        rsort($ids);
        expect(array_slice(array_merge($first, $second), 1))->toBe($ids);
    });
});

describe('detail view', function () {
    it('states who owns the queue and whether another try may happen', function () {
        $wsJob = apiJob([
            'kind' => PrintJobKind::Kitchen->value,
            'status' => PrintJobStatus::Failed->value,
            'attempts' => 1,
        ]);

        $detail = $this->actingAs($this->user)->getJson("{$this->base}/{$wsJob->id}")->assertOk()->json('data');

        expect($detail['delivery']['queue_owner'])->toBe('workstation')
            ->and($detail['delivery']['max_attempts'])->toBe(4)
            ->and($detail['delivery']['auto_retry_allowed'])->toBeTrue()
            ->and($detail['timeline'])->not->toBeEmpty();
    });

    it('says NO auto-retry for a money document, in every state', function () {
        foreach ([PrintJobStatus::Failed, PrintJobStatus::NeedsAttention] as $status) {
            $job = apiJob([
                'kind' => PrintJobKind::Receipt->value,
                'status' => $status->value,
                'attempts' => 0,
            ]);

            $detail = $this->actingAs($this->user)->getJson("{$this->base}/{$job->id}")->assertOk()->json('data');

            expect($detail['delivery']['auto_retry_allowed'])->toBeFalse()
                ->and($detail['delivery']['auto_retry_allowed_for_kind'])->toBeFalse();
        }
    });

    it('carries the aging summary and the silent-printer list in meta', function () {
        apiJob(['status' => PrintJobStatus::NeedsAttention->value]);

        $meta = $this->actingAs($this->user)->getJson($this->base)->assertOk()->json('meta');

        expect($meta)->toHaveKeys(['statuses', 'kinds', 'aging', 'silent_printers'])
            ->and($meta['aging']['needs_attention'])->toBe(1);
    });
});

describe('per-order / per-payer filters (#1875)', function () {
    it('narrows to one order, so "was a red invoice printed for this order" is answerable', function () {
        $orderId = (string) Str::uuid();
        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => $orderId, 'reprint_no' => 1]);
        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => $orderId, 'reprint_no' => 2]);
        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => (string) Str::uuid()]);

        $data = $this->actingAs($this->user)
            ->getJson("{$this->base}?order_id={$orderId}&kind=red_invoice")
            ->assertOk()->json('data');

        expect($data)->toHaveCount(2)
            ->and(collect($data)->pluck('order_id')->unique()->all())->toBe([$orderId]);
    });

    it('narrows to ONE payer of a split bill', function () {
        $orderId = (string) Str::uuid();
        $guestA = (string) Str::uuid();
        $guestB = (string) Str::uuid();

        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => $orderId, 'payment_id' => $guestA]);
        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => $orderId, 'payment_id' => $guestA]);
        apiJob(['kind' => PrintJobKind::RedInvoice->value, 'order_id' => $orderId, 'payment_id' => $guestB]);

        $data = $this->actingAs($this->user)
            ->getJson("{$this->base}?payment_id={$guestA}")
            ->assertOk()->json('data');

        expect($data)->toHaveCount(2)
            ->and(collect($data)->pluck('payment_id')->unique()->all())->toBe([$guestA]);
    });

    // The filter narrows WITHIN the shop scope; it must never become a way to
    // reach across it. Naming another shop's order returns nothing, not that
    // shop's rows.
    it('cannot reach an order belonging to another shop', function () {
        $foreignOrder = (string) Str::uuid();
        apiJob(['order_id' => $foreignOrder], $this->otherShop->id);

        $this->actingAs($this->user)
            ->getJson("{$this->base}?order_id={$foreignOrder}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('rejects an over-long id instead of scanning on it', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}?order_id=".str_repeat('x', 37))
            ->assertStatus(422);
    });
});
