import { redirect } from "next/navigation";

export default async function ShopMenuSchedulesRedirect({
  params,
}: {
  params: Promise<{ shopSlug: string; menuId: string }>;
}) {
  const { shopSlug, menuId } = await params;

  redirect(`/shop/${shopSlug}/menus/${menuId}`);
}
