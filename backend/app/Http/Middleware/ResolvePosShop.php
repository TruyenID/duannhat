<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ResolvesShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active shop for the /api/v1/pos/* namespace from the
 * X-Shop-Slug request header instead of a URL segment.
 *
 * Rationale: POS terminals (pos-web) and workstation share the same URL
 * shape /api/v1/pos/<resource>; the workstation is paired to a single
 * branch (so the header is implicit), while pos-web sends the slug it
 * already has in its frontend route /shop/:shopSlug. Keeping shop context
 * out of the URL makes LAN-vs-Cloud failover a base-URL swap with zero
 * path rewriting.
 *
 * IAM check + attribute population are shared with ResolveShopFromSlug
 * via the ResolvesShopContext trait so the two paths can never drift.
 */
class ResolvePosShop
{
    use ResolvesShopContext;

    public function handle(Request $request, Closure $next): Response
    {
        $shopSlug = $request->header('X-Shop-Slug');

        if (! $shopSlug) {
            abort(400, 'X-Shop-Slug header is required.');
        }

        $this->bindShopContext($request, $shopSlug);

        return $next($request);
    }
}
