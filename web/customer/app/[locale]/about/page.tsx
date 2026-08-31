import Header from "@/components/Header";
import BrandStory from "@/components/brand-story";
import Footer from "@/components/Footer";
import ScrollReveal from "@/components/scroll-reveal";

export const metadata = {
  title: "Câu chuyện thương hiệu · Viet Origin / ベト屋",
};

export default function AboutPage() {
  return (
    <>
	      <Header hideSwitcher showBack showLogo />
      <BrandStory />
      <Footer />
      <ScrollReveal />
    </>
  );
}
