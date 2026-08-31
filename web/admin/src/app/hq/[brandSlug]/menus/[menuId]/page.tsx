import { redirect } from "next/navigation";

export default function MenuDetailPage({
  params,
}: {
  params: { brandSlug: string; menuId: string };
}) {
  redirect(`/hq/${params.brandSlug}/menus/${params.menuId}/items`);
}
