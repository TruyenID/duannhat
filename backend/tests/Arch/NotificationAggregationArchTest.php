<?php

/**
 * Plan-023 M5 T5.10 — arch guard for the inbox collapse query.
 *
 * Pre-M5 reviewers had to remember "scope by recipient_type +
 * recipient_id" every time someone touched the inbox query — easy
 * to forget under deadline. This test makes the boundary explicit:
 * InboxCollapseService MUST use forRecipient() (the pivot-keyed
 * scope on NotificationRecipient) and MUST NOT join across tenants
 * via organization_id-only filters.
 *
 * If the service drifts toward "every notification in the same org"
 * the build fails — telling reviewers to fix the boundary, not
 * argue about it.
 */

use App\Services\Notification\InboxCollapseService;

it('InboxCollapseService scopes via forRecipient (no raw org-only union)', function () {
    $source = file_get_contents((new ReflectionClass(InboxCollapseService::class))->getFileName());

    // Positive assertion — the scope must be present.
    expect($source)->toContain('forRecipient(');

    // Negative assertion — never cross-tenant by organization_id alone.
    // Allowed: org_id appears in audit log / brand-scoped queries elsewhere.
    // Forbidden here: a where('organization_id', ...) clause without
    // a forRecipient() in the same query builder chain.
    expect($source)->not->toMatch('/->where\(\s*[\'"]organization_id[\'"]/');
});
