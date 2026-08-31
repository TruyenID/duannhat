<?php

namespace App\Services\Payment\Observation;

use App\Models\Branch;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Orchestration\Internal\CustomerWebStripeConnectionResolver;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use Throwable;

/**
 * Plan 047 T7.6 — "can we delete the legacy payment shims yet?"
 *
 * T7.6 is the only task `plans/plan-047/TASKS.md` still leaves unticked, and it
 * stalled for weeks not because the deletion is hard but because the CONDITIONS
 * were tribal knowledge: answering the question meant grepping four classes,
 * reading two docblocks, checking a config constant and querying the ledger —
 * from scratch, every time.
 *
 * The cost of that is measurable: the condition table inside `TASKS.md` went
 * stale (it listed `LegacyStripeOrchestrationBootstrap` as live long after the
 * class was deleted) and nobody noticed, because nothing re-derived it.
 *
 * Each gate answers two independent questions, and BOTH matter:
 *
 *   - `condition_met`  — is the external precondition satisfied?
 *   - `code_present`   — is the legacy code still in the tree?
 *
 * A gate that is met while the code is still present is the actionable state:
 * it is now safe to delete, and nobody knows. That combination is what
 * `--strict` fails on, so the day a condition finally flips, a scheduled run
 * says so instead of the debt sitting for another year.
 */
final class LegacyRemovalReadiness
{
    public function __construct(
        private readonly CustomerWebStripeConnectionResolver $stripeResolver,
        private readonly PaymentPolicyEvaluationService $policy,
        private readonly OrderPaymentOrchestrationCompat $transportCompat,
    ) {}

    /** @var array<string, int> branch id => payments whose channel could not be resolved */
    private array $unresolvedChannelPayments = [];

    /**
     * @return array{
     *     generated_at: string,
     *     actionable: bool,
     *     gates: list<array<string, mixed>>,
     * }
     */
    public function report(?int $branchLimit = null, int $sinceDays = self::DEFAULT_ROLLOUT_WINDOW_DAYS): array
    {
        // Reset per report: two calls on one instance would otherwise accumulate
        // stale branch entries, and a branch fixed between runs would still show
        // the ⚠. Harmless today — every call site resolves a fresh instance —
        // but it is one line, and this is exactly the latent state that bites
        // the day someone adds ->singleton() or runs it under Octane.
        $this->unresolvedChannelPayments = [];

        $gates = [
            $this->paymentStatusCompatibilityGate(),
            $this->legacyGlobalStripeConnectionGate($branchLimit),
            $this->legacyPaymentMethodResolverGate($sinceDays),
            $this->legacyPaymentMethodCodePathGate(),
            $this->legacyAliasRelianceGate(),
        ];

        $actionable = array_reduce(
            $gates,
            static fn (bool $carry, array $gate): bool => $carry || ($gate['condition_met'] && $gate['code_present']),
            false,
        );

        // Đếm riêng VIỆC và HẸN GIỜ. Câu hỏi người vận hành thật sự hỏi là "còn
        // phải LÀM gì không", và trước đây bảng không trả lời được: một dòng
        // `blocked` vì lịch trông y hệt một dòng `blocked` vì chưa ai làm.
        $work = array_values(array_filter($gates, static fn (array $g): bool => ($g['kind'] ?? self::KIND_WORK) === self::KIND_WORK));
        $workRemaining = array_values(array_filter($work, static fn (array $g): bool => $g['code_present'] && ! $g['condition_met']));
        $scheduled = array_values(array_filter($gates, static fn (array $g): bool => ($g['kind'] ?? self::KIND_WORK) === self::KIND_SCHEDULED));

        return [
            'generated_at' => now()->toIso8601String(),
            'actionable' => $actionable,
            // `work_remaining` là con số để trả lời "còn phải LÀM gì không".
            // Về 0 nghĩa là plan-047 T7.6 đóng được, kể cả khi `scheduled_pending`
            // vẫn > 0 — cái sau đợi lịch, không đợi người (#1895).
            'work_remaining' => array_map(static fn (array $g): string => $g['key'], $workRemaining),
            'scheduled_pending' => array_map(
                static fn (array $g): string => $g['key'],
                array_values(array_filter($scheduled, static fn (array $g): bool => ! $g['condition_met'])),
            ),
            'gates' => $gates,
        ];
    }

