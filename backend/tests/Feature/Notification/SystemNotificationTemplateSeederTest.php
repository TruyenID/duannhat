<?php

/**
 * Idempotency + coverage test for SystemNotificationTemplateSeeder
 * (plan-012 T2.6).
 */

use App\Models\NotificationTemplate;
use Database\Seeders\SystemNotificationTemplateSeeder;

it('creates is_system=true rows for all Phase A + M8 template keys', function () {
    app(SystemNotificationTemplateSeeder::class)->run();

    $keys = NotificationTemplate::query()->pluck('key')->sort()->values()->all();
    expect($keys)->toBe([
        'brand.status_changed',
        'coupon.expired',
        'coupon.expiring_soon',
        'coupon.redeemed',
        'device.offline',
        'device.paired',
        'device.unpaired',
        'inventory.stock_drift',
        'material_lot.expiring',
        'material_lot.recall_affected',
        'menu.approved',
        'menu.rejected',
        'menu.submitted_for_approval',
        'order.status_changed',
        'payment.disputed',
        'payment.paypay_qr_unbookable',
        // plan-052 T2.3 (#1166) — print pipeline alerts.
        'print.jobs_need_attention',
        'print.money_document_failed',
        'print.printer_silent',
        'product.approved',
        'product.rejected',
        'product.submitted_for_approval',
        'recipe.approved',
        'recipe.rejected',
        'stock.alert.low',
        'stock.alert.out',
        'stock_transaction.approved',
        'stock_transaction.rejected',
        'stock_transaction.submitted',
        'stock_transfer.in_transit',
        'stock_transfer.received',
        'till.unresolved_orders',
        // #2737 — bản copy riêng cho ca "đã thu đủ, chỉ cần đóng đơn".
        'till.unresolved_orders.pending_close',
    ]);

    expect(NotificationTemplate::query()->where('is_system', true)->count())->toBe(33);
});

it('is idempotent — running twice does not duplicate rows', function () {
    app(SystemNotificationTemplateSeeder::class)->run();
    app(SystemNotificationTemplateSeeder::class)->run();

    expect(NotificationTemplate::query()->count())->toBe(33);
});

it('every seeded row has ja/en/vi content with title + body', function () {
    app(SystemNotificationTemplateSeeder::class)->run();

    foreach (NotificationTemplate::query()->get() as $tpl) {
        $content = (array) $tpl->content;
        foreach (['ja', 'en', 'vi'] as $locale) {
            expect($content[$locale] ?? null)->toHaveKeys(['title', 'body']);
        }
    }
});
