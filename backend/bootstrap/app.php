<?php

use App\Exceptions\AllergenInUseException;
use App\Exceptions\CircularReferenceException;
use App\Exceptions\ComponentInUseException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\KdsRuleViolation;
use App\Exceptions\MenuOperationException;
use App\Exceptions\OptionInUseException;
use App\Exceptions\OptionValueInUseException;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthenticateSsoOrDevice;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\ReadBearerFromCookie;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use App\Models\Product;
use Dxs\Auth\Http\Middleware\AuthenticateSso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Explicit route model bindings for params used in nested route groups
            Route::model('product', Product::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust upstream proxy headers so `$request->isSecure()` reports
        // true when TLS terminates upstream (cloudflared, Caddy reverse
        // proxy). Without this Set-Cookie downgrades SameSite=None+Secure
        // → Lax, breaking cross-origin tunnel deployments.
        //
        // #2453 — danh sách proxy nằm ở `config/trustedproxy.php`, và CỐ Ý
        // không gọi `$middleware->trustProxies(at: ...)` ở đây: closure này
        // chạy TRƯỚC khi config được nạp, nên `config()` tại đây ném
        // BindingResolutionException. Không truyền gì thì `TrustProxies` tự đọc
        // `config('trustedproxy.proxies')` lúc có request — đúng đường framework
        // chừa sẵn cho danh sách động.
        //
        // Đừng đổi lại thành `at: '*'`: `'*'` chỉ tin `REMOTE_ADDR` (peer trực
        // tiếp), nên sau một tầng CDN `$request->ip()` trả IP edge chứ không
        // phải người gọi — đo được trên production, và nó làm mọi xác thực bằng
        // IP allowlist vô nghĩa. Lý do chọn từng dải, và vì sao KHÔNG dùng
        // `0.0.0.0/0`: đọc docblock của `config/trustedproxy.php`.

        // The cookie contains the JWT itself; leave it opaque-but-unencrypted
        // so the API middleware can promote it back to a Bearer header.
        // It remains HttpOnly and Secure, so browser JavaScript cannot read it.
        $middleware->encryptCookies(except: ['token']);

        $middleware->prepend(HandleCors::class);

        $middleware->api(append: [
            SetLocale::class,
            SetTimezone::class,
        ]);

        // Platform access tokens live only in an HttpOnly browser cookie.
        // Promote it before the package validates the bearer.
        $middleware->api(prepend: [EnsureRequestId::class, ReadBearerFromCookie::class]);

        $middleware->alias([
            'device.auth' => AuthenticateDevice::class,
            'auth.sso_or_device' => AuthenticateSsoOrDevice::class,
        ]);

        // Run device.auth BEFORE ThrottleRequests (and therefore before
        // SubstituteBindings, which sits even later). Two reasons:
        //   1. Rate-limit keying: the device-scoped named limiters
        //      (`workstation`, `kiosk-audit`, …) read the authenticated device
        //      from `$request->attributes`. If ThrottleRequests ran first that
        //      attribute is still null, so the limiter falls back to client IP
        //      and every workstation/kiosk behind one branch NAT shares a single
        //      bucket — 429-ing the sync pull/push loop. Same failure mode the
        //      SSO prepend below fixes for user-scoped throttles.
        //   2. Device type check: a KDS token hitting a /workstation/... route
        //      must 403 (type mismatch) before SubstituteBindings turns it into a
        //      404 (model-binding failure on a non-existent id).
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AuthenticateDevice::class,
        );

        // POS compound auth (SSO OR device token) has the same rate-limit
        // keying requirement as AuthenticateDevice: the 'pos' named limiter
        // reads $request->attributes->get('device'), which must be populated
        // BEFORE ThrottleRequests fires or every device NAT'd behind one
        // branch IP shares a single bucket.
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AuthenticateSsoOrDevice::class,
        );

        // Resolve Platform users before per-user rate limiting.
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AuthenticateSso::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain exceptions that render to a meaningful 4xx response are
        // expected business outcomes (illegal status transitions, in-use
        // guards, etc.) — they shouldn't pollute the error log.
        $exceptions->dontReport([
            AllergenInUseException::class,
            CircularReferenceException::class,
            ComponentInUseException::class,
            InsufficientStockException::class,
            InvalidStatusTransitionException::class,
            // KDS business-rule violations render as structured RFC 7807
            // Problem Details — expected outcomes, not internal errors.
            KdsRuleViolation::class,
            MenuOperationException::class,
            OptionInUseException::class,
            OptionValueInUseException::class,
            // Service-layer business-rule violations (e.g. "cannot approve
            // own product", "must have at least one SKU before submitting",
            // "user already a member") render as 4xx via Laravel's default
            // handler — they're contract failures, not internal errors.
            InvalidArgumentException::class,
        ]);
    })->create();
