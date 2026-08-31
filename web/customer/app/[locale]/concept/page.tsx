import Header from "@/components/Header";
import ConceptStory from "@/components/concept-story";
import Footer from "@/components/Footer";
import ScrollReveal from "@/components/scroll-reveal";

export const metadata = {
  title: "Sự tỉ mỉ · こだわり · Viet Origin / ベト屋",
};

export default function ConceptPage() {
  return (
    <>
	      <Header hideSwitcher showBack showLogo />
      <ConceptStory />
      <Footer />
      <ScrollReveal />
    </>
  );
}
