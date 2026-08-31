import { redirect } from "next/navigation";

export default async function SettingsOrderRedirectPage({
  params,
}: {
  params: Promise<{ shopSlug: string }>;
}) {
  const { shopSlug } = await params;
  redirect(`/shop/${shopSlug}/settings`);
}
