import { redirect } from "next/navigation";

export default async function ShopPaymentsIndexPage({
  params,
}: {
  params: Promise<{ shopSlug: string }>;
}) {
  const { shopSlug } = await params;
  redirect(`/shop/${shopSlug}/settings/payments/ownership`);
}
