export interface MetadataBranch {
  slug: string;
  name: string;
  brand?: { name?: string | null } | null;
}

const copy = {
  ja: { title: (shop: string) => `${shop} – テイクアウトメニュー`, description: (shop: string) => `${shop}のテイクアウトメニュー` },
  en: { title: (shop: string) => `${shop} – Takeaway Menu`, description: (shop: string) => `Order takeaway from ${shop}` },
  vi: { title: (shop: string) => `${shop} – Menu mang về`, description: (shop: string) => `Đặt món mang về từ ${shop}` },
} as const;

export function buildTakeawayMetadata(locale: string, branch?: MetadataBranch, routeShop?: string) {
  const language = copy[locale as keyof typeof copy] ?? copy.en;
  const shopName = branch?.name || branch?.brand?.name || "Betoya";
  const shopSlug = routeShop;
  return {
    title: language.title(shopName),
    description: language.description(shopName),
    ...(shopSlug ? {
      alternates: {
        canonical: `/${locale}/takeaway/${shopSlug}`,
        languages: {
          ja: `/ja/takeaway/${shopSlug}`,
          en: `/en/takeaway/${shopSlug}`,
          vi: `/vi/takeaway/${shopSlug}`,
          "x-default": `/en/takeaway/${shopSlug}`,
        },
      },
    } : {}),
  };
}
