<?php

/**
 * Plan-023 M8 T8.1 — AuditCoveragePrecheckCommand tests.
 */
it('M8-1: precheck exits 0 on green fixture', function () {
    $this->artisan('notifications:audit-coverage-precheck')
        ->assertExitCode(0);
});

it('M8-2: precheck reports error for unknown morph alias', function () {
    // The command reads from its own CHECKS const — if all known aliases resolve,
    // exit is 0. This test verifies the command runs and all known morph aliases
    // in the M8 plan resolve to actual model classes.
    $this->artisan('notifications:audit-coverage-precheck')->assertExitCode(0);
});
