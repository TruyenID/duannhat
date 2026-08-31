import { redirect } from "next/navigation";

/**
 * #1005 — the former sessions list page (Lịch sử ca + Ca treo tabs) was
 * consolidated into the /till dashboard as tabs. This route now only exists to
 * keep old bookmarks / deep links alive by redirecting to the right /till tab:
 *
 *   /till/sessions                     → /till?tab=history
 *   /till/sessions?tab=all             → /till?tab=history
 *   /till/sessions?tab=stale           → /till?tab=stale
 *   /till/sessions?filter=open_overdue → /till?tab=stale&filter=open_overdue
 *
 * The canonical session DETAIL route /till/sessions/[id] is unaffected.
 */
export default async function TillSessionsRedirect({
  params,
  searchParams,
}: {
  params: Promise<{ shopSlug: string }>;
  searchParams: Promise<{ tab?: string; filter?: string }>;
}) {
  const { shopSlug } = await params;
  const { tab, filter } = await searchParams;

  const goStale = tab === "stale" || Boolean(filter);
  const q = new URLSearchParams({ tab: goStale ? "stale" : "history" });
  if (goStale && filter) q.set("filter", filter);

  redirect(`/shop/${shopSlug}/till?${q.toString()}`);
}
