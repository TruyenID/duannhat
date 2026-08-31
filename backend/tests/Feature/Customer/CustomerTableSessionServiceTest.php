<?php

use App\Exceptions\TableNotFoundException;
use App\Exceptions\TableStateConflictException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\Customer\CustomerTableSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — the customer-web table state machine
 * (occupy / join / release) moved from CustomerTableController into
 * CustomerTableSessionService. The HTTP surface stays covered by
 * CustomerTableTest + TableSessionJoinTest; these hit the service directly.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->service = app(CustomerTableSessionService::class);

    // Create a free table, then plant the requested status via a RAW update so a
    // non-enum value like `paid` (TableStatusEnum has no such case) doesn't throw
    // ValueError on model hydrate — the same trick the HTTP join tests use.
    $this->table = function (string $status = 'free'): Table {
        $table = Table::factory()->create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'status' => 'free',
            'is_active' => true,
            'qr_token' => (string) Str::uuid(),
        ]);
        if ($status !== 'free') {
            DB::table('tables')->where('id', $table->id)->update(['status' => $status]);
        }

        return $table;
    };
});

// ─── occupy ──────────────────────────────────────────────────────────────────

it('occupy flips a free table to occupied and echoes it', function () {
    $table = ($this->table)('free');

    $data = $this->service->occupy($table->qr_token);

    expect($data['table']['status'])->toBe('occupied')
        ->and($data['table']['qr_token'])->toBe($table->qr_token)
        ->and($table->fresh()->getRawOriginal('status'))->toBe('occupied');
});

it('occupy is idempotent on an already-occupied table', function () {
    $table = ($this->table)('occupied');

    expect($this->service->occupy($table->qr_token)['table']['status'])->toBe('occupied');
});

it('occupy throws a 409 conflict for a non-free/occupied table', function () {
    $table = ($this->table)('cleaning');

    try {
        $this->service->occupy($table->qr_token);
        $this->fail('Expected a TableStateConflictException');
    } catch (TableStateConflictException $e) {
        expect($e->httpStatus)->toBe(409)
            ->and($e->tableStatus)->toBe('cleaning')
            ->and($e->render()->getStatusCode())->toBe(409);
    }
});

it('occupy throws TableNotFoundException for an unknown token', function () {
    expect(fn () => $this->service->occupy('no-such-token'))->toThrow(TableNotFoundException::class);
});

// ─── join ────────────────────────────────────────────────────────────────────

it('join opens a fresh session on a free table', function () {
    $table = ($this->table)('free');

    $data = $this->service->join($table->qr_token, forceNew: false);

    expect($data['status'])->toBe('joined')
        ->and($data['session']['id'])->not->toBeNull()
        ->and($data['order'])->toBeNull()
        ->and($table->fresh()->getRawOriginal('status'))->toBe('occupied')
        ->and(TableSession::where('table_id', $table->id)->where('status', TableSession::STATUS_OPEN)->count())->toBe(1);
});

it('join returns the SAME session for a second device on an occupied table', function () {
    $table = ($this->table)('free');
    $first = $this->service->join($table->qr_token, false);
    $second = $this->service->join($table->qr_token, false);

    expect($second['session']['id'])->toBe($first['session']['id'])
        ->and(TableSession::where('table_id', $table->id)->count())->toBe(1);
});

it('join on a paid table without force_new returns paid_recent', function () {
    $table = ($this->table)('paid');

    $data = $this->service->join($table->qr_token, forceNew: false);

    expect($data['status'])->toBe('paid_recent')
        ->and($data['can_start_new_session'])->toBeTrue()
        // The table stays paid — no session opened.
        ->and($table->fresh()->getRawOriginal('status'))->toBe('paid');
});

it('join on a paid table with force_new opens a new session', function () {
    $table = ($this->table)('paid');

    $data = $this->service->join($table->qr_token, forceNew: true);

    expect($data['status'])->toBe('joined')
        ->and($table->fresh()->getRawOriginal('status'))->toBe('occupied')
        ->and(TableSession::where('table_id', $table->id)->where('status', TableSession::STATUS_OPEN)->count())->toBe(1);
});

it('join throws a 423 Locked for a blocked table (cleaning/reserved/out_of_service)', function () {
    $table = ($this->table)('reserved');

    try {
        $this->service->join($table->qr_token, false);
        $this->fail('Expected a TableStateConflictException');
    } catch (TableStateConflictException $e) {
        expect($e->httpStatus)->toBe(423)
            ->and($e->tableStatus)->toBe('reserved')
            ->and($e->render()->getStatusCode())->toBe(423);
    }
});

// ─── release ─────────────────────────────────────────────────────────────────

it('release flips an occupied table back to free', function () {
    $table = ($this->table)('occupied');

    $data = $this->service->release($table->qr_token);

    expect($data['table']['status'])->toBe('free')
        ->and($table->fresh()->getRawOriginal('status'))->toBe('free');
});

it('release is idempotent on an already-free table', function () {
    $table = ($this->table)('free');

    expect($this->service->release($table->qr_token)['table']['status'])->toBe('free');
});

it('release throws a 409 conflict for a paid table', function () {
    $table = ($this->table)('paid');

    try {
        $this->service->release($table->qr_token);
        $this->fail('Expected a TableStateConflictException');
    } catch (TableStateConflictException $e) {
        expect($e->httpStatus)->toBe(409)
            ->and($e->getMessage())->toBe('Table cannot be released.');
    }
});
