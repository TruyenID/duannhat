import { redirect } from "next/navigation";
import { loginDestination } from "@/lib/sso";
import { LoginClient } from "./components/login-client";

interface LoginPageProps {
  searchParams: Promise<{
    redirect?: string | string[];
    organization_context_id?: string | string[];
  }>;
}

function first(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const query = await searchParams;
  const returnTo = first(query.redirect) || "/select-context";
  const organizationContextId = first(query.organization_context_id);

  const ssoDestination = loginDestination(returnTo, organizationContextId);

  // Production/staging: no bypass exists — go straight to SSO as before.
  // The `process.env.NODE_ENV` check is inlined at build time, so this whole
  // branch (and the LoginClient/DevLoginButton it pulls in) is dead-code-
  // eliminated out of production bundles.
  if (process.env.NODE_ENV === "production") {
    redirect(ssoDestination);
  }

  // Local dev: render a chooser so the developer can either kick off real SSO
  // or use the dev-bypass persona (see components/dev-login-button.tsx).
  return <LoginClient ssoDestination={ssoDestination} redirect={returnTo} />;
}
