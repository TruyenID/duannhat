<?php

use App\Http\Controllers\Api\V1\HQ\NotificationAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationAudienceAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationBroadcastController;
use App\Http\Controllers\Api\V1\HQ\NotificationChannelRouteAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationCoverageController;
use App\Http\Controllers\Api\V1\HQ\NotificationEmailSuppressionAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationRuleAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationRuleFieldOptionsController;
use App\Http\Controllers\Api\V1\HQ\NotificationScheduleAdminController;
use App\Http\Controllers\Api\V1\HQ\NotificationTemplateAdminController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ - Notifications Audit (plan-008, Phase A read-only)
//
//  Throttle policy (issue #171) — three named limiters, all org-scoped so a
//  compromised admin token cannot starve queue capacity for other tenants:
//
//    notifications-broadcast        → 30/min/org   (fans out to N×M jobs)
//    notifications-admin-mutations  → 60/min/org   (CRUD on templates/etc)
//    notifications-admin-reads      → 120/min/org  (preview/render/lookup)
//
//  Read-only list / show endpoints inherit the global API throttle that
//  wraps the parent group, so they don't need an explicit cap here.
// =========================================================================

// `types` must come before the `{id}` route so it doesn't get swallowed.
Route::get('notifications/types', [NotificationAdminController::class, 'types'])
    ->name('api.v1.hq.notifications.types');

// `stats` likewise must precede the `{id}` route (TC-NOTIF-ROOT04).
Route::get('notifications/stats', [NotificationAdminController::class, 'stats'])
    ->name('api.v1.hq.notifications.stats');

