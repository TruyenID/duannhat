/**
 * Reads Laravel's `XSRF-TOKEN` cookie. The backend sets it (URL-encoded) on
 * every `web`-group response; Laravel then accepts its decoded value back as
 * the `X-XSRF-TOKEN` header in place of a form `_token` field.
 */
function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

export async function logout(): Promise<void> {
  try {
    const token = xsrfToken();
    await globalThis.fetch("/auth/logout", {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        // `POST /auth/logout` (dxs/laravel-auth SsoLogoutController) runs the
        // `web` middleware group, so CSRF verification applies. Without this
        // header Laravel answers 419 and the session is never destroyed — the
        // browser still redirected to /login, so the failure was silent and
        // SSO signed the user straight back in.
        ...(token ? { "X-XSRF-TOKEN": token } : {}),
      },
    });
  } finally {
    window.location.assign("/login");
  }
}
