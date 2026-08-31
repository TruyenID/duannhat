import { redirect } from "next/navigation";

export default async function LegacyPaymentMethodEditRedirect({
  params,
}: {
  params: Promise<{ brandSlug: string; id: string }>;
}) {
  const { brandSlug } = await params;
  redirect(`/hq/${brandSlug}/settings/payments/methods`);
}
