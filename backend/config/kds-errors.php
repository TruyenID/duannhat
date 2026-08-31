<?php

/**
 * KDS error catalog (RFC 7807 — Problem Details for HTTP APIs).
 *
 * Each entry maps a code (e.g. KDS_E001) to its public metadata:
 * - type: URI identifier (problem type) — frontend can match against
 * - title: short human-readable summary
 * - status: HTTP status code
 * - remediation: default suggested user action (overridable per-throw)
 *
 * Throw via App\Exceptions\KdsRuleViolation('KDS_E001', 'detail', $context, $remediation).
 */
return [
    'KDS_E001' => [
        'type' => 'https://godx-tempo.dev/errors/kds/order-finalized',
        'title' => 'Order is finalized',
        'status' => 409,
        'remediation' => 'Refresh dashboard để load lại trạng thái.',
    ],
    'KDS_E002' => [
        'type' => 'https://godx-tempo.dev/errors/kds/invalid-transition',
        'title' => 'Invalid status transition',
        'status' => 409,
        'remediation' => 'Refresh để xem trạng thái mới nhất của item.',
    ],
    'KDS_E003' => [
        'type' => 'https://godx-tempo.dev/errors/kds/too-soon',
        'title' => 'Anti-misclick — too soon after previous action',
        'status' => 409,
        'remediation' => 'Đợi 30 giây sau khi mark-ready trước khi mark-served.',
    ],
    'KDS_E004' => [
        'type' => 'https://godx-tempo.dev/errors/kds/toppings-not-ready',
        'title' => 'Toppings not ready',
        'status' => 409,
        'remediation' => 'Mark-ready các topping trước khi mark-ready item cha.',
    ],
    'KDS_E005' => [
        'type' => 'https://godx-tempo.dev/errors/kds/throttled',
        'title' => 'Throttle exceeded',
        'status' => 429,
        'remediation' => 'Quá nhanh — đợi 3 giây giữa các hành động trên cùng item.',
    ],
    'KDS_E006' => [
        'type' => 'https://godx-tempo.dev/errors/kds/branch-forbidden',
        'title' => 'Branch forbidden',
        'status' => 403,
        'remediation' => 'Item không thuộc branch của KDS device. Liên hệ admin.',
    ],
    'KDS_E007' => [
        'type' => 'https://godx-tempo.dev/errors/kds/item-not-found',
        'title' => 'Item not found',
        'status' => 404,
        'remediation' => 'Item có thể đã bị void hoặc xóa. Refresh dashboard.',
    ],
    'KDS_E008' => [
        'type' => 'https://godx-tempo.dev/errors/kds/idempotency-replay',
        'title' => 'Idempotency replay',
        'status' => 200,
        'remediation' => '',
    ],
    'DEFAULT' => [
        'type' => 'https://godx-tempo.dev/errors/kds/unknown',
        'title' => 'KDS error',
        'status' => 500,
        'remediation' => '',
    ],
];
