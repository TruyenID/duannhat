<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Denomination;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workstation-flow cashier-shift endpoints — accept workstation-supplied
 * ids + timestamps so the LAN-offline write path on the device can replay
 * later without losing the actual mở-ca / kết-ca clock time.
 *
 * Auth: device.auth:workstation (no SSO). The `opened_by_id` /
 * `performed_by_id` rides in the payload so the audit trail still records
 * the actual cashier.
 *
 * Idempotency: every write uses updateOrCreate on the workstation id, so
 * a queue retry after a flaky network never duplicates. Close/abandon are
 * no-ops when the session already sits in the target terminal status.
 */
class TillController extends Controller
{
    public function __construct(
        private readonly TillSessionService $sessions,
    ) {}

    // ─── Sync DOWN reads ────────────────────────────────────────────────────

    public function current(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $till = $this->sessions->resolveTillForBranch($device->branch_id);

        return response()->json(['data' => [
            'id' => $till->id,
            'branch_id' => $till->branch_id,
            'code' => $till->code,
            'default_currency_code' => $till->default_currency_code,
            'variance_tolerance_amount' => (float) $till->variance_tolerance_amount,
            'current_session_id' => $till->current_session_id,
        ]]);
    }

    /**
     * GET /api/v1/workstation/till-sessions/active
     *
     * Replica feed for the workstation's PullTillSessions. Returns every
     * till session for the device's branch whose status is `open` or
     * `closing` — the rows pos-web's `/pos/till/current` LAN handler
     * needs to render the open-shift card after the cashier flips from
     * Cloud back to LAN mode without losing context.
     *
     * Without this endpoint workstation only sees the
     * `till.current_session_id` pointer (via `current()` above) and
     * never the underlying TillSession row when the shift was opened on
     * Cloud — the LAN-mode `/pos/till/current` handler would then
     * return `open_session: null` and the cashier would be prompted to
     * open a fresh shift on top of the existing one.
     *
     * Scope: branch-level. Returned list is bounded (1 open + at most
     * 1 closing per till) so no pagination.
     */
    public function activeSessions(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        if (! $branchId) {
            return response()->json(['data' => []]);
        }

        $sessions = TillSession::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                TillSessionStatusEnum::Open->value,
                TillSessionStatusEnum::Closing->value,
            ])
            ->orderBy('opened_at', 'desc')
            ->get();

        return response()->json([
            'data' => $sessions->map(fn (TillSession $s) => $this->shape($s))->all(),
        ]);
    }

    public function denominations(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $orgId = $device?->organization_id;

        // Scope to the device's organization OR system-wide rows (org_id
        // NULL — what DenominationSeeder writes for JPY/VND/USD/EUR
        // baseline). Pre-fix, the query had no scope, which both leaked
        // cross-org rows AND made it harder to debug "why aren't my
        // denominations showing up": a workstation paired to org A
        // would still see denominations from orgs B, C, D, masking the
        // real chain of custody.
        $rows = Denomination::query()
            ->where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id');
                if ($orgId) {
                    $q->orWhere('organization_id', $orgId);
                }
            })
            ->orderBy('currency_code')
            ->orderBy('sort_order')
            ->get(['id', 'currency_code', 'value', 'kind', 'label', 'sort_order', 'is_active']);

        return response()->json(['data' => $rows]);
    }

    public function tenderCategories(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $rows = TillTenderCategory::query()
            ->where('organization_id', $device->organization_id)
            ->where('is_active', true)
            ->where(function ($q) use ($device) {
                $q->whereNull('branch_id')->orWhere('branch_id', $device->branch_id);
            })
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'sort_order', 'is_system']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Replica feed for the workstation's PullTenderTypes.
     *
     * Carries EVERY locale of the translatable `name`, not one resolved
     * string. The workstation pulls this once per shop on a background tick
     * with no Accept-Language, so a single resolved name would be whatever
     * `config('app.locale')` happens to be (`en`) for every terminal in the
     * shop at once — which is exactly how the POS payment dialog ended up
     * showing "Credit"/"Transit IC" to a cashier who had picked 日本語. The
     * locale belongs to the READER (each terminal, per request), so the
     * mirror has to hold all three and let the LAN handler pick — the same
     * shape the menu feed already uses (`name_ja`/`name_en`/`name_vi`).
     *
     * `name` stays exactly as it was — a flat string — so a workstation
     * build older than this change keeps working byte-for-byte. Deploy
     * backend before workstation, per the repo-wide rule.
     */
    public function tenderTypes(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $rows = TillTenderType::query()
            ->with('translations')
            ->where('is_active', true)
            ->where('organization_id', $device->organization_id)
            ->where(function ($q) use ($device) {
                $q->whereNull('branch_id')->orWhere('branch_id', $device->branch_id);
            })
            ->orderBy('sort_order')
            ->get();

        $data = $rows->map(function (TillTenderType $tender): array {
            $translations = $this->translationsOf($tender, 'name');

            // The mirror column is NOT NULL and the chip has to stay tappable,
            // so this walks down: the locale-resolved name → ANY stored
            // translation → the key. The middle rung matters for a shop that
            // translated only into a language that is neither the app locale
            // nor its fallback — showing them their own wording beats showing
            // them `rakuten_pay`.
            $fallbackName = $translations === [] ? null : reset($translations);
            $name = match (true) {
                is_string($tender->name) && $tender->name !== '' => $tender->name,
                is_string($fallbackName) && $fallbackName !== '' => $fallbackName,
                default => (string) $tender->tender_key,
            };

            return [
                'id' => $tender->id,
                'tender_key' => $tender->tender_key,
                'name' => $name,
                // Rỗng thì phải phát `{}`, KHÔNG phải `[]` — cùng cái bẫy mà
                // `PosEffectivePaymentOptionEnricher` vá cho `display_name_i18n`,
                // ở feed thứ hai.
                //
                // `sync_pull_pos.go:1740` giải mã trường này vào
                // `map[string]string`, và Go từ chối mảng vào map. Vì cả
                // `resp.Data` là MỘT lượt `Unmarshal`, một hàng không dịch
                // không làm rơi một tender — nó ném bỏ TOÀN BỘ feed, kể cả
                // những hàng dịch đầy đủ. `PullTenderTypes` trả lỗi trước khi
                // vào `p.atomic(...)`, nên `till_tender_types` của máy trạm
                // không bao giờ được nạp: quầy không có phím tender nào để bấm.
                //
                // Ca rỗng là ca MẶC ĐỊNH, và dòng `$fallbackName` ngay trên đã
                // lường trước nó — chỉ là chưa ai lường ở tầng JSON.
                // `TillTenderTypeSeeder` `use WithoutModelEvents`, mà Astrotomic
                // bền hoá bản dịch trong hook `saved`, nên mọi `name:ja/en/vi`
                // của seeder bị vứt lặng lẽ.
                //
                // Ép kiểu CHỈ khi rỗng: mảng có khoá chuỗi vốn đã encode ra
                // `{}`, và giữ nguyên kiểu mảng cho ca đó để `reset()` ở trên
                // cùng mọi chỗ đọc bằng `['ja']` không đổi.
                'name_i18n' => $translations === [] ? (object) [] : $translations,
                'category' => $tender->category instanceof \BackedEnum
                    ? $tender->category->value
                    : (string) $tender->category,
                'parent_tender_key' => $tender->parent_tender_key,
                'currency_code' => $tender->currency_code,
                'payment_method_code' => $tender->payment_method_code,
                'is_expected_anchor' => (bool) $tender->is_expected_anchor,
                'requires_terminal_total' => (bool) $tender->requires_terminal_total,
                'sort_order' => (int) ($tender->sort_order ?? 0),
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * Every stored translation of a translatable attribute, keyed by locale.
     *
     * Reads the eager-loaded `translations` relation rather than
     * `translate($locale)` per locale, which would fire one query per row per
     * language. Locales with no row are omitted (NOT filled from a fallback):
     * the consumer must be able to tell "this shop never translated it" from
     * "this shop translated it to the same string", because only the first
     * should fall back to the base column.
     *
     * @return array<string, string>
     */
    private function translationsOf(TillTenderType $model, string $attribute): array
    {
        $out = [];
        foreach ($model->translations as $translation) {
            $value = $translation->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                $out[(string) $translation->locale] = $value;
            }
        }

        return $out;
    }

    // ─── Sync UP writes ─────────────────────────────────────────────────────

    public function openSession(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'session_code' => ['required', 'string', 'max:50'],
            'till_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'currency_code' => ['required', 'string', 'size:3'],
            // #817: opening float is now DERIVED from opening_counts server-side
            // (see below). Kept nullable for payload backwards-compat; ignored.
            'opening_float_amount' => ['nullable', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:2000'],
            'opened_by_id' => ['nullable', 'uuid'],
            'opener_name' => ['nullable', 'string', 'max:255'],
            'opened_at' => ['required', 'date'],
            'opening_counts' => ['required', 'array'],
            'opening_counts.*.denomination_id' => ['required', 'uuid', 'exists:denominations,id'],
            'opening_counts.*.quantity' => ['required', 'integer', 'min:0'],
            // Plan-046 — chain grouping is workstation-authoritative (the device
            // decided continue-vs-new-chain against its local terminal history).
            'chain_id' => ['nullable', 'uuid'],
            'chain_sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        [$session, $created] = $this->sessions->openFromWorkstation($device->branch_id, $data);

        return response()->json(['data' => $this->shape($session)], $created ? 201 : 200);
    }

    public function cashEvent(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureDeviceMatch($request, $session);

        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:paid_in,paid_out,loan_from_safe,pickup_to_safe'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'performed_by_id' => ['nullable', 'uuid'],
            'occurred_at' => ['required', 'date'],
        ]);

        // The device-supplied id (idempotency key of the sync queue) and the
        // timezone normalisation of the device clock both live in the service —
        // see recordCashEventFromWorkstation for why each is load-bearing.
        [$event, $created] = $this->sessions->recordCashEventFromWorkstation($session, $data);

        return response()->json(['data' => $event], $created ? 201 : 200);
    }

    public function close(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureDeviceMatch($request, $session);

        $data = $request->validate([
            'closed_at' => ['required', 'date'],
            'closing_counts' => ['nullable', 'array'],
            'closing_counts.*.denomination_id' => ['required_with:closing_counts.*', 'uuid', 'exists:denominations,id'],
            'closing_counts.*.quantity' => ['required_with:closing_counts.*', 'integer', 'min:0'],
            'tender_details' => ['nullable', 'array'],
            'tender_details.*.tender_key' => ['required_with:tender_details.*', 'string', 'max:50'],
            'tender_details.*.gross_amount' => ['nullable', 'numeric'],
            'tender_details.*.cancel_amount' => ['nullable', 'numeric'],
            'tender_details.*.terminal_batch_total' => ['nullable', 'numeric'],
            'tender_details.*.variance_reason' => ['nullable', 'string', 'max:1000'],
            'closing_note' => ['nullable', 'string', 'max:2000'],
            // Device-owned physical drawer count (Cloud can't count a drawer).
            'counted_cash' => ['required', 'numeric'],
            // #817: cash_variance is now IGNORED — Cloud computes variance from
            // reconcile(). Kept nullable so older payloads still validate.
            'cash_variance' => ['nullable', 'numeric'],
            // Cashier user id (nullable). Never the device id — the Z-report
            // reads closedBy->name.
            'closed_by_id' => ['nullable', 'uuid'],
            // Plan-046 — the workstation tells Cloud whether this settle was a
            // HANDOVER (keep the chain open) or a FINAL close. WITHOUT these
            // rules Laravel validate() strips them, so a handover would be stored
            // as a plain final settle and the chain would break on Cloud (P5-7).
            // The chain-report handover op (P7-C) POSTs to THIS /close route with
            // settlement_kind=handover. chain_id/chain_sequence are stored verbatim
            // (workstation-authoritative grouping).
            'settlement_kind' => ['nullable', 'string', 'in:handover,final'],
            'chain_id' => ['nullable', 'uuid'],
            'chain_sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        // Issue #817 — Cloud-authoritative settle. The workstation used to ship
        // counted_cash + cash_variance and Cloud fabricated
        // expected = counted − variance, so a declared "variance = 0" hid any
        // shortage. settleFromWorkstation() runs reconcile() against payments
        // synced with till_session_id, computes variance itself, and applies the
        // idempotent-replay / till-pointer guards. See the service docblock for
        // why hard variance-reason enforcement is deferred to Phase B.
        $session = $this->sessions->settleFromWorkstation($session, $data);

        return response()->json(['data' => $this->shape($session)]);
    }

    public function abandon(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureDeviceMatch($request, $session);

        $data = $request->validate([
            'abandon_reason' => ['nullable', 'string', 'max:2000'],
            'closed_at' => ['required', 'date'],
        ]);

        $session = $this->sessions->abandonFromWorkstation($session, $data);

        return response()->json(['data' => $this->shape($session)]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function ensureDeviceMatch(Request $request, TillSession $session): void
    {
        $device = $request->attributes->get('device');
        if ($session->branch_id !== $device->branch_id
            || $session->organization_id !== $device->organization_id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(TillSession $s): array
    {
        return [
            'id' => $s->id,
            'session_code' => $s->session_code,
            'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
            'business_date' => optional($s->business_date)?->toDateString(),
            'default_currency_code' => $s->default_currency_code,
            'opening_float_amount' => (float) $s->opening_float_amount,
            'opening_note' => $s->opening_note,
            'opened_by_id' => $s->opened_by_id,
            'opener_name' => $s->opener_name,
            'opened_at' => optional($s->opened_at)?->toIso8601String(),
            'closed_at' => optional($s->closed_at)?->toIso8601String(),
            'closing_note' => $s->closing_note,
            'abandon_reason' => $s->abandon_reason,
            'till_id' => $s->till_id,
            'branch_id' => $s->branch_id,
            // Plan-046 (G3) — chain grouping + the authoritative settlement_snapshot
            // ride INSIDE `data` here so the workstation adopts them from the
            // sync-UP response (R7 adopt-if-present). settlement_snapshot casts to an
            // array, so it serializes as a JSON object (not a string).
            'chain_id' => $s->chain_id,
            'chain_sequence' => $s->chain_sequence !== null ? (int) $s->chain_sequence : null,
            'settlement_kind' => $s->settlement_kind instanceof \BackedEnum ? $s->settlement_kind->value : $s->settlement_kind,
            'settlement_snapshot' => $s->settlement_snapshot,
        ];
    }
}
