import { redirect } from "next/navigation";

export default async function HqSettingsPage({
  params,
}: {
  params: Promise<{ brandSlug: string }>;
}) {
  const { brandSlug } = await params;
  redirect(`/hq/${brandSlug}/settings/payments`);
}
