<?php

declare(strict_types=1);

/**
 * Notification platform runtime config.
 *
 * Plan-012 introduced this file for the NOTIFICATION_USE_AUDIENCE rollout
 * flag (Decision 19). Plan-023 T1.3 removed that flag after the audience
 * engine became the unconditional emitter path — see plan-023 NOTES.md
 * §M1 corrections. The file shell is kept because later plan-023
 * milestones reuse it (M5 digest defaults, M7 NOTIFICATION_USE_RULES).
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Mail Webhook Driver
    |--------------------------------------------------------------------------
    |
    | Plan-023 M4 — provider driver for inbound bounce/complaint webhooks.
    | Resolves through App\Services\Notification\MailWebhookManager. Default
    | "postmark" is pre-registered; add others (ses, mailgun, sendgrid) by
    | calling MailWebhookManager::register($name, $factory) from a service
    | provider, then flipping this key.
    |
    */
    'mail_webhook_driver' => env('MAIL_WEBHOOK_DRIVER', 'postmark'),

    /*
    |--------------------------------------------------------------------------
    | Workflow Rule Engine
    |--------------------------------------------------------------------------
    |
    | Plan-023 M7 — when true, EvaluateRuleJob actually dispatches the
    | notification on a `matched` outcome. When false (default), matched
    | rules log a `shadow` firing without dispatching — the parallel-
    | shadow rollout used to validate rule-engine parity against the
    | hardcoded Phase A emitters before flipping production.
    |
    | Flip path:
    |   1. Seed system rules + leave them is_active=false.
    |   2. Run `php artisan notifications:rule-shadow-compare --since=14d`
    |      after activation — outcomes=`shadow` should match hardcoded
    |      emitter output.
    |   3. Flip to true; hardcoded emitters short-circuit (T7.13,
    |      deferred to a later commit).
    */
    'use_rules' => (bool) env('NOTIFICATION_USE_RULES', false),

    /*
    |--------------------------------------------------------------------------
    | Device Offline Detection
    |--------------------------------------------------------------------------
    |
    | Plan-023 M8 T8.4 — thresholds for DeviceOfflineDetectionJob.
    | threshold: minutes of silence before a device is considered offline.
    | cooldown:  minutes between repeat offline notifications for the same device.
    |
    */
    'device_offline_threshold_minutes' => (int) env('NOTIFICATION_DEVICE_OFFLINE_THRESHOLD_MINUTES', 15),
    'device_offline_cooldown_minutes' => (int) env('NOTIFICATION_DEVICE_OFFLINE_COOLDOWN_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Coupon Expiration Scanner
    |--------------------------------------------------------------------------
    |
    | Plan-023 M8 T8.5 — daily scan time for CouponExpirationScannerJob.
    | HH:MM format, Asia/Tokyo timezone.
    |
    */
    'coupon_scan_time' => env('NOTIFICATION_COUPON_SCAN_TIME', '08:00'),

    /*
    |--------------------------------------------------------------------------
    | Coverage catalogue exclusions
    |--------------------------------------------------------------------------
    |
    | Plan-023 M8 T8.13 — morph aliases to exclude from the "uncovered models"
    | list in the coverage catalogue. Pivot tables, audit-log models, and
    | notification-domain models should not appear as uncovered, because they
    | are intentionally never the primary trigger target of a system rule.
    |
    */
    'coverage' => [
        'excluded_models' => [
            // Pivot / junction tables
            'AllergenMaterial', 'CategoryProduct', 'CouponBranch',
            'MenuMenuSection', 'MenuProduct', 'MenuProductSku',
            'MenuPromotionCategory', 'MenuPromotionProduct',
            'MenuSection', 'OrderItemTopping',
            'PostCategory', 'PostPostTag',
            'ProductOption', 'ProductOptionValue',
            'ProductToppingGroupItemOverride',
            'ToppingGroupItem', 'ToppingGroupItemSku',
            // Notification-domain models (loop protection)
            'Notification', 'NotificationAudience', 'NotificationChannelRoute',
            'NotificationDelivery', 'NotificationDigestPreference',
            'NotificationEmailSuppression', 'NotificationPreference',
            'NotificationRecipient', 'NotificationRule', 'NotificationRuleFiring',
            'NotificationSchedule', 'NotificationTemplate',
            // Audit / internal models
            'AuditLog',
            // Stock sub-items
            'StockAlert', 'StockCount', 'StockCountItem', 'StockLevel',
            'StockMovement', 'StockTransactionItem', 'StockTransferItem',
            // Production sub-items
            'ProductionOrder', 'ProductionOrderItem',
            // Other non-primary models
            'BranchScheduleOverride', 'CustomerOrderItem', 'DisposalRecord',
            'ExpiryAlert', 'File', 'GenealogyLink', 'MaterialBatch',
            'MaterialBatchItem', 'MaterialLotReservation',
            'MaterialSubstitutionRule', 'MaterialUnit',
            'MenuSchedule', 'MenuPromotion',
            'OrderPayment', 'PaymentMethod',
            'ProductSku', 'ProductType',
            'RecallAffectedOrder', 'RecallDrill',
            'ShopOrderSetting', 'TableStatusChange',
            'VariantUnit', 'WarehouseMember',
        ],
    ],
];
