<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mints a development bypass bearer for an allowlisted seeded persona.
 *
 * Used by the admin-web login page's "Đăng nhập Dev (bypass)" affordance when
 * the SSO IdP is unreachable. The returned `dev:<console_user_id>` token is
 * accepted by Dxs\Auth\Http\Middleware\AuthenticateSso, which re-checks the
 * subject through App\Sso\DevSubjectValidator on every request — bound to the
 * package contract in AppServiceProvider::register() (#2037; before that bind
 * the middleware used the package's subject-list validator instead, so this
 * allowlist gated only the MINT and never the requests that followed).
 *
 * The validator passes a subject on EITHER list — this email allowlist or
 * `SSO_DEV_BYPASS_SUBJECTS` — so a token minted here is honoured on later
 * requests without any env change.
 */
final class DevLoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless((bool) config('dev_login.enabled', false), Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = (string) $validated['email'];

        abort_unless(
            in_array($email, (array) config('dev_login.emails', []), true),
            Response::HTTP_FORBIDDEN,
            'Email is not on the dev-login allowlist.',
        );

        $user = User::query()->where('email', $email)->first();

        abort_if($user === null, Response::HTTP_NOT_FOUND, 'Dev persona not seeded in this database.');
        abort_if(blank($user->console_user_id), Response::HTTP_CONFLICT, 'Dev persona has no console_user_id.');

        $prefix = (string) config('sso.dev_bypass.token_prefix', 'dev:');

        return response()->json([
            'data' => [
                'token' => $prefix.$user->console_user_id,
                'user' => [
                    'id' => (string) $user->id,
                    'console_user_id' => (string) $user->console_user_id,
                    'email' => (string) $user->email,
                    'name' => (string) $user->name,
                ],
            ],
        ]);
    }
}