    /**
     * ĐÃ XOÁ (#1822) — cổng này giờ là RATCHET, không còn là danh sách chờ.
     *
     * `App\Support\PaymentStatusCompatibility` không còn tồn tại. Chủ repo xác
     * nhận 2026-08-05 rằng chưa có bản phát hành nào, nên vế "fleet" — thứ
     * không đo được từ Cloud và từng là lý do duy nhất giữ shim lại — không có
     * gì để bảo vệ.
     *
     * Giữ cổng thay vì gỡ, vì nó đổi vai: `code_present` giờ trả `false`, và
     * nếu ai đó thêm lại class thì cổng sáng đèn ngay. Một cổng báo "đã xoá" là
     * bằng chứng sống cho việc xoá; gỡ nó đi thì lần sau không ai biết chỗ này
     * từng có gì.
     *
     * Vế sổ cái vẫn đo thật (`status = 'confirmed'`), vì nó rẻ và vì một hàng
     * `confirmed` xuất hiện lại sau khi shim biến mất là dấu hiệu có đường ghi
     * nào đó chưa được chuẩn hoá — đúng thứ đáng biết.
     *
     * @return array<string, mixed>
     */
    private function paymentStatusCompatibilityGate(): array
    {
        $live = OrderPayment::query()
            ->where('status', self::LEGACY_CONFIRMED)
            ->count();

        // Soft-deleted rows are reported separately: they no longer feed any
        // projection, so they do not block removal, but a non-zero count is
        // evidence the status was in real use and the fleet check still matters.
        $trashed = OrderPayment::query()
            ->withTrashed()
            ->where('status', self::LEGACY_CONFIRMED)
            ->count() - $live;

        return [
            'key' => 'payment_status_compatibility',
            'kind' => self::KIND_WORK,
            'target' => 'App\\Support\\PaymentStatusCompatibility',
            'condition' => "App\\Support\\PaymentStatusCompatibility is deleted AND order_payments.status = '".self::LEGACY_CONFIRMED."' stays zero",
            'measurement' => sprintf(
                'shim đã xoá (#1822); %d hàng %s còn sống (%d soft-deleted)',
                $live, self::LEGACY_CONFIRMED, $trashed,
            ),
            'ledger_condition_met' => $live === 0,
            'condition_met' => $live === 0,
            'code_present' => $this->codePresent('PaymentStatusCompatibility'),
            'call_sites' => $this->callSites('PaymentStatusCompatibility'),
            'note' => $live === 0
                ? 'Shim đã xoá và sổ cái sạch. Cổng giữ lại làm RATCHET: code_present quay lại true nghĩa là ai đó thêm lại lớp tương thích.'
                : 'Shim đã xoá NHƯNG sổ cái vẫn có hàng legacy — nghĩa là còn đường ghi chưa chuẩn hoá. Tìm nó, đừng thêm lại shim.',
        ];
    }

    /**
     * The resolver logs `customer_web_stripe_bootstrap_fallback` every time it
     * has to fall back to the global bootstrap, and its own docblock names the
     * absence of those entries as the removal evidence. Rather than grep logs,
     * this asks the SAME resolver the same question per branch — a branch that
     * resolves a real connection today is a branch that will not emit the
     * fallback tomorrow.
     *
     * @return array<string, mixed>
     */
    private function legacyGlobalStripeConnectionGate(?int $branchLimit): array
    {
        $query = Branch::query()->where('is_active', true)->orderBy('id');

        if ($branchLimit !== null) {
            $query->limit($branchLimit);
        }

        $branches = $query->get();
        $unresolved = [];

        foreach ($branches as $branch) {
            if ($this->stripeResolver->resolveForBranch($branch) === null) {
                $unresolved[] = (string) $branch->id;
            }
        }

        $checked = $branches->count();

        return [
            'key' => 'legacy_global_stripe_connection',
            'kind' => self::KIND_WORK,
            'target' => 'App\\Services\\Payment\\ProviderEvent\\LegacyGlobalStripeConnection',
            'condition' => 'every active branch resolves a real Stripe PaymentGatewayConnection (no bootstrap fallback)',
            'measurement' => sprintf('%d/%d active branches unresolved', count($unresolved), $checked),
            // No branches checked proves nothing — refuse to call that ready.
            'condition_met' => $checked > 0 && $unresolved === [],
            'code_present' => $this->codePresent('LegacyGlobalStripeConnection'),
            'call_sites' => $this->callSites('LegacyGlobalStripeConnection'),
            'unresolved_branch_ids' => array_slice($unresolved, 0, 20),
            'note' => 'Not a plain delete: WebhookConnectionResolver, StripeConnectScope and StripeTerminalService also use this class as the identity of "the global Stripe connection". Removal is a refactor.',
        ];
    }

    /**
     * Đường legacy THẬT: controller nhận `payment_method` là CHUỖI MÃ rồi tra ra
     * `PaymentMethod`, thay vì nhận một effective-option id.
     *
     * Vì sao cần cổng riêng, tách khỏi cổng class (#1887): cổng kia đo sự tồn
     * tại của `LegacyPaymentMethodResolver`. Tôi xoá class đó bằng cách chuyển
     * hai controller sang một phương thức TƯƠNG ĐƯƠNG ở enricher — call site về
     * 0, `code_present` về false, verdict nhảy sang `already removed`. Nhưng
     * kiosk và workstation vẫn gửi y nguyên chuỗi mã cũ và server vẫn tra y như
     * cũ: KHÔNG CÓ cuộc di trú nào. Cổng cũ sẽ báo nợ đã trả trong khi nợ còn
     * nguyên.
     *
     * Nói cách khác cổng cũ đo TÊN, mà nợ nằm ở ĐƯỜNG ĐI. Đổi tên là đủ để đóng
     * nó.
     *
     * ⚠️ CỔNG NÀY CŨNG ĐO TÊN — chỉ là tên gần đường đi hơn. Câu đầu tiên tôi
     * viết ở đây là *"cổng này đo đường đi, nên chỉ đóng được bằng cách làm
     * thật"*, và review vòng hai chứng minh nó SAI bằng phép đo: đổi tên
     * `resolveMethodByCode` thành thứ khác thì `sourceFilesMentioning` trả về
     * rỗng, `method_exists` trả false, verdict nhảy `already removed`, và
     * `--strict` im lặng — đúng lỗi vòng một, thấp hơn một tầng. Tôi vừa mắc
     * lại chính lỗi mà cổng này sinh ra để vá.
     *
     * Thứ THẬT SỰ giữ cổng trung thực là ratchet trong
     * `LegacyRemovalReadinessTest` (#1887): nó ghim `code_present === true` và
     * ghim đúng hai đường dẫn controller, nên đổi tên hay dời lời gọi đều làm
     * test đỏ và bắt người sửa phải khai lại bằng chứng. Ghi rõ ở đây vì một
     * cổng tự nhận bất khả gian lận thì nguy hiểm hơn một cổng thừa nhận giới
     * hạn của mình.
     *
     * @return array<string, mixed>
     */
    private function legacyPaymentMethodCodePathGate(): array
    {
        $method = 'resolveMethodByCode';
        $sites = $this->methodCallSites(PosEffectivePaymentOptionEnricher::class, $method);

        return [
            'key' => 'legacy_payment_method_code_path',
            'kind' => self::KIND_WORK,
            'target' => PosEffectivePaymentOptionEnricher::class.'::'.$method.'()',
            'condition' => 'no caller reaches PosEffectivePaymentOptionEnricher::resolveMethodByCode() — the one remaining lookup of a PaymentMethod by client-supplied code',
            'note' => 'Deleting a CLASS does not migrate a CLIENT: legacy_payment_method_resolver reads "already removed" '
                .'because #1887 folded the duplicate query into the enricher, while kiosk + workstation still POST a legacy '
                .'method code. This gate is the one that stays open until they send an effective-option id instead.',
            'measurement' => sprintf('%d call site(s)', count($sites)),
            'condition_met' => $sites === [],
            'code_present' => method_exists(PosEffectivePaymentOptionEnricher::class, $method),
            'call_sites' => $sites,
            'preconditions' => [],
        ];
    }

