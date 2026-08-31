"use client";

import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { DevLoginButton } from "./dev-login-button";

export interface LoginClientProps {
  /** The `/auth/redirect?...` URL that begins the real OIDC flow. */
  ssoDestination: string;
  /** Where to send the user after a dev-bypass login. */
  redirect?: string;
}

/**
 * LOCAL-only login chooser. Never ships to production — LoginPage only renders
 * this off `NODE_ENV !== "production"`; production redirects straight to SSO.
 *
 * Gives the developer two doors:
 *   1. Real SSO — full OIDC round-trip (needs a reachable IdP + org context).
 *   2. Dev bypass — mints a `dev:<console_user_id>` bearer for a seeded
 *      persona, skipping the IdP entirely (see dev-login-button.tsx).
 */
export function LoginClient({ ssoDestination, redirect }: LoginClientProps) {
  const { t } = useTranslation();

  return (
    <div className="flex min-h-dvh items-center justify-center bg-muted/30 p-4">
      <div className="w-full max-w-sm rounded-xl border bg-background p-6 shadow-sm">
        <h1 className="mb-1 text-center text-lg font-semibold">TempoFast</h1>
        <p className="mb-4 text-center text-sm text-muted-foreground">
          {t("login.dev_hint")}
        </p>

        <Button asChild className="w-full">
          <a href={ssoDestination}>SSO</a>
        </Button>

        <DevLoginButton redirect={redirect} />
      </div>
    </div>
  );
}
