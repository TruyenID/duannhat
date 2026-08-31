<?php

namespace App\OpenApi\Standalone;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Standalone Auth',
    description: <<<'DESC'
    ## TempoFast — Standalone Authentication API

    Email/password authentication for the TempoFast service.

    ### Endpoints
    | Method | Endpoint | Description |
    |--------|----------|-------------|
    | POST | `/api/v1/auth/register` | Register a new account |
    | POST | `/api/v1/auth/login` | Login with email & password |
    | POST | `/api/v1/auth/logout` | Logout (revoke current token) |
    | GET | `/api/v1/auth/user` | Get current user profile |

    ### Authentication

    All protected endpoints require a Bearer token in the `Authorization` header:
    ```
    Authorization: Bearer {token}
    ```

    **How to get a token:**
    1. Call `POST /api/v1/auth/login` or `POST /api/v1/auth/register` with `device_name` parameter
    2. Use the returned `token` in subsequent requests

    **Session-based auth (web):**
    Omit `device_name` → a session cookie is set automatically (for SPA with same-origin).

    ### Token Lifecycle
    - Each `device_name` creates an independent token
    - `POST /api/v1/auth/logout` revokes only the **current** token (other devices stay logged in)
    - Tokens do not expire automatically (revoke explicitly on logout)

    ### Error Format
    ```json
    {
      "error": "ERROR_CODE",
      "message": "Human-readable description"
    }
    ```
    Validation errors (422):
    ```json
    {
      "message": "The email field is required.",
      "errors": { "email": ["The email field is required."] }
    }
    ```
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
    description: 'Sanctum Bearer token from login or register',
)]
#[OA\Tag(name: 'Auth — Standalone', description: 'Email/password authentication')]
#[OA\Tag(name: 'User Context', description: 'User profile, brands, shops, locale, timezone')]
class StandaloneApiInfo {}
