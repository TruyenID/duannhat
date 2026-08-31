<?php

declare(strict_types=1);

/**
 * #2188 — LEGACY KHÔNG TỒN TẠI (ruling chủ dự án 2026-08-08):
 *
 * > "Không có cái gì gọi là legacy. Mọi thứ chưa được release nên không quan
 * > tâm đến legacy. Legacy không tồn tại và cấm tuyệt đối."
 *
 * Rào này cấm ĐỊNH DANH mang nghĩa legacy trong code mới dưới `app/`: biến,
 * hàm, lớp, alias… khớp /legacy/i trong phần MÃ của file. Comment/docblock và
 * string literal được loại trước khi quét (cùng lý do #1921/taxModeFlagCodeOnly:
 * removal record — "ĐÃ GỠ #1779" — và log text kể chuyện cũ không phải là nhánh
 * legacy; một cái TÊN trong code thì là).
 *
 * Allowlist dưới đây là ảnh chụp NHỮNG GÌ CÒN LẠI sau đợt dọn #2188 đợt 4
 * (BUG-8 lazy re-stamp, $legacyRate + fallback subtotal, 17 lệnh Backfill*,
 * alias till_name/Type) — mỗi entry kèm lý do nó còn sống. Danh sách CHỈ ĐƯỢC
 * TEO: dọn xong một file thì gỡ entry (test dưới cưỡng chế cả chiều đó). Thêm
 * entry mới là vi phạm ruling — không phải thủ tục xin cho.
 */
const LEGACY_IDENTIFIER_PATTERN = '/legacy/i';

/**
 * file => lý do định danh legacy còn tồn tại (nợ #2188, shrink-only).
 */
const LEGACY_IDENTIFIER_ALLOWED = [
    // ── Cụm payment-orchestration compat (đợt dọn riêng — lớn nhất còn lại) ──
    'app/Services/Payment/Observation/LegacyRemovalReadiness.php' => 'chính là instrument đo readiness để GỠ legacy payment — chết cùng đợt payment',
    'app/Console/Commands/PaymentLegacyRemovalReadiness.php' => 'CLI của instrument trên',
    'app/Services/Payment/Orchestration/OrderPaymentOrchestrationCompat.php' => 'compat funnel legacy-vs-orchestrator (finalizeLegacyConfirm/Fail) — đợt payment',
    'app/Services/Payment/Orchestration/Support/PaymentOrchestrationMetrics.php' => 'metric đếm đường legacy của compat funnel trên',
    'app/Services/Payment/ProviderEvent/LegacyGlobalStripeConnection.php' => 'kết nối Stripe global tiền-multi-gateway, còn là fallback sống — đợt payment',
    'app/Services/Payment/ProviderEvent/LegacyStripeWebhookBridge.php' => 'cầu webhook cũ sang intake mới — đợt payment',
    'app/Services/Payment/ProviderEvent/ProviderEventIntakeService.php' => 'intakeLegacyStripeWebhook — miệng nhận của cầu trên',
    'app/Services/Payment/ProviderEvent/ProviderEventApplicator.php' => 'áp provider event lên cả đường legacy lẫn orchestrator — đợt payment',
    'app/Services/Payment/ProviderEvent/WebhookConnectionResolver.php' => 'resolver còn nhánh fallback về global connection cũ — đợt payment',
    'app/Services/Customer/StripePaymentService.php' => 'legacyConnection() — fallback về LegacyGlobalStripeConnection — đợt payment',
    'app/Services/Payment/Gateway/Stripe/StripeConnectScope.php' => 'phân biệt scope kết nối legacy global vs per-brand — đợt payment',
    'app/Services/Payment/Gateway/Stripe/StripePaymentGateway.php' => 'nhánh charge qua kết nối legacy global — đợt payment',
    'app/Services/Payment/Terminal/StripeTerminalService.php' => 'đọc kết nối legacy global cho Terminal — đợt payment',
    'app/Services/Customer/OrderPaymentService.php' => 'gọi finalizeLegacyConfirm/Fail của compat funnel — đợt payment',
    'app/Http/Controllers/Api/V1/Customer/StripeWebhookController.php' => 'route vào intakeLegacyStripeWebhook — đợt payment',
    'app/Services/Payment/Policy/Admin/PosEffectivePaymentOptionEnricher.php' => 'enricher còn song song shape legacy/orchestrator cho pos-web — đợt payment',

    // ĐÃ GỠ #2410: PaymentOrchestrationShadowComparator. #2505 xoá lệnh
    // `payments:shadow-compare` — người gọi duy nhất — nên lớp so bóng chỉ còn
    // là mã chết; nó đi trước phần còn lại của cụm payment, không chờ cutover.

    // ĐÃ GỠ #2413: hai entry cuối (AuditRolloutCommand + LegacyRecipientResolver).
    // Đo trên production 30 ngày: stock_alert 0 dòng lệch; customer_order 217 dòng
    // lệch GIỐNG HỆT nhau, nguyên nhân duy nhất là resolver cũ dội cho admin của
    // một chi nhánh có 0 đơn. Engine là bên đúng ⇒ instrument diff hết việc.
];

