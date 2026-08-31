import { apiFetch } from "@/lib/api";

interface Brand {
  slug: string;
}

interface BrandPage {
  data: Brand[];
}

async function findAccessibleBrand(search?: string): Promise<Brand | null> {
  const query = new URLSearchParams({ per_page: search ? "100" : "1" });
  if (search) query.set("search", search);

  const response = await apiFetch<BrandPage>(`/api/v1/me/brands?${query}`);

  if (search) {
    return response.data.find((brand) => brand.slug === search) ?? null;
  }

  return response.data[0] ?? null;
}

export async function resolveHqBrandSlug(preferredSlug: string | null): Promise<string | null> {
  if (preferredSlug) {
    const preferredBrand = await findAccessibleBrand(preferredSlug);
    if (preferredBrand) return preferredBrand.slug;
  }

  return (await findAccessibleBrand())?.slug ?? null;
}