    /**
     * #2410 — RATCHET: lớp alias tên trường policy cũ đã xoá, và không ai dựng lại.
     *
     * Cổng này từng đếm hit từ bảng `legacy_field_alias_hits` để trả lời *"còn
     * client nào phụ thuộc tên trường cũ không?"*. Câu hỏi đó **đã đóng** ở
     * #2410 và cả bộ đếm đi cùng lớp alias: writer duy nhất là
     * `LegacyPaymentPolicyFieldAliases::recordAliasHits()`.
     *
     * Giữ lại phép đọc bảng sau đó là giữ một tử số vĩnh viễn bằng 0 vì **không
     * ai ghi** — đúng nguồn thứ ba của số 0 mà chính cổng này viết ra để cảnh
     * báo. Một cổng đọc ra 0 bất kể chuyện gì xảy ra thì không canh gì cả.
     *
     * Bằng chứng lúc đóng (production, 7 ngày tới 2026-08-17): **252** payment
     * qua workstation, **0** lượt alias thắng, mẫu số **3** thiết bị. Mẫu số
     * khác 0 nên số 0 đó là kết quả — không phải phép đo không chạy.
     *
     * Hình dạng còn lại giống hệt `payment_status_compatibility`, cổng đầu tiên
     * đóng theo kiểu này: tín hiệu là `code_present`, và nó quay lại `true`
     * nghĩa là có người dựng lại lớp nhận-cả-hai-tên.
     *
     * @return array<string, mixed>
     */
    private function legacyAliasRelianceGate(): array
    {
        $codePresent = $this->codePresent('LegacyPaymentPolicyFieldAliases');

        return [
            'key' => 'legacy_alias_reliance',
            'kind' => self::KIND_WORK,
            'target' => 'App\\Http\\Support\\LegacyPaymentPolicyFieldAliases',
            'condition' => 'lớp alias tên trường policy cũ đã xoá và không ai dựng lại',
            'measurement' => $codePresent
                ? 'lớp alias ĐÃ QUAY LẠI trong cây — ai đó dựng lại lớp nhận-cả-hai-tên'
                : 'đã xoá (#2410); lúc gỡ: 252 payment workstation / 7 ngày, 0 lượt alias thắng, mẫu số 3 thiết bị',
            'note' => $codePresent
                ? 'Cổng này đã ĐÓNG ở #2410. `code_present` quay lại true nghĩa là lớp alias được dựng '
                    .'lại — đọc lại #2410 trước khi coi đó là bình thường.'
                : 'ĐÃ GỠ #2410. Cổng giữ lại làm RATCHET, cùng khuôn `payment_status_compatibility`. '
                    .'Bộ đếm `legacy_field_alias_hits` đi cùng lớp alias: writer duy nhất là chính nó, nên '
                    .'giữ phép đọc lại sẽ là một tử số vĩnh viễn bằng 0 vì không ai ghi. '
                    .'Khác `legacy_payment_method_code_path`: cổng kia hỏi mã PHƯƠNG THỨC do client gửi, và CHƯA đóng.',
            'condition_met' => ! $codePresent,
            'code_present' => $codePresent,
            'call_sites' => $this->callSites('LegacyPaymentPolicyFieldAliases'),
            'preconditions' => [],
        ];
    }

    /**
     * Phân loại cổng: **việc** hay **hẹn giờ**.
     *
     * Hai loại này khác nhau về bản chất và trộn lại thì bảng nói dối theo hướng
     * ngược với thường lệ — nó báo "còn nợ" trong khi thật ra không còn việc.
     *
     * Cổng `deprecated_payment_methods_routes` từng là ví dụ duy nhất của loại
     * `scheduled`: nó đợi mốc 2027-01-01 đã công bố ra client, và không lượng
     * công việc nào rút ngắn được. Route đó **đã bị xoá sớm theo quyết định của
     * chủ dự án (#1895)**, nên cổng ấy không còn gì để canh và đã gỡ.
     *
     * Phân loại giữ nguyên: cổng sau này đợi LỊCH thì gắn `KIND_SCHEDULED` để
     * không bị đếm là nợ; quên gắn thì mặc định rơi vào `work` — an toàn theo
     * hướng đúng.
     */
    public const KIND_WORK = 'work';