/**
 * Chỉ giữ phần MÃ: bỏ comment, docblock VÀ string literal — định danh mới là
 * đối tượng của lệnh cấm; chữ trong string/comment (log text, removal record)
 * thì không.
 */
function legacyIdentifierCodeOnly(string $contents): string
{
    $out = '';

    foreach (@token_get_all($contents) as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $out .= $token[1];
    }

    return $out;
}

it('#2188 — bans legacy-named identifiers in app/ outside the shrink-only debt list', function () {
    $root = base_path('app');

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

        // Generated code — regen would fight a hand-edit; the editable classes
        // are the enforcement surface.
        if (str_starts_with($relative, 'app/Omnify/')) {
            continue;
        }

        $count = preg_match_all(
            LEGACY_IDENTIFIER_PATTERN,
            legacyIdentifierCodeOnly((string) file_get_contents($file->getPathname())),
        );
        if ($count > 0) {
            $violations[$relative] = $count;
        }
    }

    $offenders = [];
    foreach ($violations as $file => $count) {
        if (! array_key_exists($file, LEGACY_IDENTIFIER_ALLOWED)) {
            $offenders[] = "{$file}: {$count} legacy identifier reference(s)";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Định danh mang nghĩa legacy trong code mới. Ruling #2188: legacy không tồn',
        'tại — không nhánh "?? fallback" cho dữ liệu cũ, không lazy re-stamp, không',
        'lệnh backfill, không alias tên cũ. Xoá nhánh/đổi tên thay vì allowlist;',
        'LEGACY_IDENTIFIER_ALLOWED chỉ ghi NỢ CŨ và chỉ được teo.',
        ...$offenders,
    ]));

    // Shrink-only: entry mà file đã sạch (hoặc đã xoá) thì phải gỡ đi, để danh
    // sách luôn là ảnh thật của số nợ còn lại.
    $stale = [];
    foreach (array_keys(LEGACY_IDENTIFIER_ALLOWED) as $file) {
        if (! array_key_exists($file, $violations)) {
            $stale[] = "{$file}: hết định danh legacy (hoặc file đã xoá) — gỡ entry";
        }
    }

    expect($stale)->toBe([], "Entry allowlist thừa (danh sách chỉ được teo):\n".implode("\n", $stale));
});

it('#2188 — bans resurrecting the Backfill command family', function () {
    // 17 lệnh Backfill* đã bị xoá cùng đợt này (backfill = nhánh chữa dữ liệu
    // cũ lúc vận hành — đúng thứ ruling cấm; sửa dữ liệu dev = reseed). Một
    // lệnh console mới tên Backfill* là dựng lại cả hạng mục.
    $offenders = glob(base_path('app/Console/Commands/Backfill*.php')) ?: [];

    expect($offenders)->toBe([], "Lệnh Backfill* mới xuất hiện (ruling #2188: reseed, không backfill):\n"
        .implode("\n", array_map(fn ($f) => ltrim(str_replace(base_path(), '', $f), '/'), $offenders)));
});
