"use client";

import { useState } from "react";
import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

/**
 * LOCAL-only SSO bypass affordance on the login page.
 *
 * Renders NOTHING in a production build — the `process.env.NODE_ENV` guard is
 * inlined by Next at build time, so the whole component is dead-code-eliminated
 * off `local` dev. Staging + production ship a build with NODE_ENV=production,
 * exactly matching the backend gate: the `dxs/laravel-auth` dev-bypass branch
 * (config `sso.dev_bypass.enabled` + `sso.dev_bypass.environments`) only fires
 * on local/testing. See backend/config/sso.php + AuthenticateSso middleware.
 *
 * This component does NOT call the backend — but a mint route does exist, so
 * do not read "no round-trip here" as "no such endpoint anywhere":
 * `POST /api/dev/test-login` (backend/routes/api.php → DevLoginController)
 * mints the same bearer from an email. It is triple-gated — the route is only
 * REGISTERED under `app()->environment(['local','testing'])` (production
 * builds do not have it at all), returns 404 unless `DEV_LOGIN=true`, and 403
 * unless the email is on the `config/dev_login.php` allowlist
 * (`DEV_LOGIN_EMAILS`). Nothing here is reachable in production.
 *
 * We skip it because the bearer is derivable client-side: AuthenticateSso
 * accepts a `dev:<subject>` bearer directly, where `subject` is the persona's
 * `console_user_id` and must be listed in `SSO_DEV_BYPASS_SUBJECTS` (config
 * `sso.dev_bypass.subjects`) — that check runs on EVERY authed request, so a
 * bearer minted or planted for an unlisted subject is refused regardless of
 * how it was obtained. So this component just plants that bearer as the
 * `token` cookie the same way the real OIDC callback does (src/lib/oidc.ts)
 * and navigates in — the middleware provisions the user off the seeded row on
 * the next authed request.
 */

/**
 * Allowlisted seeded personas — the `subject` MUST equal the seeded
 * `console_user_id` and MUST be present in `SSO_DEV_BYPASS_SUBJECTS` on the
 * backend (.env). Values below mirror ShopManagerUserSeeder + the Betoya/
 * Plan036 seeders.
 */
const DEV_PERSONAS = [
  {
    subject: "019e8a3b-8001-7a00-8001-000000000001",
    email: "admin@famgia.com",
    label: "HQ Admin (Famgia)",
  },
  {
    subject: "019e8a3b-8001-7a00-8001-000000000010",
    email: "shop-manager-sjk@famgia.com",
    label: "Shop Manager — SJK",
  },
  {
    subject: "019e8a3b-8001-7a00-8001-000000000011",
    email: "shop-staff-sjk@famgia.com",
    label: "Shop Staff — SJK",
  },
  {
    subject: "019eb5cf-5564-7a00-8002-000000000100",
    email: "sjk-manager@famgia.com",
    label: "Manager — SJK (新宿店)",
  },
] as const;

export interface DevLoginButtonProps {
  /** Where to send the user after a successful bypass login. */
  redirect?: string;
}

export function DevLoginButton({ redirect }: DevLoginButtonProps) {
  // Compiled out of production bundles — never renders off local dev.
  if (process.env.NODE_ENV === "production") {
    return null;
  }

  return <DevLoginButtonInner redirect={redirect} />;
}

function DevLoginButtonInner({ redirect }: DevLoginButtonProps) {
  const { t } = useTranslation();
  const [subject, setSubject] = useState<string>(DEV_PERSONAS[0].subject);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleDevLogin = () => {
    setLoading(true);
    setError(null);

    const persona = DEV_PERSONAS.find((p) => p.subject === subject);
    if (!persona) {
      setError(t("login.dev_error"));
      setLoading(false);
      return;
    }

    // The bearer AuthenticateSso's dev-bypass branch expects: `dev:<subject>`
    // where subject === seeded console_user_id. No backend round-trip needed.
    const token = `dev:${persona.subject}`;

    // Plant the `token` cookie the same way the OIDC callback does
    // (src/lib/oidc.ts) so apiFetch + the proxy.ts middleware pick it up.
    // Secure is honoured on http://localhost (a secure context in Chrome).
    document.cookie = `token=${token}; path=/; max-age=${60 * 60 * 24 * 7}; SameSite=Lax; Secure`;
    localStorage.setItem("token", token);
    localStorage.setItem(
      "user",
      JSON.stringify({ id: persona.subject, name: persona.label, email: persona.email })
    );

    window.location.href = redirect || "/select-context";
  };

  return (
    <div className="mt-4 border-t border-dashed pt-4">
      <p className="mb-2 text-center text-xs text-muted-foreground">
        {t("login.dev_hint")}
      </p>
      <select
        value={subject}
        onChange={(e) => setSubject(e.target.value)}
        disabled={loading}
        aria-label={t("login.dev_persona")}
        className="mb-2 h-9 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50"
      >
        {DEV_PERSONAS.map((p) => (
          <option key={p.subject} value={p.subject}>
            {p.label} — {p.email}
          </option>
        ))}
      </select>
      <Button
        type="button"
        variant="outline"
        onClick={handleDevLogin}
        disabled={loading}
        className="w-full"
      >
        {loading ? t("login.dev_loading") : t("login.dev_button")}
      </Button>
      {error ? (
        <p className="mt-2 text-center text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