    public const KIND_SCHEDULED = 'scheduled';

    /**
     * #1811 — this gate's note used to read "ordinary work, not waiting on
     * production evidence". That was WRONG, and it was wrong in the direction
     * that invites someone to just do it.
     *
     * Emptying these call sites means kiosk + workstation must resolve through
     * effective options, and two preconditions sit in front of that:
     *
     *   1. Policy enforcement is currently OPT-IN BY THE CLIENT.
     *      `PaymentPolicySubmission::fromPaymentData()` returns null when
     *      `gateway_option_id` is absent, so `OrderPaymentService` skips the
     *      policy check entirely — for POS, kiosk and workstation alike. The
     *      hole is therefore NOT specific to these two transports, and deleting
     *      this class does not close it.
     *   2. Policy revisions do not cover every branch. Enforcing before they do
     *      makes branches without a published revision fail checkout outright
     *      ("No effective payment options are available for checkout"), i.e. it
     *      refuses real money at the counter.
     *
     * On top of both, requiring `gateway_option_id` is a CONTRACT change across
     * three independently released clients (pos-web, godx-kiosk, workstation-app).
     *
     * So the coverage number is reported here on purpose: the precondition has
     * to be visible, or the next reader re-derives it from source the hard way.
     *
     * @return array<string, mixed>
     */
    private function legacyPaymentMethodResolverGate(int $sinceDays): array
    {
        $sites = $this->callSites('LegacyPaymentMethodResolver');

        $coverage = $this->policyRevisionCoverage();
        $activeBranches = $coverage['total'];
        $branchesWithRevision = $coverage['covered'];
        $enforcementRequired = (bool) config('payments.policy_enforcement.required', false);

        return [
            'key' => 'legacy_payment_method_resolver',
            'kind' => self::KIND_WORK,
            'target' => 'App\\Services\\Payment\\LegacyPaymentMethodResolver',
            'condition' => 'no runtime call sites of the CLASS remain (see legacy_payment_method_code_path for whether clients actually migrated)',
            'measurement' => sprintf('%d call site(s)', count($sites)),
            'condition_met' => $sites === [],
            'code_present' => $this->codePresent('LegacyPaymentMethodResolver'),
            'call_sites' => $sites,
            'preconditions' => [
                [
                    'key' => 'policy_revision_coverage',
                    'measurement' => sprintf(
                        '%d/%d active branches ready (%d have a revision, %d of those have 0 effective option)%s',
                        $coverage['ready'],
                        $activeBranches,
                        $branchesWithRevision,
                        $coverage['revision_but_no_option'],
                        $coverage['per_org'] === '' ? '' : ' ['.$coverage['per_org'].']',
                    ),
                    // plan-055 T2.3 (#1838) — "has a revision" is NOT the exit
                    // condition, and reporting it as one is worse than reporting
                    // nothing.
                    //
                    // Measured on dev while writing the backfill command: all 8
                    // uncovered branches would have ZERO effective options.
                    // Publishing a revision for them takes this number to 9/9 —
                    // this precondition flips to `met` — and every one of them
                    // STILL dies at the flip on
                    // `No effective payment options are available for checkout`.
                    // The gate would read green while the counter refuses money.
                    //
                    // That is the same shape as the `omnify diff` false guard in
                    // CLAUDE.md: a check that answers "yes" to "is this covered?"
                    // without covering it. So both halves are required here.
                    'met' => $activeBranches > 0 && $coverage['ready'] === $activeBranches,
                    // Where to go and backfill — a total hides which tenant is at zero.
                    'organizations_incomplete' => $coverage['incomplete'],
                    // Two different failures, two different fixes: a missing
                    // revision is a backfill; a revision with no effective option
                    // is NOT, and backfilling it changes nothing. The `reason`
                    // says which — read it before assuming it is per-branch
                    // catalog work. Right after Gate 2 the dominant cause is
                    // expected to be ONE platform-wide denial
                    // (`ownership_source_unavailable`), not N broken branches.
                    'branches_without_effective_option' => $coverage['no_effective_option'],
                    // Evidence gaps, surfaced rather than swallowed: a payment
                    // whose channel cannot be resolved is a channel this gate
                    // may be failing to check. A count is actionable; silence
                    // is not.
                    //
                    // ⚠ SCOPE, so its ABSENCE is not read as evidence: this only
                    // covers branches that ALREADY have a revision, because
                    // `channelsInUse()` runs after the missing-revision guard. So
                    // during Gate 2 — exactly when most branches have no revision
                    // and someone is most likely reading this — an empty list
                    // means "not looked at", not "nothing to find".
                    'payments_with_unresolvable_channel' => $this->unresolvedChannelPayments,
                ],
                $this->clientSendsGatewayOptionIdPrecondition($sinceDays),
                [
                    'key' => 'policy_enforcement_is_mandatory',
                    // plan-055 T7.2 (#1847) — READ the flag, do not assert a
                    // constant.
                    //
                    // This was a hard-coded `false`, so after Gate 6 flipped the
                    // flag the command PRINTED A FALSE STATEMENT to the person
                    // who had just flipped it: "precondition NOT met —
                    // policy_enforcement_is_mandatory: opt-in…".
                    //
                    // Scope, stated precisely because the first version of this
                    // comment overstated it and a reviewer measured otherwise:
                    // `preconditions[].met` feeds NOTHING. The gate's verdict
                    // comes from `condition_met` (call sites empty) and
                    // `code_present`, so the constant never blocked the gate and
                    // never blocked T7.2 — whose exit is the `already removed`
                    // verdict, decided by `code_present` before `condition_met`
                    // is even read.
                    //
                    // So the defect is diagnostic, not gating. Worth fixing
                    // anyway: this is the text an operator reads immediately
                    // before deciding whether to flip a flag that refuses money,
                    // and it told them the opposite of what they had just done.
                    'measurement' => $enforcementRequired
                        ? 'mandatory: payments.policy_enforcement.required is ON'
                        : 'opt-in: payments.policy_enforcement.required is OFF, so a payment that names no gateway option is still accepted',
                    'met' => $enforcementRequired,
                ],
            ],
            'note' => 'NOT ordinary work, but the blockers have moved. The flag exists (#1823) and the rest is production state rather than code — publish a revision AND at least one effective option per branch (Gate 2), read BOTH observation logs empty (Gate 4), then flip (Gate 6). ⚠️ Before flipping, confirm the internal-tender exemption (#1831) is deployed: without it, flipping refuses EVERY CASH PAYMENT, which the suite already encodes as expected behaviour (PolicyOptionEnforcementTest). Requiring gateway_option_id is still a contract change across pos-web, godx-kiosk and workstation-app, and it belongs to POS too, not just these two transports.',
        ];
    }