// Static audience routes MUST come before `notifications/{id}` so the
// `audiences` / `audiences/preview` paths don't get swallowed by the
// uuid-constrained detail route.
Route::prefix('notifications/audiences')->name('api.v1.hq.notifications.audiences.')->group(function () {
    // Preview is the heaviest read on this surface (resolver scans members),
    // so it keeps a tighter cap than the rest of the admin reads.
    Route::post('preview', [NotificationAudienceAdminController::class, 'preview'])
        ->middleware('throttle:10,1')
        ->name('preview');
    Route::get('/', [NotificationAudienceAdminController::class, 'index'])->name('index');
    Route::post('/', [NotificationAudienceAdminController::class, 'store'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('store');
    Route::get('{id}', [NotificationAudienceAdminController::class, 'show'])->whereUuid('id')->name('show');
    Route::patch('{id}', [NotificationAudienceAdminController::class, 'update'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('update');
    Route::delete('{id}', [NotificationAudienceAdminController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
});

Route::post('notifications/broadcast', [NotificationBroadcastController::class, 'store'])
    ->middleware('throttle:notifications-broadcast')
    ->name('api.v1.hq.notifications.broadcast');

// plan-023 M3 T3.5 — recurring schedule CRUD + pause/resume/preview-rrule.
// Static routes (`preview-rrule`) MUST come before the `{id}` route so
// the uuid-constrained detail path doesn't swallow them.
Route::prefix('notifications/schedules')->name('api.v1.hq.notifications.schedules.')->group(function () {
    Route::post('preview-rrule', [NotificationScheduleAdminController::class, 'previewRrule'])
        ->middleware('throttle:notifications-admin-reads')
        ->name('preview-rrule');
    Route::get('/', [NotificationScheduleAdminController::class, 'index'])->name('index');
    Route::post('/', [NotificationScheduleAdminController::class, 'store'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('store');
    Route::get('{id}', [NotificationScheduleAdminController::class, 'show'])->whereUuid('id')->name('show');
    Route::patch('{id}', [NotificationScheduleAdminController::class, 'update'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('update');
    Route::delete('{id}', [NotificationScheduleAdminController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
    Route::post('{id}/pause', [NotificationScheduleAdminController::class, 'pause'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('pause');
    Route::post('{id}/resume', [NotificationScheduleAdminController::class, 'resume'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('resume');
});

// plan-023 M4 T4.8 — email suppression admin: list, manual block,
// un-suppress (writes un_suppressed_at; row retained for audit).
Route::prefix('notifications/email-suppressions')->name('api.v1.hq.notifications.email-suppressions.')->group(function () {
    Route::get('/', [NotificationEmailSuppressionAdminController::class, 'index'])->name('index');
    Route::post('/', [NotificationEmailSuppressionAdminController::class, 'store'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('store');
    Route::delete('{id}', [NotificationEmailSuppressionAdminController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
});

// Aggregate metrics for the email-health dashboard (sent / delivered /
// bounced / spam over a date window). Shares the suppression controller
// since the data it returns is half-deliveries, half-suppressions.
Route::get('notifications/email-health/metrics', [NotificationEmailSuppressionAdminController::class, 'metrics'])
    ->middleware('throttle:notifications-admin-reads')
    ->name('api.v1.hq.notifications.email-health.metrics');

// Per-day time-series for the deliverability chart.
Route::get('notifications/email-health/metrics/timeseries', [NotificationEmailSuppressionAdminController::class, 'timeseries'])
    ->middleware('throttle:notifications-admin-reads')
    ->name('api.v1.hq.notifications.email-health.metrics.timeseries');

Route::prefix('notifications/channel-routes')->name('api.v1.hq.notifications.channel-routes.')->group(function () {
    Route::get('/', [NotificationChannelRouteAdminController::class, 'index'])->name('index');
    Route::put('/', [NotificationChannelRouteAdminController::class, 'upsert'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('upsert');
    Route::delete('{type}', [NotificationChannelRouteAdminController::class, 'destroy'])
        ->where('type', '[a-zA-Z0-9._-]+')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
});

Route::prefix('notifications/templates')->name('api.v1.hq.notifications.templates.')->group(function () {
    Route::post('render', [NotificationTemplateAdminController::class, 'render'])
        ->middleware('throttle:notifications-admin-reads')
        ->name('render');
    Route::get('/', [NotificationTemplateAdminController::class, 'index'])->name('index');
    Route::post('/', [NotificationTemplateAdminController::class, 'store'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('store');
    Route::post('{id}/duplicate', [NotificationTemplateAdminController::class, 'duplicate'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('duplicate');
    Route::patch('{id}', [NotificationTemplateAdminController::class, 'update'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('update');
    Route::delete('{id}', [NotificationTemplateAdminController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
});

Route::get('notifications', [NotificationAdminController::class, 'index'])
    ->name('api.v1.hq.notifications.index');
Route::get('notifications/{id}', [NotificationAdminController::class, 'show'])
    ->name('api.v1.hq.notifications.show')
    ->whereUuid('id');
Route::delete('notifications/{id}', [NotificationAdminController::class, 'destroy'])
    ->name('api.v1.hq.notifications.destroy')
    ->whereUuid('id')
    ->middleware('throttle:notifications-admin-mutations');

// plan-023 M7 T7.9 — rule editor FieldPicker introspection.
Route::get('notifications/rules/field-options', NotificationRuleFieldOptionsController::class)
    ->middleware('throttle:notifications-admin-reads')
    ->name('api.v1.hq.notifications.rules.field-options');

// plan-023 M7 T7.5 — workflow rule CRUD + dry-run.
Route::prefix('notifications/rules')->name('api.v1.hq.notifications.rules.')->group(function () {
    Route::get('/', [NotificationRuleAdminController::class, 'index'])->name('index');
    Route::post('/', [NotificationRuleAdminController::class, 'store'])
        ->middleware('throttle:notifications-admin-mutations')
        ->name('store');
    Route::get('{id}', [NotificationRuleAdminController::class, 'show'])
        ->whereUuid('id')->name('show');
    Route::patch('{id}', [NotificationRuleAdminController::class, 'update'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('update');
    Route::delete('{id}', [NotificationRuleAdminController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('destroy');
    Route::post('{id}/dry-run', [NotificationRuleAdminController::class, 'dryRun'])
        ->whereUuid('id')
        ->middleware('throttle:notifications-admin-reads')
        ->name('dry-run');
    Route::get('{id}/firings', [NotificationRuleAdminController::class, 'firings'])
        ->whereUuid('id')
        ->name('firings');
    Route::post('{id}/firings/{firing}/retry', [NotificationRuleAdminController::class, 'retryFiring'])
        ->whereUuid('id')
        ->whereUuid('firing')
        ->middleware('throttle:notifications-admin-mutations')
        ->name('firings.retry');
});

// Plan-023 M8 T8.13 — read-only coverage catalogue.
Route::get('notifications/coverage', [NotificationCoverageController::class, 'index'])
    ->middleware('throttle:notifications-admin-reads')
    ->name('api.v1.hq.notifications.coverage');
