import Header from "@/components/Header";
import MenuStory from "@/components/menu-page";
import Footer from "@/components/Footer";
import ScrollReveal from "@/components/scroll-reveal";

export const metadata = {
  title: "Thực đơn · メニュー · Viet Origin / ベト屋",
};

// Skip static prerender — `MenuStory` reads BrandProvider context which is
// only mounted at runtime via the locale layout. Building this page
// statically throws "useBrand must be used inside BrandProvider".
export const dynamic = "force-dynamic";

export default function MenusPage() {
  return (
    <>
      <Header hideSwitcher showBack showLogo branchListHref="/select-branch" />
      <MenuStory />
      <Footer />
      <ScrollReveal />
    </>
  );
}
