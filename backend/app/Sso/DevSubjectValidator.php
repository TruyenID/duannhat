<?php

declare(strict_types=1);

namespace App\Sso;

use App\Models\User;
use Dxs\Auth\Contracts\ValidatesDevelopmentSubjects;

/**
 * Authorizes `dev:<subject>` bearers minted by DevLoginController.
 *
 * Bound over the package default in AppServiceProvider::register() — see
 * #2037, where this class shipped implementing the contract but was never
 * wired, so the gate its docblock advertised was not the gate that ran.
 *
 * TWO independent ways to pass, and the OR is deliberate — do NOT "tidy" one
 * branch away:
 *
 *  1. **Subject list** — `sso.dev_bypass.subjects` (`SSO_DEV_BYPASS_SUBJECTS`).
 *     This is exactly what the package's ConfigDevelopmentSubjectValidator did,
 *     reproduced verbatim so that binding this class changes nothing for a
 *     machine already configured that way. Deleting it silently breaks every
 *     such setup; keeping it costs one in_array against an env-derived list.
 *  2. **Email allowlist** — `dev_login.emails` (`DEV_LOGIN_EMAILS`), reached by
 *     resolving the subject back to its user. This is the branch the class was
 *     written for: the subject list pins raw console-user uuids, which are
 *     reissued on every `migrate:fresh --seed`, so it breaks on each reseed
 *     while an email survives.
 *
 * Cheap branch first: (1) is pure config, (2) costs a query, and only the
 * uuid-shaped subjects that reach here at all can match either.
 *
 * Fail-closed at every layer. Branch 2 additionally requires
 * `dev_login.enabled`; the package gates BOTH branches on
 * `sso.dev_bypass.enabled` plus `app()->environment(local|testing)` before
 * `allows()` is ever called (Dxs\Auth\Http\Middleware\AuthenticateSso).
 */
final class DevSubjectValidator implements ValidatesDevelopmentSubjects
{
    public function allows(string $subject): bool
    {
        if (in_array($subject, (array) config('sso.dev_bypass.subjects', []), true)) {
            return true;
        }

        if (! config('dev_login.enabled', false)) {
            return false;
        }

        $email = User::query()
            ->where('console_user_id', $subject)
            ->value('email');

        return is_string($email)
            && in_array($email, (array) config('dev_login.emails', []), true);
    }
}
