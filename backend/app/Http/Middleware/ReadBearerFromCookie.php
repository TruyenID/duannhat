<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReadBearerFromCookie
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('sso.token_cookie', 'token');

        if (! $request->headers->has('Authorization') && $request->cookies->has($cookieName)) {
            $token = (string) $request->cookies->get($cookieName);
            if ($token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