    /** Rollout progress is a RECENT-traffic question; all-time would be drowned by legacy rows. */
    public const DEFAULT_ROLLOUT_WINDOW_DAYS = 7;

    /**
     * Rebuild the `metadata` shape `resolveTransportFromPayment()` expects from
     * the raw `json_extract` result.
     *
     * MySQL returns the value still quoted (`"kiosk"`), SQLite returns it bare.
     * Normalising here keeps one code path for both instead of branching on the
     * driver.
     *
     * @return array<string, string>|null
     */
    private function metaChannel(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '' || $raw === 'null') {
            return null;
        }

        return ['channel' => trim($raw, '"')];
    }

    /**
     * The channels a flip would actually enforce on for this branch.
     *
     * Derived from `order_payments.channel` — the channels this branch has
     * ACTUALLY taken money on — not from which devices happen to be registered.
     *
     * The device table was the first attempt and it was wrong in both
     * directions: a registered-but-idle kiosk blocked the flip forever, a
     * deleted device row hid kiosks still in the field, and `customer_web` has
     * no device at all so the rule could never reach it — while customer-web is
     * a first-class payment path. It also silently omitted `self_regi`, a real
     * device class AND a real channel, which reintroduced this gate's own
     * "green while refusing" bug on a third transport. (Caught in review.)
     *
     * Observed traffic dissolves all of it: a channel that has never taken a
     * payment here is not checked, so the gate can never become unsatisfiable —
     * and every channel that HAS taken money is covered without an enum to keep
     * in sync. It also answers the question the flip actually asks: what would
     * this flip refuse?
     *
     * POS is unioned in as a floor: it is browser + SSO rather than a paired
     * device, so a cashier can reach it at any branch even with no history.
     *
     * ALL-TIME, deliberately — and the cost is stated rather than discovered.
     * For a gate whose failure mode is refusing money at a counter, false-red is
     * the safe direction, and "idle this week" is not "retired". But HISTORY IS
     * IMMUTABLE: a device row could be deleted to clear a false red, a payment
     * row cannot. A branch that ran a two-week customer-web pilot in 2024 is
     * blocked here forever, with no operator action that clears it — the
     * unsatisfiability this mechanism was chosen to avoid, relocated from
     * configuration into history. If that bites, the escape hatch is a
     * `--channel-since=` option (this class already threads a day window for
     * `clientSendsGatewayOptionIdPrecondition`), NOT a quiet default change.
     *
     * @return list<PaymentChannelEnum>
     */
    private function channelsInUse(Branch $branch): array
    {
        $channels = [PaymentChannelEnum::Pos];

        // Resolved through the canonical helper, NOT read off the column.
        //
        // `channel` is nullable and rows written before it existed carry the
        // value in `metadata.channel` (#1058/#1059); there was never a backfill.
        // A bare `whereNotNull('channel')` therefore drops exactly the OLD rows —
        // and old rows are the whole point of looking all the way back, so the
        // filter would have blinded the gate precisely where it reaches. Measured
        // in review: a kiosk payment with the channel only in metadata flipped
        // this gate back to "ready". `resolveTransportFromPayment()` also carries
        // the `till_session_id ⇒ pos` heuristic that a column read misses.
        //
        // `withTrashed()` on purpose: a soft-deleted payment is still evidence
        // the channel is in use. Without it a voided batch silently stops a
        // channel from being checked.
        // Let the DATABASE collapse first, and carry each combination's payment
        // count with it.
        //
        // `GROUP BY … COUNT(*)` rather than `DISTINCT` + a second counting query:
        // that second query had to re-express "unresolved" in SQL, and it had
        // ALREADY drifted from `resolveTransportFromPayment()` on two shapes —
        // `channel = ''` (SQL `whereNull` misses it, the helper does not) and
        // `metadata = {"channel": null}` (MySQL JSON null is not SQL NULL).
        //
        // Worse than an undercount: if a branch's only unresolvable rows were
        // those shapes, the count returned 0, the `> 0` test failed and the ⚠
        // VANISHED — code that had detected a gap then reported silence. And it
        // was structurally untestable, because SQLite maps JSON null to SQL NULL,
        // so the test went green exactly where production went quiet. (Measured
        // in review.) Grouping removes the duplicated condition instead of
        // patching it: the helper stays the only definition. The output is a set of at most four
        // channels, so hydrating every payment row to compute it was O(payments)
        // for an O(1) answer — measured in review at ~19µs/row, linear: 500
        // branches × 500k payments ≈ 80 minutes. This is the command someone
        // re-runs during a rollout to decide whether to flip a flag that refuses
        // money; slow enough to skip is a real risk to the gate's purpose.
        //
        // The three expressions are low-cardinality, so hundreds of thousands of
        // rows collapse to a handful, and the canonical resolver still decides —
        // it just runs once per DISTINCT combination instead of once per row.
        // `json_extract` on purpose: both MySQL and SQLite support it (the test
        // suite runs SQLite), unlike `JSON_UNQUOTE`, which is MySQL-only.
        $combinations = OrderPayment::withTrashed()
            ->where('branch_id', $branch->id)
            ->selectRaw("channel, json_extract(metadata, '$.channel') as meta_channel, (till_session_id is not null) as has_till, count(*) as payment_count")
            ->groupBy('channel', 'meta_channel', 'has_till')
            ->get();

        $unresolved = 0;

        foreach ($combinations as $combination) {
            // A throwaway READ-ONLY projection: no primary key, so never save(),
            // delete() or touch a relation on it.
            $probe = new OrderPayment;
            $probe->channel = $combination->channel;
            $probe->metadata = $this->metaChannel($combination->meta_channel);
            $probe->till_session_id = $combination->has_till ? 'present' : null;

            $resolved = $this->transportCompat->resolveTransportFromPayment($probe);

            if ($resolved === null) {
                $unresolved += (int) $combination->payment_count;

                continue;
            }

            $channel = PaymentChannelEnum::tryFrom($resolved);

            // `server_to_server` is not a counter transport — machine traffic
            // has no checkout to refuse.
            if ($channel === null
                || $channel === PaymentChannelEnum::ServerToServer
                || in_array($channel, $channels, true)) {
                continue;
            }

            $channels[] = $channel;
        }

        // Never silent: a payment whose channel cannot be resolved is a gap in
        // the evidence this gate reasons from, and the operator can act on a
        // count. Silence cannot be acted on at all.
        if ($unresolved > 0) {
            $this->unresolvedChannelPayments[(string) $branch->id] = $unresolved;
        }

        return $channels;
    }

    /**
     * Why the options were not effective, in a few words.
     *
     * "no effective option" alone is ambiguous between "the catalog has nothing
     * for this branch" and "everything was denied for one shared reason". Those
     * are completely different repairs, and the second is the likely one right
     * after Gate 2 backfills: `BranchManagementProjectionSource` is bound to the
     * unavailable implementation outside tests, so every option is denied with
     * `ownership_source_unavailable` — one platform-wide cause that would
     * otherwise look like N broken branches.
     *
     * @param  array<int, mixed>  $options
     */
    private function denialSummary(array $options): string
    {
        if ($options === []) {
            return ' (no candidate options at all)';
        }

        $reasons = collect($options)
            ->filter(static fn ($option): bool => is_array($option))
            ->map(static fn (array $option): string => (string) ($option['reason'] ?? $option['error_code'] ?? 'unknown'))
            ->unique()
            ->values()
            ->all();

        return ' (all '.count($options).' denied: '.implode(', ', $reasons).')';
    }

    /**
     * plan-055 T1.2 (#1817) — coverage per ORGANIZATION, not just a total.
     *
     * A total hides the only thing that is actionable. One tenant fully covered
     * and another at zero averages into a number nobody can act on: it does not
     * say WHERE to backfill, and it lets a healthy tenant mask a tenant that
     * would refuse every checkout the moment enforcement turns on.
     *
     * ⚠️ `branches.console_organization_id` joins `organizations.console_organization_id`,
     * NOT `organizations.id`. Joining on `id` returns nothing and fails silently —
     * the report would simply lose every org name and nobody would notice.
     *
     * @return array{total: int, covered: int, ready: int, revision_but_no_option: int,
     *     per_org: string, incomplete: list<array<string, mixed>>,
     *     no_effective_option: list<array<string, string>>}
     */
    private function policyRevisionCoverage(): array
    {
        // Full rows, not a two-column projection: the policy evaluator resolves
        // the tenant scope off the branch, and a partially hydrated model makes
        // it throw "Shop tenant scope is invalid" for every branch — which this
        // gate would then have reported as "not ready", uniformly and wrongly.
        // The count is one row per active branch on an ops command; that is
        // cheap next to the per-branch evaluation below.
        $branches = Branch::query()
            ->where('is_active', true)
            ->get();

        if ($branches->isEmpty()) {
            return [
                'total' => 0,
                'covered' => 0,
                'ready' => 0,
                'revision_but_no_option' => 0,
                'per_org' => '',
                'incomplete' => [],
                'no_effective_option' => [],
            ];
        }

        $covered = PaymentPolicyRevision::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->distinct()
            ->pluck('branch_id')
            ->flip();

        // plan-055 T2.3 (#1838) — the second half of the exit condition.
        //
        // Same predicate `PaymentPolicySubmissionValidator` applies at checkout:
        // evaluate the branch and count options whose `effective` is true. A
        // branch with a published revision but zero effective options is NOT
        // ready — it refuses every payment the moment enforcement is on.
        //
        // Evaluated per branch on purpose. Reading the stored snapshot would
        // answer a different question: the snapshot says what WAS effective when
        // it was published, and the flip is judged on what is effective now.
        //
        // PER CHANNEL, not just POS (caught in review). The validator evaluates
        // the SUBMISSION's channel, and `PaymentPolicyResolver` filters on
        // `approvedChannels` while a device may disable an option outright. So a
        // branch effective for POS but not for kiosk reads "ready" while the
        // kiosk dies on the same `throwDisabled` — this issue's own failure mode
        // surviving on a second axis. The channels checked are exactly the ones
        // plan-055 is migrating.
        $noEffectiveOption = [];

        foreach ($branches as $branch) {
            if (! $covered->has((string) $branch->id)) {
                continue; // Missing revision is the other failure, counted below.
            }

            $reason = null;

            foreach ($this->channelsInUse($branch) as $channel) {
                try {
                    $evaluation = $this->policy->effectiveOptions($branch, null, $channel);
                    $effective = collect($evaluation['options'] ?? [])
                        ->filter(static fn ($option): bool => is_array($option) && ($option['effective'] ?? false) === true)
                        ->count();

                    if ($effective === 0) {
                        // Carry WHY. Without it a single platform-wide cause —
                        // e.g. every option denied because the branch-ownership
                        // projection source is unavailable, which is the bound
                        // default outside tests — reads as N independent broken
                        // branches and sends the operator to investigate each.
                        $reason = 'no effective option on channel '.$channel->value
                            .$this->denialSummary($evaluation['options'] ?? []);
                    }
                } catch (Throwable $exception) {
                    // An evaluation that throws is not evidence of readiness:
                    // a branch that cannot be evaluated cannot check out either.
                    // A silent skip would let a broken branch count as fine.
                    $reason = 'evaluation failed on channel '.$channel->value.': '.$exception->getMessage();
                }

                if ($reason !== null) {
                    break; // One dead channel is enough to block the flip.
                }
            }

            if ($reason !== null) {
                $noEffectiveOption[] = [
                    'branch_id' => (string) $branch->id,
                    'organization_id' => (string) ($branch->console_organization_id ?? 'unknown'),
                    'reason' => $reason,
                ];
            }
        }

        $blocked = collect($noEffectiveOption)->pluck('branch_id')->flip();

        $names = Organization::query()
            ->whereIn('console_organization_id', $branches->pluck('console_organization_id')->unique()->filter())
            ->pluck('name', 'console_organization_id');

        $rows = $branches
            ->groupBy(static fn ($branch): string => (string) ($branch->console_organization_id ?? 'unknown'))
            ->map(static function ($group, string $orgId) use ($covered, $blocked, $names): array {
                $withRevision = $group->filter(static fn ($branch): bool => $covered->has((string) $branch->id))->count();
                // Ready = has a revision AND is not on the no-effective-option list.
                $ready = $group->filter(static fn ($branch): bool => $covered->has((string) $branch->id)
                    && ! $blocked->has((string) $branch->id))->count();

                return [
                    'organization' => $names[$orgId] ?? $orgId,
                    'organization_id' => $orgId,
                    'covered' => $withRevision,
                    'ready' => $ready,
                    'total' => $group->count(),
                ];
            })
            // Worst first: the tenant furthest from ready is the one to act on.
            // Sorted on READY, not on covered — otherwise an org whose branches
            // all have revisions but no options sorts as perfect.
            //
            // Ready alone is not enough to order by: once the option half is
            // counted, MANY tenants sit at 0 and tie, which silently destroys
            // the "worst first" property the operator relies on. So the revision
            // ratio breaks the tie — between two tenants that can take no
            // payment, the one that has not even been backfilled is further away.
            ->sortBy(static fn (array $row): array => [
                $row['total'] === 0 ? 1.0 : $row['ready'] / $row['total'],
                $row['total'] === 0 ? 1.0 : $row['covered'] / $row['total'],
                -$row['total'],
            ])
            ->values();

        return [
            'total' => $branches->count(),
            'covered' => $covered->count(),
            'ready' => $rows->sum('ready'),
            'revision_but_no_option' => count($noEffectiveOption),
            'per_org' => $rows
                ->map(static fn (array $row): string => sprintf('%s %d/%d', $row['organization'], $row['ready'], $row['total']))
                ->implode(' · '),
            'incomplete' => $rows
                ->filter(static fn (array $row): bool => $row['ready'] < $row['total'])
                ->values()
                ->all(),
            'no_effective_option' => $noEffectiveOption,
        ];
    }

    private const LEGACY_CONFIRMED = 'confirmed';

    /**
     * plan-055 T1.1 (#1814) — how far the client rollout actually got.
     *
     * Enforcement can only become mandatory once every transport sends
     * `gateway_option_id`, so this is the number gate G2/G3 of plan-055 exits
     * on. Without it, "have the clients rolled out yet?" is answered by feel.
     *
     * Two things are deliberate:
     *
     *   - Read through the MODEL, never `DB::table`. `order_payments`
     *     soft-deletes, and #1801 was exactly this: a raw builder skips the
     *     scope and the number stops agreeing with the canonical projection.
     *   - The `unknown` bucket (channel NULL) is REPORTED, not dropped. On the
     *     dev DB every row has a null channel; hiding that bucket would show a
     *     flattering percentage over a population where no client has sent
     *     anything at all.
     *
     * @return array<string, mixed>
     */
    private function clientSendsGatewayOptionIdPrecondition(int $sinceDays): array
    {
        $since = now()->subDays(max(1, $sinceDays));

        $rows = OrderPayment::query()
            ->where('created_at', '>=', $since)
            // Sale rows only: a refund row and a debt-settlement rider carry no
            // option of their own, so counting them would dilute the signal.
            ->whereNull('refund_of_id')
            ->whereNull('metadata->settles_payment_id')
            ->selectRaw('channel, COUNT(*) as total, SUM(CASE WHEN gateway_option_id IS NULL THEN 1 ELSE 0 END) as missing')
            ->groupBy('channel')
            ->get();

        $total = (int) $rows->sum('total');
        $missing = (int) $rows->sum('missing');

        $perChannel = $rows
            ->sortByDesc(static fn ($row): int => (int) $row->total)
            ->map(static function ($row): string {
                $rowTotal = max(1, (int) $row->total);
                $sent = $rowTotal - (int) $row->missing;

                return sprintf(
                    '%s %d%% (%d)',
                    $row->channel ?? 'unknown',
                    (int) round($sent / $rowTotal * 100),
                    (int) $row->total,
                );
            })
            ->implode(' · ');

        if ($total === 0) {
            // An empty window is not evidence of a finished rollout — same rule
            // as the Stripe gate refusing to call 0/0 branches ready.
            return [
                'key' => 'client_sends_gateway_option_id',
                'measurement' => sprintf('no sale payments in the last %d day(s) — nothing measured', $sinceDays),
                'met' => false,
            ];
        }

        return [
            'key' => 'client_sends_gateway_option_id',
            'measurement' => sprintf(
                '%d%% of %d sale payment(s) in the last %d day(s) carry gateway_option_id [%s]',
                (int) round(($total - $missing) / $total * 100),
                $total,
                $sinceDays,
                $perChannel,
            ),
            'met' => $missing === 0,
        ];
    }

    private function codePresent(string $class): bool
    {
        return $this->sourceFilesMentioning($class) !== [];
    }

    /**
     * Call sites của một PHƯƠNG THỨC, loại trừ chính file khai báo nó.
     *
     * `callSites()` loại file bằng quy ước tên `<Class>.php`; với tên phương
     * thức thì quy ước đó không khớp, nên file khai báo sẽ tự tính là call site
     * và cổng không bao giờ về 0. Ở đây loại theo file của class khai báo.
     *
     * @return list<string>
     */
    private function methodCallSites(string $declaringClass, string $method): array
    {
        $declaringFile = null;
        if (class_exists($declaringClass)) {
            $name = (new \ReflectionClass($declaringClass))->getFileName();
            if ($name !== false) {
                $declaringFile = str_replace(base_path().'/', '', $name);
            }
        }

        return array_values(array_filter(
            $this->sourceFilesMentioning($method),
            static fn (string $path): bool => $path !== $declaringFile,
        ));
    }

    /**
     * Files that USE the class, excluding its own definition — the list that has
     * to reach empty before a delete is mechanical.
     *
     * @return list<string>
     */
    private function callSites(string $class): array
    {
        return array_values(array_filter(
            $this->sourceFilesMentioning($class),
            static fn (string $path): bool => ! str_ends_with($path, '/'.$class.'.php'),
        ));
    }

    /** @return list<string> */
    private function sourceFilesMentioning(string $class): array
    {
        $root = app_path();

        if (! is_dir($root)) {
            return [];
        }

        // This scanner names every legacy class in its own source, so without
        // this it reports ITSELF as a call site for all of them — and the count
        // never reaches zero no matter how much migration work lands.
        $self = (new \ReflectionClass(self::class))->getFileName();

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if ($self !== false && $file->getPathname() === $self) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, $class)) {
                continue;
            }

            // CHỈ tính lần nhắc trong CODE, bỏ comment và docblock (#1822).
            //
            // Trước đây đây là `str_contains` trần, nên một dòng văn xuôi kiểu
            // "tách ra từ PaymentStatusCompatibility khi class đó bị xoá" đủ để
            // cổng báo `code_present = true` — tức việc GHI LẠI một lần xoá lại
            // làm cổng nói rằng chưa xoá.
            //
            // Đó là cùng một lỗi mà cả file này sinh ra để chống: một phép đo
            // trông như bằng chứng nhưng đo nhầm thứ. Và nó tệ theo hướng xấu
            // nhất — cổng kêu sai sẽ bị người ta học cách bỏ qua.
            //
            // `token_get_all` phân biệt được vì PHP đã tách sẵn: comment và
            // docblock là `T_COMMENT` / `T_DOC_COMMENT`, không phải mã.
            if (! self::mentionsInCode($contents, $class)) {
                continue;
            }

            $found[] = str_replace(base_path().'/', '', $file->getPathname());
        }

        sort($found);

        return $found;
    }

    /**
     * Tên class có xuất hiện trong CODE không (bỏ comment/docblock)?
     *
     * Tách riêng để test được: `MeasuresCodeNotProseTest` cho nó một file có
     * đúng một lần nhắc trong docblock và khẳng định kết quả là `false`.
     */
    private static function mentionsInCode(string $contents, string $class): bool
    {
        foreach (@token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            if (str_contains($token[1], $class)) {
                return true;
            }
        }

        return false;
    }
}
