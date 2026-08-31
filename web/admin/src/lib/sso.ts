export function loginDestination(
  returnTo = "/select-context",
  organizationContextId?: string
): string {
  const query = new URLSearchParams({ return: returnTo });
  if (organizationContextId) {
    query.set("organization_context_id", organizationContextId);
  }

  return `/auth/redirect?${query.toString()}`;
}
