<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ResolvesShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveShopFromSlug
{
    use ResolvesShopContext;

    public function handle(Request $request, Closure $next): Response
    {
        $shopSlug = $request->route('shopSlug');

        if (! $shopSlug) {
            abort(400, 'Shop slug is required.');
        }

        $this->bindShopContext($request, $shopSlug);

        // Drop the shopSlug route parameter so controller methods don't have
        // to declare `string $shopSlug` as the first argument — model binding
        // by parameter name still works for the remaining `{warehouse}` etc.
        $request->route()->forgetParameter('shopSlug');

        return $next($request);
    }
}
