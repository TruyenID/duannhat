import Header from "@/components/Header";
import MenuStory from "@/components/menu-page";
import Footer from "@/components/Footer";
import ScrollReveal from "@/components/scroll-reveal";

export const metadata = {
  title: "Thực đơn · メニュー · Viet Origin / ベト屋",
};

export default function MenusPage() {
  return (
    <>
	      <Header hideSwitcher showBack showLogo />
      <MenuStory />
      <Footer />
      <ScrollReveal />
    </>
  );
}
