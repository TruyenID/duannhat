import { redirect } from "next/navigation";

export default function MenuSchedulesRedirect({
  params,
}: {
  params: { brandSlug: string; menuId: string };
}) {
  redirect(`/hq/${params.brandSlug}/menus/${params.menuId}/items`);
}
