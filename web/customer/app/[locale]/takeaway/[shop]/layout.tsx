import type { Metadata } from "next";
import { API_BASE } from "@/lib/api";
import { buildTakeawayMetadata, type MetadataBranch } from "@/lib/takeaway-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; shop: string }>;
}): Promise<Metadata> {
  const { locale, shop } = await params;
  let branch: MetadataBranch | undefined;

  try {
    // Runs in generateMetadata on the server, where apiFetch's cookie-reading
    // auth does not apply, and Accept-Language is passed explicitly below. The
    // directive has to be the LAST comment line: eslint-disable-next-line means
    // literally the next line, so an explanation placed after it disables a
    // comment and leaves the call reported.
    // eslint-disable-next-line no-restricted-globals
    const response = await fetch(`${API_BASE}/api/v1/customer/branches`, {
      headers: { Accept: "application/json", "Accept-Language": locale },
      cache: "no-store",
    });
    if (response.ok) {
      const payload = (await response.json()) as { data?: MetadataBranch[] };
      branch = payload.data?.find((candidate) => candidate.slug === shop);
    }
  } catch {
    // Metadata must not make the customer route unavailable when the API is
    // temporarily unreachable; the client page still renders its retry state.
  }

  return buildTakeawayMetadata(locale, branch, shop);
}

export default function TakeawayShopLayout({ children }: { children: React.ReactNode }) {
  return children;
}
