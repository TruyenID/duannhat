<?php

use App\Models\User;
use App\Sso\DevSubjectValidator;
use Dxs\Auth\Contracts\ValidatesDevelopmentSubjects;

/**
 * Resolve the way the MIDDLEWARE does — through the contract, never by class
 * name (#2037). `app(DevSubjectValidator::class)` builds that class whether or
 * not the container uses it for the contract, so the original spelling stayed
 * green for two years while every real request went through the package's
 * validator instead.
 */
function devSubjects(): ValidatesDevelopmentSubjects
{
    return app(ValidatesDevelopmentSubjects::class);
}

beforeEach(function () {
    config([
        'dev_login.enabled' => true,
        'dev_login.emails' => ['admin@famgia.com'],
        'sso.dev_bypass.subjects' => [],
    ]);

    $this->persona = User::factory()->create([
        'email' => 'admin@famgia.com',
        'console_user_id' => '019e8a3b-8001-7a00-8001-000000000001',
    ]);
});

// =========================================================================
//  POST /api/dev/test-login
// =========================================================================

it('mints a dev bearer for an allowlisted persona', function () {
    $this->postJson('/api/dev/test-login', ['email' => 'admin@famgia.com'])
        ->assertOk()
        ->assertJsonPath('data.token', 'dev:019e8a3b-8001-7a00-8001-000000000001')
        ->assertJsonPath('data.user.email', 'admin@famgia.com')
        ->assertJsonPath('data.user.console_user_id', '019e8a3b-8001-7a00-8001-000000000001');
});

it('refuses an email that is not on the allowlist', function () {
    User::factory()->create(['email' => 'intruder@example.com']);

    $this->postJson('/api/dev/test-login', ['email' => 'intruder@example.com'])
        ->assertForbidden();
});

it('404s when the dev-login gate is closed', function () {
    config(['dev_login.enabled' => false]);

    $this->postJson('/api/dev/test-login', ['email' => 'admin@famgia.com'])
        ->assertNotFound();
});

// =========================================================================
//  App\Sso\DevSubjectValidator — re-checked on every authenticated request
// =========================================================================

it('is the validator the container hands the SSO middleware', function () {
    // The load-bearing assertion of this file. Without it every case below
    // passes on a class the middleware never asks for: dxs/laravel-auth binds
    // ValidatesDevelopmentSubjects with `singletonIf`, so implementing the
    // contract is not the same as being wired to it (#2037).
    expect(devSubjects())->toBeInstanceOf(DevSubjectValidator::class);
});

it('authorizes the minted subject by email allowlist', function () {
    expect(devSubjects()->allows($this->persona->console_user_id))->toBeTrue();
});

it('denies a subject whose email left the allowlist', function () {
    config(['dev_login.emails' => ['someone-else@famgia.com']]);

    expect(devSubjects()->allows($this->persona->console_user_id))->toBeFalse();
});

it('denies every subject when the gate is closed', function () {
    config(['dev_login.enabled' => false]);

    expect(devSubjects()->allows($this->persona->console_user_id))->toBeFalse();
});

it('denies an unknown subject', function () {
    expect(devSubjects()->allows('00000000-0000-0000-0000-000000000999'))->toBeFalse();
});

// -------------------------------------------------------------------------
//  The OTHER branch: SSO_DEV_BYPASS_SUBJECTS, kept verbatim from the package
//  validator so binding ours cannot log anyone out (#2037).
// -------------------------------------------------------------------------

it('authorizes a subject listed in sso.dev_bypass.subjects', function () {
    config(['sso.dev_bypass.subjects' => ['019e8a3b-8001-7a00-8001-0000000000ff']]);

    expect(devSubjects()->allows('019e8a3b-8001-7a00-8001-0000000000ff'))->toBeTrue();
});

it('honours the subject list even with the dev-login gate closed', function () {
    // A machine running on SSO_DEV_BYPASS_SUBJECTS alone never set DEV_LOGIN.
    // Gating this branch on dev_login.enabled would have broken it.
    config([
        'dev_login.enabled' => false,
        'sso.dev_bypass.subjects' => ['019e8a3b-8001-7a00-8001-0000000000ff'],
    ]);

    expect(devSubjects()->allows('019e8a3b-8001-7a00-8001-0000000000ff'))->toBeTrue();
});

it('authorizes a listed subject that has no user row at all', function () {
    config(['sso.dev_bypass.subjects' => ['019e8a3b-8001-7a00-8001-0000000000ff']]);

    expect(User::query()->where('console_user_id', '019e8a3b-8001-7a00-8001-0000000000ff')->exists())
        ->toBeFalse();
    expect(devSubjects()->allows('019e8a3b-8001-7a00-8001-0000000000ff'))->toBeTrue();
});

it('denies a subject on neither list', function () {
    config(['sso.dev_bypass.subjects' => ['019e8a3b-8001-7a00-8001-0000000000ff']]);

    expect(devSubjects()->allows('00000000-0000-0000-0000-000000000999'))->toBeFalse();
});
