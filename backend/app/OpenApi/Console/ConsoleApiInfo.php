<?php

namespace App\OpenApi\Console;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Console SSO',
    description: <<<'DESC'
    ## TempoFast — Console SSO Authentication API

    OAuth2 Single Sign-On integration with DXS Console (Identity Provider).

    ### SSO Flow

    ```
    Frontend                     TempoFast API            DXS Console (IDP)
    ────────                     ───────────────            ─────────────────
    1. User clicks "Login"
    2. Redirect to Console ─────────────────────────────→ /sso/authorize?
                                                           service_slug=dxs-product&
                                                           redirect_uri=...
    3.                                                    User authenticates
    4. Console redirects back ←──────────────────────── ?code=AUTH_CODE
    5. POST /api/v1/auth/sso/callback ──→
       { code: "AUTH_CODE" }        Exchange code ──────→ POST /api/sso/token
                                    Verify JWT          ← { access_token, refresh_token }
                                    Create/update user
                                    Sync orgs & roles
                                 ←── { user, organizations, token }
    6. Use token for API calls
    ```

    ### Endpoints

    | Method | Endpoint | Auth | Description |
    |--------|----------|------|-------------|
    | POST | `/api/v1/auth/sso/callback` | - | Exchange SSO code for tokens |
    | POST | `/api/v1/auth/sso/logout` | Bearer | Logout & revoke Console tokens |
    | GET | `/api/v1/auth/sso/user` | Bearer | Get user + organizations |
    | POST | `/api/v1/auth/webhook/cache-purge` | HMAC | Console webhook for cache invalidation |

    ### Authentication

    After SSO callback, include the Bearer token in all requests:
    ```
    Authorization: Bearer {token}
    ```

    **With `device_name`** in callback → returns API token (mobile/SPA).
    **Without `device_name`** → creates session cookie (same-origin web).

    ### Multi-Tenancy Headers

    API endpoints that operate within an organization context require:
    ```
    X-Organization-Id: {organization_slug}    # Required — which org to operate in
    X-Branch-Id: {branch_uuid}                # Optional — which branch (for branch-level permissions)
    ```

    Organizations and roles are synced automatically during SSO callback.

    ### Webhook Security

    The `cache-purge` webhook requires HMAC-SHA256 signature verification:
    ```
    X-Omnify-Signature: HMAC-SHA256(request_body, SSO_SERVICE_SECRET)
    ```

    ### Error Format
    ```json
    {
      "error": "ERROR_CODE",
      "message": "Human-readable description"
    }
    ```

    | Code | Error | Description |
    |------|-------|-------------|
    | 401 | `INVALID_CODE` | SSO code exchange failed |
    | 401 | `INVALID_TOKEN` | JWT verification failed |
    | 401 | `UNAUTHENTICATED` | No valid Bearer token |
    | 400 | `MISSING_ORGANIZATION` | X-Organization-Id header missing |
    | 403 | `ACCESS_DENIED` | No access to organization |
    | 403 | `INSUFFICIENT_ROLE` | Role level too low |
    | 403 | `PERMISSION_DENIED` | Missing required permission |
    DESC,
    contact: new OA\Contact(name: 'TempoFast'),
)]
#[OA\Server(
    url: 'http://localhost:5400',
    description: 'Local Development',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Token',
    description: 'Sanctum Bearer token from SSO callback',
)]
#[OA\Tag(name: 'Auth — SSO', description: 'Console SSO authentication (OAuth2 code exchange)')]
#[OA\Tag(name: 'Webhook', description: 'Console webhook endpoints (HMAC-signed)')]
#[OA\Tag(name: 'User Context', description: 'User profile, brands, shops, locale, timezone')]
class ConsoleApiInfo {}
